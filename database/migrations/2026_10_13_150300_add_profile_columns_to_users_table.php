<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar')->nullable()->after('email');
            $table->string('job_title')->nullable()->after('avatar');
            $table->boolean('is_active')->default(true)->after('job_title');
            $table->timestamp('last_seen_at')->nullable()->after('remember_token');
            // When they last signed in: the first sign-in is kept quiet (no passkey offer).
            $table->timestamp('last_signed_in_at')->nullable()->after('last_seen_at');
            // "Don't show this again" on the offer to add a passkey after signing in.
            $table->timestamp('passkey_prompt_dismissed_at')->nullable()->after('last_seen_at');
            // Two-factor sign-in: one-time recovery codes, hashed (the apps are authenticator_apps).
            $table->text('app_authentication_recovery_codes')->nullable()->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['avatar', 'job_title', 'is_active', 'last_seen_at', 'last_signed_in_at', 'passkey_prompt_dismissed_at', 'app_authentication_recovery_codes']);
        });
    }
};
