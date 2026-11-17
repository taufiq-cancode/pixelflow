<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per billed generation. All amounts are integer nanodollars
     * (1e-9 USD). Reports only ever sum these stored integers.
     */
    public function up(): void
    {
        Schema::create('generation_costs', function (Blueprint $table) {
            $table->id();
            // Unique: a generation can be charged at most once, whatever
            // order or number of times its events arrive.
            $table->foreignId('generation_id')->unique()->constrained()->restrictOnDelete();

            // Token usage exactly as Pixelforge reported it.
            $table->unsignedInteger('input_text_tokens');
            $table->unsignedInteger('input_image_tokens');
            $table->unsignedInteger('output_tokens');
            $table->unsignedInteger('reasoning_tokens');

            // What Pixelforge charged us (exact, never rounded).
            $table->unsignedBigInteger('provider_cost_nano');
            // What we charged the user (provider cost + markup, rounded up
            // to a whole nanodollar). Zero when user_charged is false.
            $table->unsignedBigInteger('user_cost_nano');
            $table->unsignedInteger('markup_bps');
            // False only when Pixelforge billed a generation that we had
            // already reported to the user as failed (DECISIONS.md §16).
            $table->boolean('user_charged')->default(true);

            // Pixelforge bills a generation on the UTC day it completed.
            $table->date('billed_on')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generation_costs');
    }
};
