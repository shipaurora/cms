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
        Schema::create('image_formats', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            // What code and file names call it ("lg"): set once, when the format is added.
            $table->string('handle')->unique();
            $table->unsignedSmallInteger('width');
            // With a height, images are cropped to exactly width × height.
            $table->unsignedSmallInteger('height')->nullable();
            $table->string('file_format')->default('webp');
            $table->unsignedTinyInteger('quality')->default(82);
            // Offered to browsers in srcset, to pick the size that fits the screen.
            $table->boolean('is_responsive')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        // The sizes every site starts with: small, medium and large responsive widths.
        $now = now();

        DB::table('image_formats')->insert(array_map(fn (array $format, int $sort): array => [
            'id' => (string) Str::uuid7(),
            ...$format,
            'file_format' => 'webp',
            'quality' => 82,
            'is_responsive' => true,
            'sort' => $sort,
            'created_at' => $now,
            'updated_at' => $now,
        ], [
            ['name' => 'Small', 'handle' => 'sm', 'width' => 640],
            ['name' => 'Medium', 'handle' => 'md', 'width' => 1280],
            ['name' => 'Large', 'handle' => 'lg', 'width' => 1920],
        ], [1, 2, 3]));
    }

    public function down(): void
    {
        Schema::dropIfExists('image_formats');
    }
};
