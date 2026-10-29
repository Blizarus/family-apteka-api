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
        Schema::table('reference_medicines', function (Blueprint $table) {
            $table->text('composition')->nullable()->after('dosage');
            $table->json('allergen_tags')->nullable()->after('composition');
            $table->json('instructions')->nullable()->after('allergen_tags');
            $table->string('leaflet_pdf_url')->nullable()->after('image_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reference_medicines', function (Blueprint $table) {
            $table->dropColumn(['composition', 'allergen_tags', 'instructions', 'leaflet_pdf_url']);
        });
    }
};
