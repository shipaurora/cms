<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Folders nest: a folder's parent_id points at the folder it sits in (null = top level).
        Schema::create('asset_folders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->foreignUuid('parent_id')->nullable()->constrained('asset_folders')->cascadeOnDelete();
            // Who can see and change it: "inherit" (as the folder it's in) or "custom" (asset_access).
            $table->string('access')->default('inherit');
            $table->timestamps();

            $table->unique(['parent_id', 'name']);
        });

        Schema::create('assets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title');
            // The filesystem disk its file is on, so files stay where they are (and keep working)
            // when the media library moves to another disk (media-library.disk_name).
            $table->string('disk')->nullable()->index();
            $table->json('alt')->nullable();
            $table->json('caption')->nullable();
            $table->foreignUuid('folder_id')->nullable()->constrained('asset_folders')->nullOnDelete();
            $table->string('credit')->nullable();
            $table->string('access')->default('inherit');
            $table->foreignUuid('uploaded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        // Where each asset is used: rebuilt whenever a page is saved, so the media library
        // can say "Used on 3 pages" and warn before deleting an image that is in use.
        Schema::create('asset_usages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('asset_id')->constrained()->cascadeOnDelete();
            $table->uuidMorphs('usable');
            $table->string('location')->nullable();
            $table->timestamps();

            $table->unique(['asset_id', 'usable_type', 'usable_id', 'location']);
        });

        // Who can read, or read and change, a file or folder with its own access ("custom"):
        // everyone (no grantee), a role or one person.
        Schema::create('asset_access', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuidMorphs('accessible');
            $table->nullableUuidMorphs('grantee');
            $table->string('level');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_access');
        Schema::dropIfExists('asset_usages');
        Schema::dropIfExists('assets');
        Schema::dropIfExists('asset_folders');
    }
};
