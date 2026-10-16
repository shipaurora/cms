<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mcp_activities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // The site the call worked on: each site's activity log shows its own.
            $table->foreignUuid('site_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            // Which AI made the call: an external app over MCP or the assistant in the admin panel.
            $table->string('channel')->default('mcp')->index();
            $table->string('tool');
            $table->string('summary')->nullable();
            $table->boolean('succeeded')->default(true);
            $table->nullableUuidMorphs('subject');
            $table->json('arguments')->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mcp_activities');
    }
};
