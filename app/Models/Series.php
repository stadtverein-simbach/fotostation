<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Storage;

#[Table('series')]
#[Fillable([
    'station_id', 'type', 'period_from', 'period_to', 'video_path',
    'frame_count', 'duration_seconds', 'license', 'rendered_at',
])]
class Series extends Model
{
    protected function casts(): array
    {
        return [
            'period_from' => 'datetime',
            'period_to' => 'datetime',
            'rendered_at' => 'datetime',
            'frame_count' => 'integer',
            'duration_seconds' => 'float',
        ];
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    public function frames(): BelongsToMany
    {
        return $this->belongsToMany(Submission::class, 'series_frames')
            ->withPivot('position')
            ->orderByPivot('position');
    }

    public function videoUrl(): ?string
    {
        return $this->video_path
            ? Storage::disk(config('fotostation.disks.public'))->url($this->video_path)
            : null;
    }
}
