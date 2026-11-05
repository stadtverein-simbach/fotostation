<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('submissions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('station_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('received')->index();

            // Dateien (Pfade innerhalb der Buckets)
            $table->string('original_path')->nullable();
            $table->string('review_thumb_path')->nullable();
            $table->string('aligned_path')->nullable();
            $table->string('public_path')->nullable();
            $table->string('thumb_path')->nullable();

            // Metadaten
            $table->timestamp('taken_at')->nullable()->index();
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->string('display_name', 40)->nullable();

            // Zustimmung (Nachweis)
            $table->foreignId('consent_version_id')->constrained();
            $table->timestamp('consent_at');

            // Spamschutz, Duplikate
            $table->string('ip_hash', 64)->nullable();
            $table->string('sha256', 64)->index();
            $table->string('phash', 16)->nullable();

            // Bildabgleich
            $table->unsignedInteger('match_inliers')->nullable();
            $table->float('match_score')->nullable();
            $table->json('homography')->nullable();
            $table->json('face_boxes')->nullable();
            $table->json('manual_boxes')->nullable();

            // Moderation
            $table->string('rejection_reason')->nullable();
            $table->text('error')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('published_at')->nullable();

            $table->timestamps();

            $table->index(['station_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submissions');
    }
};
