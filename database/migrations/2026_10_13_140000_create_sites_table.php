<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // The websites this CMS runs: one (the default) unless multi-site is switched on.
        Schema::create('sites', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            // Its own picture in the site switcher (public disk, like people's avatars), else its initial.
            $table->string('avatar')->nullable();
            // In the admin's addresses when multi-site is on: /admin/{handle}/pages.
            $table->string('handle')->unique();
            // The site answers on this host; the default site also answers on any other.
            $table->string('domain')->nullable()->unique();
            $table->boolean('is_default')->default(false);
            // Its own default language and languages (codes); empty: the CMS's.
            $table->string('default_locale', 12)->nullable();
            $table->json('locales')->nullable();
            // Site settings: name, colours, header, footer, socials, announcement.
            $table->json('settings')->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        // Who can work on which site (Super Admins and people who manage sites: all of them).
        Schema::create('site_user', function (Blueprint $table) {
            $table->foreignUuid('site_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['site_id', 'user_id']);
        });

        // There is always a default site: everything belongs to it until there are more.
        DB::table('sites')->insert([
            'id' => (string) Str::uuid7(),
            'name' => 'Main site',
            'handle' => 'main',
            'is_default' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('site_user');
        Schema::dropIfExists('sites');
    }
};
