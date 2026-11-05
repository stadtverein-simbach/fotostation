<?php

namespace App\Http\Controllers;

use App\Enums\SubmissionStatus;
use App\Models\Report;
use App\Models\Submission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;

/**
 * Melden von Inhalten (Art. 16 DSA).
 */
class ReportController extends Controller
{
    public function create(Submission $submission): View
    {
        abort_unless($submission->status === SubmissionStatus::Published, 404);

        return view('reports.create', ['submission' => $submission->load('station')]);
    }

    public function store(Request $request, Submission $submission): RedirectResponse
    {
        abort_unless($submission->status === SubmissionStatus::Published, 404);

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
            'contact' => ['nullable', 'string', 'max:255'],
        ], [
            'reason.required' => 'Bitte beschreibe kurz, warum das Foto entfernt werden soll.',
            'reason.min' => 'Bitte beschreibe den Grund etwas genauer (mindestens 10 Zeichen).',
        ]);

        $report = Report::create([
            'submission_id' => $submission->id,
            'reason' => $data['reason'],
            'contact' => $data['contact'] ?? null,
        ]);

        if ($to = config('fotostation.admin_email')) {
            try {
                Mail::raw(
                    "Neue Meldung zu Foto {$submission->id}:\n\n{$report->reason}\n\n".url('/admin'),
                    fn ($message) => $message->to($to)->subject('Fotostation: neue Meldung'),
                );
            } catch (\Throwable $e) {
                Log::warning('Benachrichtigung zur Meldung fehlgeschlagen.', ['error' => $e->getMessage()]);
            }
        }

        return redirect()
            ->route('stations.show', $submission->station)
            ->with('status', 'Danke für deine Meldung. Wir prüfen das Foto zeitnah.');
    }
}
