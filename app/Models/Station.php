<?php

namespace App\Models;

use App\Enums\SubmissionStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'slug', 'name', 'description', 'lat', 'lng', 'reference_path',
    'output_width', 'output_height', 'crop_margin', 'min_inliers', 'is_active',
])]
class Station extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'lat' => 'decimal:6',
            'lng' => 'decimal:6',
            'is_active' => 'boolean',
            'needs_render' => 'boolean',
            'output_width' => 'integer',
            'output_height' => 'integer',
            'min_inliers' => 'integer',
            'crop_margin' => 'float',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(Submission::class);
    }

    public function publishedSubmissions(): HasMany
    {
        return $this->submissions()
            ->where('status', SubmissionStatus::Published)
            ->orderByRaw('coalesce(taken_at, created_at)');
    }

    public function series(): HasMany
    {
        return $this->hasMany(Series::class);
    }

    public function latestSeries(): HasOne
    {
        return $this->hasOne(Series::class)->ofMany('rendered_at');
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function uploadUrl(): string
    {
        return route('upload.show', $this);
    }

    /**
     * Sicherheitsrand als Anteil je Seite (4 % => 0.04).
     */
    public function cropMarginFraction(): float
    {
        return max(0.0, min(20.0, (float) $this->crop_margin)) / 100;
    }

    public function isReady(): bool
    {
        return $this->is_active && filled($this->reference_path);
    }
}
