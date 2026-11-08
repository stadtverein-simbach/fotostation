<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const VERSION = '2026-11-2';

    /**
     * Neue Fassung des Zustimmungstexts: weist darauf hin, dass das Original
     * aufbewahrt wird. Bestehende Versionen bleiben unverändert, damit frühere
     * Einreichungen weiter auf den Text verweisen, dem zugestimmt wurde.
     */
    public function up(): void
    {
        if (DB::table('consent_versions')->where('version', self::VERSION)->exists()) {
            return;
        }

        // Muss nach der bisherigen Version gelten, auch wenn beide Migrationen
        // in derselben Sekunde laufen (frische Datenbank).
        $latest = DB::table('consent_versions')->max('valid_from');
        $validFrom = now()->toImmutable();
        if ($latest !== null && CarbonImmutable::parse($latest)->gte($validFrom)) {
            $validFrom = CarbonImmutable::parse($latest)->addSecond();
        }

        DB::table('consent_versions')->insert([
            'version' => self::VERSION,
            'license' => 'cc0-1.0',
            'text' => 'Ich habe dieses Foto selbst aufgenommen und stelle es unter CC0 1.0 zur Verfügung. Damit darf es jeder ohne Bedingungen nutzen, auch der Stadtverein für Bilderreihen und Veröffentlichungen. Ich bin mindestens 16 Jahre alt oder meine Eltern sind einverstanden. Der Stadtverein bewahrt das Originalfoto ohne Metadaten nicht öffentlich auf, um es später neu bearbeiten zu können. Veröffentlicht wird nur die bearbeitete Fassung, in der Personen verpixelt sind.',
            'valid_from' => $validFrom,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('consent_versions')->where('version', self::VERSION)->delete();
    }
};
