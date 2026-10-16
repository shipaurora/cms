<?php

use Datlechin\FilamentMenuBuilder\Enums\LinkTarget;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('filament-menu-builder.tables.menus'), function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('site_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->boolean('is_visible')->default(true);
            $table->timestamps();
        });

        Schema::create(config('filament-menu-builder.tables.menu_items'), function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('menu_id')->constrained(config('filament-menu-builder.tables.menus'))->cascadeOnDelete();
            $table->foreignUuid('parent_id')->nullable()->constrained($table->getTable())->nullOnDelete();
            $table->nullableUuidMorphs('linkable');
            $table->string('panel')->nullable();
            // Per language: {"en": "About", "nb": "Om oss"} (Aurora\Models\MenuItem).
            $table->json('title');
            $table->string('url')->nullable();
            $table->string('icon')->nullable();
            $table->string('classes')->nullable();
            $table->string('target', 10)->default(LinkTarget::Self);
            $table->integer('order')->default(0);
            $table->timestamps();
        });

        Schema::create(config('filament-menu-builder.tables.menu_locations'), function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('site_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('menu_id')->constrained(config('filament-menu-builder.tables.menus'))->cascadeOnDelete();
            // One menu per place on each site.
            $table->string('location');
            $table->unique(['site_id', 'location']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('filament-menu-builder.tables.menu_locations'));
        Schema::dropIfExists(config('filament-menu-builder.tables.menu_items'));
        Schema::dropIfExists(config('filament-menu-builder.tables.menus'));
    }
};
