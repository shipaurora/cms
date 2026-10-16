<?php

use Aurora\Models\Asset;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The media library takes any kind of file, not just images. Each asset keeps its file's
 * MIME type, so the library can filter by type and image fields only offer images.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->string('mime_type', 127)->nullable()->after('title')->index();
        });

        DB::table('media')
            ->where('model_type', (new Asset)->getMorphClass())
            ->where('collection_name', Asset::COLLECTION)
            ->orderBy('id')
            ->each(fn (object $media) => DB::table('assets')->where('id', $media->model_id)->update(['mime_type' => $media->mime_type]));
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn('mime_type');
        });
    }
};
