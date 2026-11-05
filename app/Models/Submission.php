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
    'station_id', 'status', 'original_path', 'review_thumb_path', 'aligned_path',
    'public_path', 'thumb_path', 'taken_at', 'width', 'height', 'display_name',
    'consent_version_id', 'consent_at', 'ip_hash', 'sha256', 'phash',
    'match_inliers', 'match_score', 'homography', 'face_boxes', 'manual_boxes',
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
            'homography' => 'array',
            'face_boxes' => 'array',
            'manual_boxes' => 'array',
            'match_inliers' => 'integer',
            'match_score' => 'float',
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

    /*
    |--------------------------------------------------------------------------
    | Dateien
    |--------------------------------------------------------------------------
    */

    public function pathFor(string $kind): string
    {
        $station = $this->station_id;

        return match ($kind) {
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
     * Löscht die privaten Arbeitsdateien (Original, Vorschau, ausgerichtet).
     */
    public function deletePrivateFiles(): void
    {
        $paths = array_filter([$this->original_path, $this->review_thumb_path, $this->aligned_path]);

        if ($paths !== []) {
            Storage::disk(config('fotostation.disks.private'))->delete($paths);
        }

        $this->forceFill([
            'original_path' => null,
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
