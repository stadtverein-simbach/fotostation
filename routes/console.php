<?php

use App\Enums\SubmissionStatus;
use App\Enums\UserRole;
use App\Jobs\RenderSeries;
use App\Models\Station;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Nächtliche Aufgaben
|--------------------------------------------------------------------------
|
| Alles läuft gebündelt in einem Zeitfenster, damit die (hibernate-fähige)
| Datenbank nur einmal am Tag für den Scheduler aufwacht. Die Erreichbarkeit
| des Workers (GET /health) prüft das externe Server-Monitoring.
|
*/

$at = config('fotostation.render.at', '03:00');

Artisan::command('fotostation:purge-ip-hashes', function (): void {
    $hours = (int) config('fotostation.retention.ip_hash_hours');

    $count = Submission::query()
        ->whereNotNull('ip_hash')
        ->where('created_at', '<=', now()->subHours($hours))
        ->update(['ip_hash' => null]);

    $this->info("IP-Hashes gelöscht: {$count}");
})->purpose('Löscht IP-Hashes nach Ablauf der Frist (Datenschutz).');

Artisan::command('fotostation:render-changed {--all : Alle aktiven Stationen, nicht nur geänderte}', function (): int {
    $stations = Station::query()
        ->when(! $this->option('all'), fn ($query) => $query->where('needs_render', true))
        ->when($this->option('all'), fn ($query) => $query->where('is_active', true))
        ->get();

    $failed = 0;

    foreach ($stations as $station) {
        $this->info("Zeitraffer für {$station->name} …");

        try {
            // Synchron: läuft im Scheduler bzw. unter „Commands“, nicht in der
            // Queue (90-Sekunden-Grenze der Managed Queues).
            app()->call([new RenderSeries($station), 'handle']);
        } catch (\Throwable $e) {
            $failed++;
            $this->error("Fehlgeschlagen: {$e->getMessage()}");
        }
    }

    $this->info($stations->isEmpty() ? 'Keine Station mit Änderungen.' : 'Fertig.');

    return $failed > 0 ? 1 : 0;
})->purpose('Rendert die Zeitraffer aller Stationen mit Änderungen (synchron).');

Artisan::command('fotostation:report-stuck', function (): void {
    $failed = Submission::where('status', SubmissionStatus::Failed)
        ->where('updated_at', '>=', now()->subDay())
        ->count();

    $stuck = Submission::whereIn('status', [SubmissionStatus::Received, SubmissionStatus::Processing])
        ->where('updated_at', '<=', now()->subHour())
        ->count();

    if ($failed === 0 && $stuck === 0) {
        return;
    }

    $message = "Fotostation: {$failed} fehlgeschlagene und {$stuck} hängende Einreichungen.";
    Log::warning($message);

    if ($to = config('fotostation.admin_email')) {
        Mail::raw($message."\n\n".url('/admin'), fn ($m) => $m->to($to)->subject('Fotostation: Verarbeitung prüfen'));
    }
})->purpose('Meldet fehlgeschlagene oder hängende Verarbeitungen.');

Artisan::command('fotostation:create-admin {email} {name} {--password= : Passwort (für nicht interaktive Umgebungen wie Laravel Cloud „Commands“)} {--moderator : Nur Moderationsrechte}', function (string $email, string $name): void {
    $password = $this->option('password');

    if (blank($password)) {
        if (! $this->input->isInteractive()) {
            $this->error('Bitte das Passwort mit --password="…" angeben.');

            return;
        }

        $password = $this->secret('Passwort (mindestens 12 Zeichen)');
    }

    if (mb_strlen((string) $password) < 12) {
        $this->error('Das Passwort ist zu kurz.');

        return;
    }

    $user = User::updateOrCreate(['email' => $email], [
        'name' => $name,
        'password' => $password,
        'role' => $this->option('moderator') ? UserRole::Moderator : UserRole::Admin,
    ]);

    $this->info("Zugang für {$user->email} ({$user->role->getLabel()}) angelegt.");

    if ($this->option('password')) {
        $this->warn('Das Passwort steht jetzt im Befehlsverlauf. Bitte nach der ersten Anmeldung ändern.');
    }
})->purpose('Legt einen Admin- oder Moderationszugang an.');

Schedule::command('model:prune', ['--model' => [Submission::class]])->dailyAt($at);
Schedule::command('fotostation:purge-ip-hashes')->dailyAt($at);
Schedule::command('fotostation:render-changed')->dailyAt($at)->withoutOverlapping(60);
Schedule::command('fotostation:report-stuck')->dailyAt($at);
