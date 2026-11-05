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

Artisan::command('fotostation:render-changed', function (): void {
    Station::query()
        ->where('needs_render', true)
        ->each(fn (Station $station) => RenderSeries::dispatch($station));
})->purpose('Rendert die Zeitraffer aller Stationen mit Änderungen.');

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

Artisan::command('fotostation:create-admin {email} {name} {--moderator : Nur Moderationsrechte}', function (string $email, string $name): void {
    $password = $this->secret('Passwort (mindestens 12 Zeichen)');

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
})->purpose('Legt einen Admin- oder Moderationszugang an.');

Schedule::command('model:prune', ['--model' => [Submission::class]])->dailyAt($at);
Schedule::command('fotostation:purge-ip-hashes')->dailyAt($at);
Schedule::command('fotostation:render-changed')->dailyAt($at);
Schedule::command('fotostation:report-stuck')->dailyAt($at);
