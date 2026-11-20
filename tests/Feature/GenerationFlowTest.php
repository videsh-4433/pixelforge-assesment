<?php

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    fakePixelforge();
    Queue::fake();
});

it('shows the generations page', function () {
    $this->actingAs(User::factory()->create())->get('/generations')->assertOk();
});

it('returns a live estimate', function () {
    $user = User::factory()->create(['balance_nano' => 1_000_000_000]);

    $this->actingAs($user)
        ->getJson('/generations/estimate?characters=400&size=1536x1024&quality=high&references=2')
        ->assertOk()
        ->assertJsonPath('charge.max', '0.467343750')
        ->assertJsonPath('affordable', true);
});

it('creates a pending generation, holds its max price and queues submission', function () {
    $user = User::factory()->create(['balance_nano' => 1_000_000_000]);

    $this->actingAs($user)->post('/generations', [
        'prompt' => 'A lighthouse',
        'size' => '1024x1024',
        'quality' => 'medium',
        'reference_images' => ['', 'https://example.com/a.png'],
    ])->assertRedirect('/generations');

    $generation = Generation::sole();
    expect($generation->status)->toBe(GenerationStatus::Pending)
        ->and($generation->reference_images)->toBe(['https://example.com/a.png'])
        ->and($user->fresh()->availableNano())->toBe(1_000_000_000 - $generation->max_charge_nano)
        ->and($user->fresh()->balance_nano)->toBe(1_000_000_000); // nothing charged yet

    Queue::assertPushed(SubmitGeneration::class);
});

it('blocks generating when the user cannot afford the worst case', function () {
    $user = User::factory()->create(['balance_nano' => 10_000_000]); // $0.01

    $this->actingAs($user)->post('/generations', ['prompt' => 'x', 'size' => '1024x1024', 'quality' => 'high'])
        ->assertSessionHasErrors('credits');

    expect(Generation::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('validates input against Pixelforge limits', function () {
    $user = User::factory()->create(['balance_nano' => 1_000_000_000]);

    $this->actingAs($user)->post('/generations', [
        'prompt' => str_repeat('a', 4001),
        'size' => '512x512',
        'quality' => 'ultra',
        'reference_images' => ['ftp://x', 'https://a.co/1', 'https://a.co/2', 'https://a.co/3', 'https://a.co/4'],
    ])->assertSessionHasErrors(['prompt', 'size', 'quality', 'reference_images', 'reference_images.0']);
});

it('buys credits', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/credits', ['amount' => 5])->assertRedirect();
    $this->actingAs($user)->post('/credits', ['amount' => 7])->assertSessionHasErrors('amount');

    expect($user->fresh()->balance_nano)->toBe(5_000_000_000);
});

it('polls stale in-flight generations as a webhook fallback', function () {
    Http::fake(['pixelforge.promptedit.dev/v1/generations/gen_TEST1' => Http::response(succeededGeneration())]);
    $user = User::factory()->create(['balance_nano' => 1_000_000_000]);
    $generation = Generation::factory()->submitted()->for($user)->create(['updated_at' => now()->subMinutes(5)]);

    $this->artisan('generations:sync')->assertSuccessful();

    expect($generation->fresh())->status->toBe(GenerationStatus::Succeeded)->charge_nano->toBe(211_350_000);
});
