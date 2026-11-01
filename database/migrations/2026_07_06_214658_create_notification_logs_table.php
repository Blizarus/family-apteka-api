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
        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type');
            $table->bigInteger('entity_id');
            $table->enum('type', [
                'expiry_warning_30d',
                'expiry_warning_7d',
                'expiry_critical',
                'intake_reminder',
                'opened_expired',
                'low_stock',
                'push_test',
            ]);
            $table->foreignId('user_id')->constrained()->cascadeOnDelete()->index();
            $table->timestamp('sent_at');
            $table->index(['entity_type', 'entity_id']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
    }
};
