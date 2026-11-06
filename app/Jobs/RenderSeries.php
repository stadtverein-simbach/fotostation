<?php

namespace App\Jobs;

use App\Models\Series;
use App\Models\Station;
use App\Services\CvWorker;
use App\Services\PhotoStorage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Rendert den Zeitraffer einer Station neu.
 *
 * Bewusst kein Queue-Job: Bei vielen Fotos dauert das Rendern länger als
 * die 90 Sekunden, die Flex-Worker der Laravel-Cloud-Managed-Queues einem
 * Job erlauben. Es läuft deshalb synchron im nächtlichen Befehl
 * `fotostation:render-changed` (Scheduler) oder von Hand über „Commands“.
 */
class RenderSeries
{
    public function __construct(public Station $station) {}

    public function handle(CvWorker $worker, PhotoStorage $storage): void
    {
        $station = $this->station->refresh();

        $frames = $station->publishedSubmissions()
            ->whereNotNull('public_path')
            ->get(['id', 'public_path', 'taken_at', 'created_at']);

        // Sehr lange Reihen gleichmäßig ausdünnen.
        $max = (int) config('fotostation.render.max_frames');
        if ($frames->count() > $max) {
            $step = $frames->count() / $max;
            $frames = collect(range(0, $max - 1))->map(fn (int $i) => $frames[(int) floor($i * $step)]);
        }

        $previous = $station->series()->get();

        if ($frames->count() < 2) {
            $this->removeSeries($previous, $storage);
            $station->forceFill(['needs_render' => false])->save();

            return;
        }

        $series = $station->series()->create([
            'type' => 'timelapse',
            'license' => config('fotostation.operator.series_license'),
            'period_from' => $frames->first()->taken_at ?? $frames->first()->created_at,
            'period_to' => $frames->last()->taken_at ?? $frames->last()->created_at,
        ]);

        $videoPath = "{$station->id}/series/{$series->id}.mp4";

        try {
            $result = $worker->render(
                framePaths: $frames->pluck('public_path')->all(),
                fps: (int) config('fotostation.render.fps'),
                width: $station->output_width,
                height: $station->output_height,
                targetPath: $videoPath,
            );
        } catch (Throwable $e) {
            // Unfertige Reihe aufräumen; needs_render bleibt gesetzt, damit es
            // in der nächsten Nacht erneut versucht wird.
            $series->delete();
            Log::error('Zeitraffer konnte nicht erstellt werden.', ['station' => $station->id, 'error' => $e->getMessage()]);

            throw $e;
        }

        DB::transaction(function () use ($series, $frames, $videoPath, $result, $station, $previous, $storage): void {
            $series->forceFill([
                'video_path' => $videoPath,
                'frame_count' => (int) ($result['frames'] ?? $frames->count()),
                'duration_seconds' => (float) ($result['duration'] ?? 0),
                'rendered_at' => now(),
            ])->save();

            $series->frames()->sync(
                $frames->values()->mapWithKeys(fn ($frame, int $i) => [$frame->id => ['position' => $i]])->all()
            );

            $this->removeSeries($previous, $storage);

            $station->forceFill(['needs_render' => false])->save();
        });
    }

    /**
     * Nur die aktuelle Reihe bleibt erhalten; alte Videos werden gelöscht.
     */
    private function removeSeries($seriesList, PhotoStorage $storage): void
    {
        foreach ($seriesList as $old) {
            /** @var Series $old */
            if ($old->video_path) {
                $storage->public()->delete($old->video_path);
            }
            $old->delete();
        }
    }
}
