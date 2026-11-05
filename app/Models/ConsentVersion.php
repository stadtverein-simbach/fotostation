<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Versionierter Zustimmungstext. Bestehende Versionen werden nie geändert,
 * damit jede Einreichung nachweisbar auf den Text verweist, dem zugestimmt wurde.
 */
#[Fillable(['version', 'license', 'text', 'valid_from'])]
class ConsentVersion extends Model
{
    protected function casts(): array
    {
        return [
            'valid_from' => 'datetime',
        ];
    }

    public static function current(): ?self
    {
        return static::query()
            ->where('valid_from', '<=', now())
            ->latest('valid_from')
            ->first();
    }

    protected static function booted(): void
    {
        static::updating(function (self $version): void {
            throw new \LogicException('Zustimmungstexte sind unveränderlich. Bitte eine neue Version anlegen.');
        });
    }
}
