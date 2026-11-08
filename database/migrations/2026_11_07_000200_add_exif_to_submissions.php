<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            // Ausgewählte EXIF-Angaben (Gerät, Brennweite, Belichtung, Software),
            // siehe App\Support\ExifData. Nie Seriennummern, Namen oder GPS.
            $table->json('exif')->nullable()->after('height');
            // Eigene Spalte zum Filtern und Sortieren in der Moderation.
            $table->unsignedSmallInteger('focal_length_35mm')->nullable()->after('exif');
            // Abstand zwischen GPS-Position im Foto und Station in Metern,
            // auf 10 m gerundet und gedeckelt. Die Koordinaten selbst werden
            // nicht gespeichert.
            $table->unsignedSmallInteger('gps_distance')->nullable()->after('focal_length_35mm');
        });
    }

    public function down(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            $table->dropColumn(['exif', 'focal_length_35mm', 'gps_distance']);
        });
    }
};
