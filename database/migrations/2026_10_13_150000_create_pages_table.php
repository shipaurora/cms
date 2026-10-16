<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('site_id')->constrained()->cascadeOnDelete();
            // The page it sits under: its address starts with its ancestors' (/docs/getting-started).
            $table->foreignUuid('parent_id')->nullable()->constrained('pages')->nullOnDelete();
            // Title, address, summary and SEO text in every language ({"en": …, "nb": …});
            // addresses are unique per language, which the page form checks.
            $table->json('title');
            $table->json('slug');
            $table->json('excerpt')->nullable();
            // One block structure for every language; other languages' text sits on top of it,
            // by block id ({"nb": {"<block id>": {"headline": …}}}).
            $table->json('blocks')->nullable();
            $table->json('block_translations')->nullable();
            // The languages the page is in besides the default site language (null: all of them).
            $table->json('locales')->nullable();
            $table->string('status')->default('draft')->index();
            $table->timestamp('published_at')->nullable()->index();
            $table->string('layout')->default('default');
            $table->boolean('is_homepage')->default(false)->index();
            $table->json('meta_title')->nullable();
            $table->json('meta_description')->nullable();
            $table->string('og_image')->nullable();
            $table->boolean('noindex')->default(false);
            // What the page says, in all its languages, for search (Aurora\Support\PageSearch).
            $table->boolean('exclude_from_search')->default(false);
            // Hashed: visitors type it to see the page (Aurora\Support\PagePassword).
            $table->string('password')->nullable();
            $table->longText('search_text')->nullable();
            $table->timestamp('indexed_at')->nullable();
            $table->foreignUuid('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('last_edited_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            // Full-text search where the database has it (Aurora\Support\Search\DatabaseEngine).
            if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                $table->fullText('search_text');
            }
        });

        Schema::create('page_revisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('page_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source')->default('editor');
            $table->string('summary')->nullable();
            $table->json('snapshot');
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_revisions');
        Schema::dropIfExists('pages');
    }
};
