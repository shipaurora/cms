<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('site_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // One set of fields for every language; other languages' text for them sits on top,
            // by field name ({"nb": {"email": {"label": "E-post"}}}).
            $table->json('fields')->nullable();
            $table->json('field_translations')->nullable();
            // In every language ({"en": …, "nb": …}).
            $table->json('submit_label')->nullable();
            $table->json('success_message')->nullable();
            $table->foreignUuid('redirect_page_id')->nullable()->constrained('pages')->nullOnDelete();
            $table->json('notify')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forms');
    }
};
