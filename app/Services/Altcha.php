<?php

namespace App\Services;

use AltchaOrg\Altcha\Algorithm\Pbkdf2;
use AltchaOrg\Altcha\Altcha as AltchaLib;
use AltchaOrg\Altcha\CreateChallengeOptions;
use AltchaOrg\Altcha\Payload;
use AltchaOrg\Altcha\VerifySolutionOptions;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * ALTCHA: selbst gehosteter Spamschutz per Proof of Work.
 *
 * Der Server erzeugt eine signierte Rechenaufgabe, der Browser löst sie und
 * schickt die Lösung mit dem Formular. Die Prüfung findet komplett auf diesem
 * Server statt, es gibt keine Anfrage an einen externen Dienst.
 */
class Altcha
{
    private AltchaLib $altcha;

    private Pbkdf2 $algorithm;

    public function __construct()
    {
        $this->altcha = new AltchaLib(hmacSignatureSecret: $this->secret());
        $this->algorithm = new Pbkdf2;
    }

    /**
     * Neue Aufgabe für das Widget (JSON-serialisierbar).
     *
     * @return array<string, mixed>
     */
    public function challenge(): array
    {
        $config = config('fotostation.captcha');

        return $this->altcha->createChallenge(new CreateChallengeOptions(
            algorithm: $this->algorithm,
            cost: max(1, (int) $config['cost']),
            expiresAt: time() + max(60, (int) $config['expires']),
        ))->toArray();
    }

    /**
     * Prüft die vom Widget gesendete Lösung (Base64-Payload).
     *
     * Jede Aufgabe gilt nur einmal: Die Signatur wird bis zum Ablauf im Cache
     * vermerkt, damit eine gelöste Aufgabe nicht mehrfach verwendet werden kann.
     */
    public function verify(string $payload): bool
    {
        try {
            $parsed = Payload::fromBase64($payload);
            $result = $this->altcha->verifySolution(new VerifySolutionOptions(
                algorithm: $this->algorithm,
                payload: $parsed,
            ));
        } catch (Throwable) {
            return false;
        }

        if (! $result->verified) {
            return false;
        }

        $expiresAt = (int) ($parsed->challenge->parameters->expiresAt ?? 0);
        $ttl = max(60, $expiresAt - time() + 60);

        return Cache::add('altcha:'.hash('sha256', (string) $parsed->challenge->signature), true, $ttl);
    }

    /**
     * Eigener Schlüssel aus ALTCHA_HMAC_KEY, sonst aus APP_KEY abgeleitet.
     */
    private function secret(): string
    {
        $key = (string) config('fotostation.captcha.hmac_key');

        if ($key !== '') {
            return $key;
        }

        return hash_hmac('sha256', 'altcha', (string) config('app.key'));
    }
}
