<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The languages the site and the admin panel are available in. One is the default:
        // the website's language and the fallback for every label.
        Schema::create('locales', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 12)->unique();
            $table->string('name');
            // The first part of the website's addresses in this language (/nb/om-oss); the code when empty.
            $table->string('prefix', 32)->nullable()->unique();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        // spatie/laravel-translation-loader: one row per label, its text per locale as JSON.
        // Group "*" holds sentence-style keys (__('Thank you!')), others "group.key" keys.
        Schema::create('language_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('group')->index();
            $table->string('key', 500);
            $table->json('text');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['group', 'key']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('locale', 12)->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('locale'));
        Schema::dropIfExists('language_lines');
        Schema::dropIfExists('locales');
    }
};
