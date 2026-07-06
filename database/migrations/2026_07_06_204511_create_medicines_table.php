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
        Schema::create('medicines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medicine_box_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reference_medicine_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('custom_trade_name')->nullable();
            $table->string('barcode')->nullable();
            $table->decimal('quantity', 10, 3);
            $table->enum('quantity_unit', ['pill', 'ml', 'mg', 'ampoule', 'patch', 'unit']);
            $table->date('expiration_date');
            $table->date('opened_at')->nullable();
            $table->enum('status', ['active', 'expired', 'finished', 'disposed']);
            $table->index(['medicine_box_id', 'barcode']);
            $table->index(['medicine_box_id', 'expiration_date']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('medicines');
    }
};
