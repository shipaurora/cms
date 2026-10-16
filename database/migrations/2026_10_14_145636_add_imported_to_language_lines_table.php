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
        // The text per locale as last imported from the lang files: a label whose text still
        // matches it is unedited and follows the files; one that differs was edited here.
        Schema::table('language_lines', function (Blueprint $table) {
            $table->json('imported')->nullable()->after('text');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('language_lines', function (Blueprint $table) {
            $table->dropColumn('imported');
        });
    }
};
