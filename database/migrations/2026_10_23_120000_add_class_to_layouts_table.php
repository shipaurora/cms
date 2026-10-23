<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Layouts can come from code, a site's or a component library's (Layouts::register()): each
 * has a row like the others, so pages pick it and it can be the default, kept in step with
 * its class. Until this has run, only the layouts added in the admin are offered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('layouts', function (Blueprint $table) {
            $table->string('class')->nullable()->after('view');
        });
    }

    public function down(): void
    {
        Schema::table('layouts', function (Blueprint $table) {
            $table->dropColumn('class');
        });
    }
};
