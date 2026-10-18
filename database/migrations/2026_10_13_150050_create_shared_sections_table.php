<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A section built once and placed on many pages (the "Shared section" block):
        // changed in one place, it changes everywhere it's shown.
        Schema::create('shared_sections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('site_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->json('blocks')->nullable();
            // Each other language's text, by block (Aurora\Support\BlockTranslations), as pages keep it.
            $table->json('block_translations')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shared_sections');
    }
};
