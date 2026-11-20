<?php

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

beforeEach(function () {
    fakePixelforge();
    Queue::fake();
    $this->user = User::factory()->create(['balance_nano' => 50_000_000_000]);
});

function generate(TestCase $test, User $user)
{
    return $test->actingAs($user)->post('/generations', ['prompt' => 'a cat', 'size' => '1024x1024', 'quality' => 'low']);
}

it('caps how many generations one user can have in flight', function () {
    config(['pixelforge.limits.max_in_flight_per_user' => 2]);
    Generation::factory()->for($this->user)->count(2)->create(); // pending

    generate($this, $this->user)->assertSessionHasErrors('limit');

    expect(Generation::count())->toBe(2);
    Queue::assertNothingPushed();
});

it('allows a new generation once an earlier one finishes', function () {
    config(['pixelforge.limits.max_in_flight_per_user' => 1]);
    Generation::factory()->for($this->user)->create(['status' => GenerationStatus::Succeeded]);

    generate($this, $this->user)->assertSessionHasNoErrors();
});

it('caps generations per user per UTC day', function () {
    config(['pixelforge.limits.max_generations_per_user_per_day' => 3]);
    Generation::factory()->for($this->user)->count(3)->create(['status' => GenerationStatus::Failed]);
    Generation::factory()->for($this->user)->create(['status' => GenerationStatus::Failed, 'created_at' => now()->subDay()]);

    generate($this, $this->user)->assertSessionHasErrors('limit');
});

it('caps the free credit balance', function () {
    config(['pixelforge.limits.max_balance_dollars' => 10]);
    $user = User::factory()->create();

    $this->actingAs($user)->post('/credits', ['amount' => 10])->assertSessionHasNoErrors();
    $this->actingAs($user)->post('/credits', ['amount' => 1])->assertSessionHasErrors('amount');

    expect($user->fresh()->balance_nano)->toBe(10_000_000_000);
});

it('drops non-https image URLs instead of rendering them', function () {
    $generation = Generation::factory()->submitted()->for($this->user)->create();

    sendWebhook($this, 'evt_x', 'generation.succeeded', succeededGeneration(['image_url' => 'javascript:alert(1)']))->assertNoContent();

    expect($generation->fresh())
        ->status->toBe(GenerationStatus::Succeeded)
        ->image_url->toBeNull();
});

it('never exposes other users\' generations', function () {
    $other = User::factory()->create();
    Generation::factory()->for($other)->create(['prompt' => 'secret prompt of another user']);

    $this->actingAs($this->user)->get('/generations')->assertDontSee('secret prompt of another user');
});

it('does not share sensitive user fields with the frontend', function () {
    $this->actingAs($this->user)->get('/generations')
        ->assertInertia(fn ($page) => $page
            ->missing('auth.user.password')
            ->missing('auth.user.remember_token')
            ->missing('auth.user.balance_nano'));
});
