<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider_event_id')->unique();
            $table->string('provider_generation_id')->nullable()->index();
            $table->string('event_type');
            $table->json('payload');
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->text('processing_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
