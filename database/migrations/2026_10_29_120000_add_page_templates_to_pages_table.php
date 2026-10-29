<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Page templates: pages that are never on the website, which new pages start from
 * (is_template), and the template each page was made from (template_id). Until this has run,
 * there are no templates and new pages start blank or from another page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->boolean('is_template')->default(false)->after('is_homepage')->index();
            $table->foreignUuid('template_id')->nullable()->after('is_template')->constrained('pages')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('template_id');
            $table->dropColumn('is_template');
        });
    }
};
