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
        Schema::create('reference_medicines', function (Blueprint $table) {
            $table->id();
            $table->string('trade_name')->index();
            $table->string('inn')->index();
            $table->string('dosage_form');
            $table->string('dosage');
            $table->integer('pack_size');
            $table->string('manufacturer');
            $table->string('country');
            $table->string('atc_code')->index();
            $table->string('barcode')->index()->nullable();
            $table->boolean('is_prescription');
            $table->string('image_url')->nullable();
            $table->enum('status', ['active','archived']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reference_medicines');
    }
};
