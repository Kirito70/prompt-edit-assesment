<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('client_request_id');
            $table->string('provider_generation_id')->nullable()->unique();
            $table->string('provider_idempotency_key')->unique();
            $table->text('prompt');
            $table->string('size');
            $table->string('quality');
            $table->json('reference_images');
            $table->string('status')->default('queued');
            $table->string('submission_status')->default('pending');
            $table->decimal('estimated_customer_charge', 20, 12);
            $table->decimal('provider_cost', 20, 12)->nullable();
            $table->decimal('customer_charge', 20, 12)->nullable();
            $table->json('rates')->nullable();
            $table->json('usage')->nullable();
            $table->text('image_url')->nullable();
            $table->timestamp('image_url_expires_at')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('provider_completed_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'client_request_id']);
            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
