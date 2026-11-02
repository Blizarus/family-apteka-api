<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Перевод полей аллергий и хронических заболеваний на сквозное шифрование AES-256 (152-ФЗ).
     */
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->text('allergies')->nullable()->change();
            $table->text('chronic_conditions')->nullable()->change();
        });

        // Безопасное шифрование существующих записей в БД
        $profiles = DB::table('profiles')->get();
        foreach ($profiles as $profile) {
            $updates = [];
            if ($profile->allergies && ! str_starts_with($profile->allergies, 'eyJ')) {
                $decoded = json_decode($profile->allergies, true);
                if (is_array($decoded)) {
                    $updates['allergies'] = Crypt::encryptString(json_encode(array_values($decoded)));
                }
            }
            if ($profile->chronic_conditions && ! str_starts_with($profile->chronic_conditions, 'eyJ')) {
                $decoded = json_decode($profile->chronic_conditions, true);
                if (is_array($decoded)) {
                    $updates['chronic_conditions'] = Crypt::encryptString(json_encode(array_values($decoded)));
                }
            }
            if (! empty($updates)) {
                DB::table('profiles')->where('id', $profile->id)->update($updates);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->json('allergies')->nullable()->change();
            $table->json('chronic_conditions')->nullable()->change();
        });
    }
};
