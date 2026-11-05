<?php

namespace App\Providers;

use App\Support\ImageProcessor;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ImageProcessor::class, function (): ImageProcessor {
            $upload = config('fotostation.upload');

            return new ImageProcessor(
                maxEdge: $upload['max_edge'],
                minEdge: $upload['min_edge'],
                jpegQuality: $upload['jpeg_quality'],
                thumbWidth: $upload['thumb_width'],
                timezone: config('app.timezone'),
            );
        });
    }

    public function boot(): void
    {
        RateLimiter::for('uploads', function (Request $request): array {
            $upload = config('fotostation.upload');
            $stationId = $request->route('station')?->id ?? 'none';
            // Rate-Limiter-Schlüssel nur als Hash, keine Klartext-IP im Cache.
            $ip = hash_hmac('sha256', (string) $request->ip(), (string) config('app.key'));

            return [
                Limit::perHour($upload['per_ip_per_hour'])->by("upload:ip:{$ip}:{$stationId}"),
                Limit::perDay($upload['per_station_per_day'])->by("upload:station:{$stationId}"),
            ];
        });

        RateLimiter::for('reports', function (Request $request): Limit {
            $ip = hash_hmac('sha256', (string) $request->ip(), (string) config('app.key'));

            return Limit::perHour(5)->by("report:{$ip}");
        });
    }
}
