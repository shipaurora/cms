<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tags cut across folders: an image lives in one folder but can carry any number of tags.
        Schema::create('asset_tags', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name')->unique();
            $table->string('color', 7);
            $table->timestamps();
        });

        Schema::create('asset_asset_tag', function (Blueprint $table) {
            $table->foreignUuid('asset_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('asset_tag_id')->constrained()->cascadeOnDelete();

            $table->primary(['asset_id', 'asset_tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_asset_tag');
        Schema::dropIfExists('asset_tags');
    }
};
