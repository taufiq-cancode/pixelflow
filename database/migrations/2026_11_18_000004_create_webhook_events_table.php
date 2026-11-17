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
            // Unique: an event delivered twice is only ever processed once.
            $table->string('event_id', 64)->unique();
            $table->string('type', 64);
            $table->string('pixelforge_generation_id', 64)->nullable()->index();
            $table->unsignedSmallInteger('delivery_attempt')->nullable();
            $table->json('payload');
            // What processing did: applied, ignored_stale, unmatched, ...
            $table->string('outcome', 32)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
