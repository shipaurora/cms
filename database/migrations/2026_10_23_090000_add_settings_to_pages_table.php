<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Settings of a page's own that aren't blocks, such as the fields its layout asks for (a
 * background image) (Aurora\Support\Layouts::schema()). Until this has run, pages are drawn
 * without them and the layout's fields aren't shown.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->json('settings')->nullable()->after('layout_id');
        });
    }

    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->dropColumn('settings');
        });
    }
};
