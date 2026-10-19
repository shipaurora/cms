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
        // How pages are drawn on the website: each layout is a Blade view. Set up under
        // Pages › More › Layouts.
        Schema::create('layouts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            // What code asks for it by ("landing"): set once, when it's added.
            $table->string('handle')->unique();
            $table->string('description')->nullable();
            $table->string('icon')->nullable();
            // The Blade view it draws pages with ("aurora::layouts.standard", "layouts.campaign").
            $table->string('view');
            // Pages without a layout of their own use this one.
            $table->boolean('is_default')->default(false);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        // The layouts every site starts with, drawn by Aurora's own views (published to the
        // site on install, to make them its own): standard, the default; landing page, its
        // header over the first block; minimal, the logo alone; and blank, the page alone. A
        // site adds its own.
        $now = now();

        DB::table('layouts')->insert(array_map(fn (array $layout, int $sort): array => [
            'id' => (string) Str::uuid7(),
            ...$layout,
            'view' => 'aurora::layouts.'.$layout['handle'],
            'is_default' => $layout['handle'] === 'standard',
            'sort' => $sort,
            'created_at' => $now,
            'updated_at' => $now,
        ], [
            ['name' => 'Standard', 'handle' => 'standard', 'icon' => 'phosphor-app-window', 'description' => 'Header, menu and footer.'],
            ['name' => 'Landing page', 'handle' => 'landing', 'icon' => 'phosphor-sparkle', 'description' => 'Transparent header floating over the first block.'],
            ['name' => 'Minimal', 'handle' => 'minimal', 'icon' => 'phosphor-squares-four', 'description' => 'Logo only, no menu or footer. Great for campaigns.'],
            ['name' => 'Blank', 'handle' => 'blank', 'icon' => 'phosphor-square', 'description' => 'The page alone: no header, menu or footer.'],
        ], [1, 2, 3, 4]));
    }

    public function down(): void
    {
        Schema::dropIfExists('layouts');
    }
};
