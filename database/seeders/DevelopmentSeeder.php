<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Station;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Testdaten für die lokale Entwicklung. Mehrfach ausführbar: vorhandene
 * Einträge werden aktualisiert, nicht doppelt angelegt.
 */
class DevelopmentSeeder extends Seeder
{
    public const PASSWORD = 'testtesttest';

    public function run(): void
    {
        User::updateOrCreate(['email' => 'admin@example'], [
            'name' => 'Admin',
            'password' => self::PASSWORD,
            'role' => UserRole::Admin,
        ]);

        User::updateOrCreate(['email' => 'moderator@example'], [
            'name' => 'Moderator',
            'password' => self::PASSWORD,
            'role' => UserRole::Moderator,
        ]);

        // Ohne Referenzbild nimmt die Station noch keine Uploads an: im Admin
        // unter Stationen eines hochladen (Foto aus dem Rahmen).
        Station::updateOrCreate(['slug' => 'teststation-01'], [
            'name' => 'Teststation-01',
            'description' => 'Station für lokale Tests.',
            'lat' => 48.26872505621292,
            'lng' => 13.021923933307084,
            'output_width' => 1920,
            'output_height' => 1440,
            'min_inliers' => 40,
            'is_active' => true,
        ]);

        $this->command?->info('Testzugänge: admin@example und moderator@example, Passwort '.self::PASSWORD);
        $this->command?->info('Teststation: /s/teststation-01 (Referenzbild im Admin hochladen)');
    }
}
