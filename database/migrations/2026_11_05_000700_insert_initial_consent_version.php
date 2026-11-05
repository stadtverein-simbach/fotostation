<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Erste Fassung des Zustimmungstexts, damit die Station nach dem
     * Deployment sofort Uploads annehmen kann. Änderungen später über
     * „Zustimmungstexte“ im Admin als neue Version anlegen.
     */
    public function up(): void
    {
        DB::table('consent_versions')->insert([
            'version' => '2026-11',
            'license' => 'cc0-1.0',
            'text' => 'Ich habe dieses Foto selbst aufgenommen und stelle es unter CC0 1.0 zur Verfügung. Damit darf es jeder ohne Bedingungen nutzen, auch der Stadtverein für Bilderreihen und Veröffentlichungen. Ich bin mindestens 16 Jahre alt oder meine Eltern sind einverstanden.',
            'valid_from' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('consent_versions')->where('version', '2026-11')->delete();
    }
};
