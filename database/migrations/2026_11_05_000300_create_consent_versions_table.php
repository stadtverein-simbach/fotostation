<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consent_versions', function (Blueprint $table) {
            $table->id();
            $table->string('version', 20)->unique();
            $table->string('license', 20)->default('cc0-1.0');
            $table->text('text');
            $table->timestamp('valid_from');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consent_versions');
    }
};
