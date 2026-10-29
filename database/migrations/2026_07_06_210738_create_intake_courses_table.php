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
        Schema::create('intake_courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained()->restrictOnDelete();
            $table->foreignId('medicine_id')->constrained()->restrictOnDelete();
            $table->string('title');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->decimal('dosage_quantity', 10, 3);
            $table->string('dosage_instruction');
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('intake_courses');
    }
};
