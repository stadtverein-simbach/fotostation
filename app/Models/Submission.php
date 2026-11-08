<?php

namespace App\Models;

use App\Enums\SubmissionStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

#[Fillable([
    'station_id', 'status', 'source_path', 'original_path', 'review_thumb_path', 'aligned_path',
    'public_path', 'thumb_path', 'taken_at', 'width', 'height', 'exif', 'focal_length_35mm', 'gps_distance', 'display_name',
    'consent_version_id', 'consent_at', 'ip_hash', 'sha256', 'phash',
    'match_inliers', 'match_score', 'homography', 'coverage', 'face_boxes', 'manual_boxes',
    'rejection_reason', 'error', 'reviewed_by', 'reviewed_at', 'published_at',
])]
#[Hidden(['ip_hash'])]
class Submission extends Model
{
    use HasFactory, HasUlids, Prunable;

    protected $attributes = [
        'status' => 'received',
    ];

    protected function casts(): array
    {
        return [
            'status' => SubmissionStatus::class,
            'taken_at' => 'datetime',
            'consent_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'published_at' => 'datetime',
            'exif' => 'array',
            'focal_length_35mm' => 'integer',
            'gps_distance' => 'integer',
            'homography' => 'array',
            'face_boxes' => 'array',
            'manual_boxes' => 'array',
            'match_inliers' => 'integer',
            'match_score' => 'float',
            'coverage' => 'float',
        ];
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    public function consentVersion(): BelongsTo
    {
        return $this->belongsTo(ConsentVersion::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }

    public function scopePublished(Builder $query): void
    {
        $query->where('status', SubmissionStatus::Published);
    }

    public function transitionTo(SubmissionStatus $status, array $attributes = []): void
    {
        $this->forceFill(['status' => $status, ...$attributes])->save();
    }

    /**
     * Zu viel Rand musste aus dem Referenzbild ergänzt werden.
     */
    public function hasLowCoverage(): bool
    {
        return $this->coverage !== null
            && $this->coverage < (float) config('fotostation.alignment.min_coverage');
    }

    /**
     * Laut GPS im Foto an der Station aufgenommen (null: keine Position im
     * Foto oder Station ohne Koordinaten).
     */
    public function takenNearStation(): ?bool
    {
        return $this->gps_distance === null
            ? null
            : $this->gps_distance <= (int) config('fotostation.upload.gps_near_distance');
    }

    /**
     * Objektiv laut Brennweite: 'ultrawide', 'main', 'tele' oder null.
     */
    public function lensType(): ?string
    {
        if ($this->focal_length_35mm === null) {
            return null;
        }

        [$min, $max] = config('fotostation.upload.main_focal_length');

        return match (true) {
            $this->focal_length_35mm < $min => 'ultrawide',
            $this->focal_length_35mm > $max => 'tele',
            default => 'main',
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Dateien
    |--------------------------------------------------------------------------
    */

    public function pathFor(string $kind, string $extension = 'jpg'): string
    {
        $station = $this->station_id;

        return match ($kind) {
            'source' => "sources/{$station}/{$this->id}.{$extension}",
            'original' => "originals/{$station}/{$this->id}.jpg",
            'review_thumb' => "review-thumbs/{$station}/{$this->id}.webp",
            'aligned' => "aligned/{$station}/{$this->id}.jpg",
            'public' => "{$station}/{$this->id}.jpg",
            'thumb' => "{$station}/thumbs/{$this->id}.webp",
        };
    }

    public function publicUrl(): ?string
    {
        return $this->public_path
            ? Storage::disk(config('fotostation.disks.public'))->url($this->public_path)
            : null;
    }

    public function thumbUrl(): ?string
    {
        return $this->thumb_path
            ? Storage::disk(config('fotostation.disks.public'))->url($this->thumb_path)
            : null;
    }

    /**
     * Löscht alle privaten Dateien (Original, Arbeitsfassung, Vorschau,
     * ausgerichtet). Nur zusammen mit der Einreichung (Ablehnung, Entfernen,
     * Löschfristen).
     */
    public function deletePrivateFiles(): void
    {
        $paths = array_filter([$this->source_path, $this->original_path, $this->review_thumb_path, $this->aligned_path]);

        if ($paths !== []) {
            Storage::disk(config('fotostation.disks.private'))->delete($paths);
        }

        $this->forceFill([
            'source_path' => null,
            'original_path' => null,
            'review_thumb_path' => null,
            'aligned_path' => null,
        ])->saveQuietly();
    }

    /**
     * Nach der Veröffentlichung: Vorschau und ausgerichtete Fassung löschen.
     * Das Original (source_path) und die Arbeitsfassung (original_path)
     * bleiben erhalten, solange die Einreichung existiert.
     */
    public function deleteWorkingFiles(): void
    {
        $paths = array_filter([$this->review_thumb_path, $this->aligned_path]);

        if ($paths !== []) {
            Storage::disk(config('fotostation.disks.private'))->delete($paths);
        }

        $this->forceFill([
            'review_thumb_path' => null,
            'aligned_path' => null,
        ])->saveQuietly();
    }

    /**
     * Löscht die veröffentlichten Dateien (Bild, Thumbnail).
     */
    public function deletePublicFiles(): void
    {
        $paths = array_filter([$this->public_path, $this->thumb_path]);

        if ($paths !== []) {
            Storage::disk(config('fotostation.disks.public'))->delete($paths);
        }

        $this->forceFill([
            'public_path' => null,
            'thumb_path' => null,
        ])->saveQuietly();
    }

    public function deleteAllFiles(): void
    {
        $this->deletePrivateFiles();
        $this->deletePublicFiles();
    }

    /*
    |--------------------------------------------------------------------------
    | Löschfristen (php artisan model:prune, nächtlich)
    |--------------------------------------------------------------------------
    */

    public function prunable(): Builder
    {
        $retention = config('fotostation.retention');

        return static::query()->where(function (Builder $query) use ($retention): void {
            $query
                ->where(fn (Builder $q) => $q
                    ->where('status', SubmissionStatus::AutoRejected)
                    ->where('updated_at', '<=', now()->subDays($retention['auto_rejected_days'])))
                ->orWhere(fn (Builder $q) => $q
                    ->where('status', SubmissionStatus::Rejected))
                ->orWhere(fn (Builder $q) => $q
                    ->where('status', SubmissionStatus::Failed)
                    ->where('updated_at', '<=', now()->subDays($retention['failed_days'])));
        });
    }

    protected function pruning(): void
    {
        $this->deleteAllFiles();
    }
}
