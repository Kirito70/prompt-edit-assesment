<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A user can choose to generate when the price could not be worked out.
     * No quote was shown to them, so none is stored (NULL, never a fake zero).
     */
    public function up(): void
    {
        Schema::table('generations', function (Blueprint $table) {
            $table->decimal('estimated_customer_charge', 20, 12)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Rows without a quote cannot satisfy NOT NULL, so give them a zero first.
        DB::table('generations')->whereNull('estimated_customer_charge')->update(['estimated_customer_charge' => 0]);

        Schema::table('generations', function (Blueprint $table) {
            $table->decimal('estimated_customer_charge', 20, 12)->nullable(false)->change();
        });
    }
};
