<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type'); // purchase | generation_charge
            $table->bigInteger('amount_nano'); // signed: + adds credits, - spends them
            $table->foreignId('generation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description');
            $table->timestamps();

            // Database-level guarantee that a generation can never be charged twice.
            $table->unique(['generation_id', 'type']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_transactions');
    }
};
