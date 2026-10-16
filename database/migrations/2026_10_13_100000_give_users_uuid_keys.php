<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Aurora owns the user model, and its users have UUID ids (Aurora\Models\User). A fresh
 * Laravel app's users table has integer ids: while it has no users yet, it is made again
 * with UUIDs (and the sessions that point at users with it). A users table that already
 * has people in it is never touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users') && ! $this->hasIntegerIds('users')) {
            return;
        }

        if (Schema::hasTable('users') && DB::table('users')->exists()) {
            throw new RuntimeException('Aurora needs UUID user ids, but this app\'s users table has integer ids and already has users in it. Aurora can only take over a users table that is still empty.');
        }

        $hadSessions = Schema::hasTable('sessions');

        Schema::dropIfExists('sessions');
        Schema::dropIfExists('users');

        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });

        if ($hadSessions) {
            Schema::create('sessions', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->foreignUuid('user_id')->nullable()->index();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->longText('payload');
                $table->integer('last_activity')->index();
            });
        }
    }

    public function down(): void
    {
        //
    }

    protected function hasIntegerIds(string $table): bool
    {
        return str_contains(strtolower(Schema::getColumnType($table, 'id')), 'int');
    }
};
