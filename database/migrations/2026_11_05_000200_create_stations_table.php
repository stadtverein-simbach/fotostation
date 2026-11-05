<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stations', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 64)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('lat', 9, 6)->nullable();
            $table->decimal('lng', 9, 6)->nullable();
            // Referenzbild im privaten Bucket; alle Fotos werden darauf ausgerichtet.
            $table->string('reference_path')->nullable();
            $table->unsignedSmallInteger('output_width')->default(1920);
            $table->unsignedSmallInteger('output_height')->default(1440);
            // Mindestanzahl passender Merkmale (RANSAC-Inlier) für den Bildabgleich.
            $table->unsignedSmallInteger('min_inliers')->default(40);
            $table->boolean('is_active')->default(true);
            // Wird bei Veröffentlichung/Entfernung gesetzt; der Nacht-Job rendert dann neu.
            $table->boolean('needs_render')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stations');
    }
};
