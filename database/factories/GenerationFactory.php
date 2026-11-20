<?php

namespace Database\Factories;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Generation> */
class GenerationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'user_id' => User::factory(),
            'status' => GenerationStatus::Pending,
            'prompt' => 'A lighthouse',
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => [],
            'estimated_cost_nano' => 58_900_000,
            'estimated_charge_nano' => 73_625_000,
            'max_charge_nano' => 100_000_000,
        ];
    }

    public function submitted(string $pixelforgeId = 'gen_TEST1'): static
    {
        return $this->state(['status' => GenerationStatus::Queued, 'pixelforge_id' => $pixelforgeId, 'submitted_at' => now()]);
    }
}
