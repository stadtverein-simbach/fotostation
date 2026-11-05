<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Prüft das Friendly-Captcha-Token (v2) serverseitig.
 *
 * Antwortet die API nicht mit 200 (Ausfall, Fehlkonfiguration), wird das
 * Token akzeptiert, wie Friendly Captcha es empfiehlt. Rate-Limiting,
 * Honeypot, Bildabgleich und Moderation greifen trotzdem.
 */
class FriendlyCaptcha implements ValidationRule
{
    /**
     * Auch ausführen, wenn das Feld fehlt (sonst ließe sich das Captcha
     * durch Weglassen des Feldes umgehen).
     */
    public bool $implicit = true;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $config = config('fotostation.captcha');

        if (! $config['enabled']) {
            return;
        }

        if (! is_string($value) || $value === '') {
            $fail('Bitte warte, bis die Prüfung „Ich bin kein Roboter“ abgeschlossen ist.');

            return;
        }

        $host = $config['endpoint'] === 'global' ? 'https://global.frcapi.com' : 'https://eu.frcapi.com';

        try {
            $response = Http::withHeaders(['X-API-Key' => (string) $config['api_key']])
                ->timeout(5)
                ->asJson()
                ->post($host.'/api/v2/captcha/siteverify', [
                    'response' => $value,
                    'sitekey' => $config['sitekey'],
                ]);
        } catch (ConnectionException $e) {
            Log::warning('Friendly Captcha nicht erreichbar, Upload wird zugelassen.', ['error' => $e->getMessage()]);

            return;
        }

        if ($response->status() !== 200) {
            Log::warning('Friendly Captcha antwortet mit Fehler, Upload wird zugelassen.', [
                'status' => $response->status(),
                'body' => $response->json('error'),
            ]);

            return;
        }

        if ($response->json('success') !== true) {
            $fail('Die Prüfung „Ich bin kein Roboter“ ist fehlgeschlagen. Bitte lade die Seite neu.');
        }
    }
}
