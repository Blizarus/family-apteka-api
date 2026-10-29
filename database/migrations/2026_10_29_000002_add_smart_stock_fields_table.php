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
        // 1. Условия хранения и срок после вскрытия в эталонном справочнике
        Schema::table('reference_medicines', function (Blueprint $table) {
            $table->string('storage_condition', 20)->default('room')->after('is_prescription');
            $table->integer('days_after_opening')->nullable()->after('storage_condition');
        });

        // 2. Условия хранения, срок после вскрытия и порог минимального остатка в аптечках
        Schema::table('medicines', function (Blueprint $table) {
            $table->string('storage_condition', 20)->default('room')->after('status');
            $table->integer('days_after_opening')->nullable()->after('storage_condition');
            $table->decimal('low_stock_threshold', 10, 3)->default(2.0)->after('days_after_opening');
        });

        // 3. Мягкое удаление аптечек при удалении аккаунта владельца
        Schema::table('medicine_boxes', function (Blueprint $table) {
            $table->softDeletes()->after('description');
        });

        // 4. Текст сообщения и поддержка расширенных типов уведомлений в notification_logs
        Schema::table('notification_logs', function (Blueprint $table) {
            $table->text('message')->nullable()->after('sent_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notification_logs', function (Blueprint $table) {
            $table->dropColumn('message');
        });

        Schema::table('medicine_boxes', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('medicines', function (Blueprint $table) {
            $table->dropColumn(['storage_condition', 'days_after_opening', 'low_stock_threshold']);
        });

        Schema::table('reference_medicines', function (Blueprint $table) {
            $table->dropColumn(['storage_condition', 'days_after_opening']);
        });
    }
};
