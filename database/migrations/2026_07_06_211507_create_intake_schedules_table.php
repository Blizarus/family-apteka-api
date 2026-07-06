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
        Schema::create('intake_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('intake_course_id')->constrained()->cascadeOnDelete();
            $table->enum('frequency_type ', ['daily', 'interval_hours', 'specific_days', 'as_needed']);
            $table->jsonb('time_slots');
            $table->integer('interval_hours')->nullable();
            $table->jsonb('days_of_week ')->nullable();
            $table->date('valid_from');
            $table->date('valid_until')->nullable();            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('intake_schedules');
    }
};
