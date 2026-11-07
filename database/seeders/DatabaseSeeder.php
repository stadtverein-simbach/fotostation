<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * php artisan db:seed
     *
     * Legt nur außerhalb der Produktion Testdaten an (bekannte Passwörter!).
     */
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('Produktion: keine Testdaten angelegt. Zugänge mit fotostation:create-admin anlegen.');

            return;
        }

        $this->call(DevelopmentSeeder::class);
    }
}
