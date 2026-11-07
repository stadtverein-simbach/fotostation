<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stations', function (Blueprint $table) {
            // Sicherheitsrand in Prozent je Seite, der nach dem Ausrichten
            // abgeschnitten wird (gleich für alle Fotos der Station).
            $table->decimal('crop_margin', 4, 1)->default(4.0)->after('output_height');
        });

        Schema::table('submissions', function (Blueprint $table) {
            // Anteil echter Fotopixel im ausgerichteten Bild (0..1); der Rest
            // wurde aus dem Referenzbild ergänzt.
            $table->float('coverage')->nullable()->after('homography');
        });
    }

    public function down(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            $table->dropColumn('coverage');
        });

        Schema::table('stations', function (Blueprint $table) {
            $table->dropColumn('crop_margin');
        });
    }
};
