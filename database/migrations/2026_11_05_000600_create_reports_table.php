<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Meldeweg nach Art. 16 DSA
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('submission_id')->constrained()->cascadeOnDelete();
            $table->text('reason');
            $table->string('contact')->nullable();
            $table->string('status', 20)->default('open')->index();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
