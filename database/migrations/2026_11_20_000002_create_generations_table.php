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
            // Sent as Idempotency-Key and in metadata, so webhooks can be matched before we know the gen_ id.
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('pixelforge_id')->nullable()->unique();

            // pending (not yet accepted by Pixelforge) -> queued -> processing -> succeeded | failed
            $table->string('status')->index();

            $table->text('prompt');
            $table->string('size');
            $table->string('quality');
            $table->json('reference_images');

            // Money: integer nanodollars. "cost" = what Pixelforge charges us, "charge" = what the user pays.
            $table->bigInteger('estimated_cost_nano');
            $table->bigInteger('estimated_charge_nano');
            $table->bigInteger('max_charge_nano'); // held against the user's balance while in flight
            $table->bigInteger('cost_nano')->nullable();
            $table->bigInteger('charge_nano')->nullable();

            $table->json('rates')->nullable(); // rates Pixelforge fixed at creation (USD per 1M tokens)
            $table->integer('input_text_tokens')->nullable();
            $table->integer('input_image_tokens')->nullable();
            $table->integer('output_tokens')->nullable();
            $table->integer('reasoning_tokens')->nullable();

            $table->text('image_url')->nullable();
            $table->timestamp('image_url_expires_at')->nullable();

            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            // Shown while we're waiting to (re)submit, e.g. "Pixelforge is busy, retrying in 5s".
            $table->string('notice')->nullable();
            $table->unsignedInteger('submit_attempts')->default(0);

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable()->index();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
