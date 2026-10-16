<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A redirect can send visitors to a file in the media library (an old /brochure.pdf to
 * the current brochure), and keeps working when the file is replaced.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('redirects', function (Blueprint $table) {
            $table->foreignUuid('asset_id')->nullable()->after('page_id')->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('redirects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('asset_id');
        });
    }
};
