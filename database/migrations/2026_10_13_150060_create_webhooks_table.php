<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Addresses told about what happens to the content (Aurora\Events), as signed JSON.
        Schema::create('webhooks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('url');
            // The events it's sent ("page.published"), by name.
            $table->json('events');
            // Signs each request, so the receiver can tell it came from here.
            $table->string('secret');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('last_status')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhooks');
    }
};
