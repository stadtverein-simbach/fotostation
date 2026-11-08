<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            // Hochgeladene Datei mit unveränderten Bilddaten, nur ohne
            // Metadaten (privat). original_path ist die neu kodierte
            // Arbeitsfassung für Abgleich und Ausrichtung.
            $table->string('source_path')->nullable()->after('station_id');
        });
    }

    public function down(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            $table->dropColumn('source_path');
        });
    }
};
