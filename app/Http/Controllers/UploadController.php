<?php

namespace App\Http\Controllers;

use App\Enums\SubmissionStatus;
use App\Http\Requests\StoreSubmissionRequest;
use App\Models\ConsentVersion;
use App\Models\Station;
use App\Models\Submission;
use App\Services\PhotoStorage;
use App\Support\ImageProcessor;
use App\Support\Pipeline;
use App\Support\UnsupportedImageException;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class UploadController extends Controller
{
    public function show(Station $station): View
    {
        abort_unless($station->is_active, 404);

        return view('upload.show', [
            'station' => $station,
            'ready' => $station->isReady() && ConsentVersion::current() !== null,
            'consent' => ConsentVersion::current(),
        ]);
    }

    public function store(StoreSubmissionRequest $request, Station $station, ImageProcessor $processor, PhotoStorage $storage): RedirectResponse
    {
        abort_unless($station->isReady(), 404);

        $consent = ConsentVersion::current();
        abort_if($consent === null, 503, 'Kein gültiger Zustimmungstext hinterlegt.');

        try {
            $image = $processor->process($request->file('photo')->getRealPath());
        } catch (UnsupportedImageException $e) {
            throw ValidationException::withMessages(['photo' => $e->getMessage()]);
        }

        $ipHash = hash_hmac('sha256', (string) $request->ip(), (string) config('app.key'));

        if ($this->isDuplicate($station, $image->sha256, $image->phash, $ipHash)) {
            // Keine Rückmeldung, ob es ein Duplikat war: einfach freundlich bestätigen.
            return redirect()->to($this->thanksUrl($station));
        }

        $submission = new Submission([
            'station_id' => $station->id,
            'status' => SubmissionStatus::Received,
            'taken_at' => $image->takenAt ? CarbonImmutable::instance($image->takenAt) : null,
            'width' => $image->width,
            'height' => $image->height,
            'display_name' => $request->string('name')->trim()->toString() ?: null,
            'consent_version_id' => $consent->id,
            'consent_at' => now(),
            'ip_hash' => $ipHash,
            'sha256' => $image->sha256,
            'phash' => $image->phash,
        ]);
        $submission->id = $submission->newUniqueId();
        $submission->original_path = $submission->pathFor('original');
        $submission->review_thumb_path = $submission->pathFor('review_thumb');

        // Nur die neu kodierte Fassung ohne Metadaten wird gespeichert.
        $storage->putPrivate($submission->original_path, $image->jpeg, 'image/jpeg');
        $storage->putPrivate($submission->review_thumb_path, $image->thumbWebp, 'image/webp');

        try {
            DB::transaction(fn () => $submission->save());
        } catch (Throwable $e) {
            $storage->private()->delete([$submission->original_path, $submission->review_thumb_path]);

            throw $e;
        }

        Pipeline::dispatch($submission);

        return redirect()->to($this->thanksUrl($station));
    }

    public function thanks(Request $request, Station $station): View
    {
        return view('upload.thanks', [
            'station' => $station,
            'series' => $station->latestSeries,
        ]);
    }

    private function thanksUrl(Station $station): string
    {
        return URL::temporarySignedRoute('upload.thanks', now()->addMinutes(30), $station);
    }

    private function isDuplicate(Station $station, string $sha256, string $phash, string $ipHash): bool
    {
        $base = Submission::query()
            ->where('station_id', $station->id)
            ->whereNotIn('status', [SubmissionStatus::Rejected, SubmissionStatus::AutoRejected]);

        if ((clone $base)->where('sha256', $sha256)->exists()) {
            return true;
        }

        $maxDistance = (int) config('fotostation.upload.duplicate_distance');

        return (clone $base)
            ->where('ip_hash', $ipHash)
            ->where('created_at', '>=', now()->subDay())
            ->whereNotNull('phash')
            ->pluck('phash')
            ->contains(fn (string $other) => ImageProcessor::hashDistance($phash, $other) <= $maxDistance);
    }
}
