<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            $table->id();
            // Restrict: a user with generations (and therefore financial
            // records) can't be deleted out from under the cost history.
            $table->foreignId('user_id')->constrained()->restrictOnDelete();

            // Sent as the Idempotency-Key header, so a retried submit can
            // never create a second Pixelforge generation.
            $table->uuid('idempotency_key')->unique();
            // Null until Pixelforge accepts the generation (or a webhook
            // that beat the POST response links it via metadata).
            $table->string('pixelforge_id', 64)->nullable()->unique();
            $table->string('status', 16)->index();

            $table->text('prompt');
            $table->string('size', 16);
            $table->string('quality', 16);
            $table->json('reference_images');

            // Upfront estimate in user-facing nanodollars (markup included).
            // The max is a guaranteed upper bound on the actual cost.
            $table->unsignedBigInteger('estimated_min_cost_nano');
            $table->unsignedBigInteger('estimated_max_cost_nano');

            // The rates this generation is billed at, exactly as Pixelforge
            // returned them (fixed when the generation was created).
            $table->json('provider_rates')->nullable();

            $table->text('image_url')->nullable();
            $table->timestamp('image_url_expires_at')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();

            $table->unsignedSmallInteger('submit_attempts')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            // Last time a Pixelforge snapshot (response, webhook or poll)
            // was applied. The reconciler polls generations that go quiet.
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'last_synced_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
