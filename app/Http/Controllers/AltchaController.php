<?php

namespace App\Http\Controllers;

use App\Services\Altcha;
use Illuminate\Http\JsonResponse;

class AltchaController extends Controller
{
    /**
     * Liefert dem ALTCHA-Widget eine neue Aufgabe.
     */
    public function challenge(Altcha $altcha): JsonResponse
    {
        abort_unless(config('fotostation.captcha.enabled'), 404);

        return response()
            ->json($altcha->challenge())
            ->header('Cache-Control', 'no-store');
    }
}
