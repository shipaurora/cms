<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "AI assistant", "AI Assistant" and "Add " (trailing space) are different labels: compare keys exactly, without padding.
     */
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        Schema::table('language_lines', function (Blueprint $table) {
            $table->string('group')->collation('utf8mb4_0900_bin')->change();
            $table->string('key', 500)->collation('utf8mb4_0900_bin')->change();
        });
    }
};
