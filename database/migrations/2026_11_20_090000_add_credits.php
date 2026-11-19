<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // What the user has bought minus what finished generations cost.
            $table->decimal('balance', 20, 12)->default(0);
        });

        Schema::table('generations', function (Blueprint $table) {
            // Held from the balance while the generation is unfinished; zero once settled.
            $table->decimal('reserved_credits', 20, 12)->default(0);
        });

        Schema::create('credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('generation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 16);
            // Signed: purchases are positive, charges negative.
            $table->decimal('amount', 20, 12);
            $table->decimal('balance_after', 20, 12);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
            // A generation is charged at most once, whatever happens to the webhook.
            $table->unique(['generation_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_transactions');

        Schema::table('generations', function (Blueprint $table) {
            $table->dropColumn('reserved_credits');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('balance');
        });
    }
};
