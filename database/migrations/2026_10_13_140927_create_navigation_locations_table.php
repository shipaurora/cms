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
        // The places in the website's templates a menu can be shown (Menu::location('header')).
        // Set up under Menus › More › Manage locations; menus are put in them with "Locations".
        Schema::create('navigation_locations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // What the templates ask for it by ("header"): set once, when it's added.
            $table->string('handle')->unique();
            $table->string('name');
            $table->string('description')->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        // The ones every site starts with; a site adds its own under Settings › Templates.
        $now = now();

        DB::table('navigation_locations')->insert(array_map(fn (array $location, int $sort): array => [
            'id' => (string) Str::uuid7(),
            ...$location,
            'sort' => $sort,
            'created_at' => $now,
            'updated_at' => $now,
        ], [
            ['handle' => 'header', 'name' => 'Main navigation', 'description' => 'The menu in the header. One level of dropdowns.'],
            ['handle' => 'footer', 'name' => 'Footer', 'description' => 'Columns of links in the footer, under their headings.'],
        ], [1, 2]));
    }

    public function down(): void
    {
        Schema::dropIfExists('navigation_locations');
    }
};
