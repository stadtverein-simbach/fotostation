<?php

namespace Tests\Feature;

use AltchaOrg\Altcha\Algorithm\Pbkdf2;
use AltchaOrg\Altcha\Altcha as AltchaLib;
use AltchaOrg\Altcha\Challenge;
use AltchaOrg\Altcha\Payload;
use AltchaOrg\Altcha\SolveChallengeOptions;
use App\Services\Altcha;
use Tests\TestCase;

class AltchaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'fotostation.captcha.enabled' => true,
            'fotostation.captcha.cost' => 10,
        ]);
    }

    private function solvedPayload(): string
    {
        $json = $this->getJson('/altcha/challenge')->assertOk()->json();
        $challenge = Challenge::fromArray($json);

        $solution = (new AltchaLib)->solveChallenge(new SolveChallengeOptions(
            algorithm: new Pbkdf2,
            challenge: $challenge,
        ));
        $this->assertNotNull($solution);

        return (new Payload($challenge, $solution))->toBase64();
    }

    public function test_challenge_endpoint_returns_signed_challenge(): void
    {
        $response = $this->getJson('/altcha/challenge');

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $response->assertOk()->assertJsonStructure(['parameters' => ['algorithm', 'nonce', 'salt', 'cost', 'expiresAt'], 'signature']);
    }

    public function test_valid_solution_is_accepted_only_once(): void
    {
        $payload = $this->solvedPayload();
        $altcha = app(Altcha::class);

        $this->assertTrue($altcha->verify($payload));
        $this->assertFalse($altcha->verify($payload));
    }

    public function test_invalid_payloads_are_rejected(): void
    {
        $altcha = app(Altcha::class);

        $this->assertFalse($altcha->verify(''));
        $this->assertFalse($altcha->verify('kein-base64'));

        $payload = json_decode(base64_decode($this->solvedPayload()), true);
        $payload['challenge']['parameters']['cost'] = 1;
        $this->assertFalse($altcha->verify(base64_encode(json_encode($payload))));
    }

    public function test_challenge_endpoint_is_disabled_without_captcha(): void
    {
        config(['fotostation.captcha.enabled' => false]);

        $this->get('/altcha/challenge')->assertNotFound();
    }
}
