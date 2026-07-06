<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('intake_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('intake_course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('intake_schedule_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('medicine_id')->nullable()->constrained();
            $table->timestamp('scheduled_time');
            $table->timestamp('taken_time')->nullable();
            $table->timestamp('client_created_at')->nullable();
            $table->decimal('quantity_taken', 10, 3)->nullable();
            $table->enum('status', ['scheduled', 'taken', 'missed', 'skipped']);
            $table->string('skipped_reason')->nullable();
            $table->index(['intake_course_id', 'scheduled_time']);
            $table->index(['status', 'scheduled_time']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('intake_logs');
    }
};
