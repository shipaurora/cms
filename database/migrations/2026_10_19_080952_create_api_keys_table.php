<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Keys apps read the website's APIs with (REST and GraphQL), each named for what uses it.
        Schema::create('api_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            // Only its hash is kept: the key itself is shown once, when it's made.
            $table->string('key_hash', 64)->unique();
            // Its first characters, to tell keys apart ("aurora_Xk3f…").
            $table->string('hint', 20);
            $table->foreignUuid('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_keys');
    }
};
