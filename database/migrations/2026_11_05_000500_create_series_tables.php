<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('series', function (Blueprint $table) {
            $table->id();
            $table->foreignId('station_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20)->default('timelapse');
            $table->timestamp('period_from')->nullable();
            $table->timestamp('period_to')->nullable();
            $table->string('video_path')->nullable();
            $table->unsignedInteger('frame_count')->default(0);
            $table->float('duration_seconds')->nullable();
            $table->string('license', 20)->default('cc-by-4.0');
            $table->timestamp('rendered_at')->nullable();
            $table->timestamps();
        });

        Schema::create('series_frames', function (Blueprint $table) {
            $table->foreignId('series_id')->constrained('series')->cascadeOnDelete();
            $table->foreignUlid('submission_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->primary(['series_id', 'submission_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('series_frames');
        Schema::dropIfExists('series');
    }
};
