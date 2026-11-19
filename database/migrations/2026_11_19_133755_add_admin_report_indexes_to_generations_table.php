<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            // The admin report filters and groups by the day a generation completed.
            $table->index('provider_completed_at');
            $table->index(['user_id', 'provider_completed_at']);
        });
    }

    public function down(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->dropIndex(['provider_completed_at']);
            $table->dropIndex(['user_id', 'provider_completed_at']);
        });
    }
};
