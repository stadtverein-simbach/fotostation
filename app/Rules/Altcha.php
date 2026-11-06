<?php

namespace App\Rules;

use App\Services\Altcha as AltchaService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Prüft die ALTCHA-Lösung serverseitig (ohne externen Dienst).
 */
class Altcha implements ValidationRule
{
    /**
     * Auch ausführen, wenn das Feld fehlt (sonst ließe sich das Captcha
     * durch Weglassen des Feldes umgehen).
     */
    public bool $implicit = true;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! config('fotostation.captcha.enabled')) {
            return;
        }

        if (! is_string($value) || $value === '') {
            $fail('Bitte bestätige zuerst „Ich bin kein Roboter“.');

            return;
        }

        if (! app(AltchaService::class)->verify($value)) {
            $fail('Die Prüfung „Ich bin kein Roboter“ ist fehlgeschlagen oder abgelaufen. Bitte bestätige sie noch einmal.');
        }
    }
}
