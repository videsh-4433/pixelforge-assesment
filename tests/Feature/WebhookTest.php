<?php

use App\Enums\GenerationStatus;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;

beforeEach(function () {
    fakePixelforge();
    $this->user = User::factory()->create(['balance_nano' => 5_000_000_000]);
    $this->generation = Generation::factory()->submitted()->for($this->user)->create();
});

it('rejects webhooks with an invalid signature', function () {
    sendWebhook($this, 'evt_1', 'generation.succeeded', succeededGeneration(), secret: 'whsec_wrong')->assertStatus(400);

    expect($this->generation->fresh()->status)->toBe(GenerationStatus::Queued);
});

it('rejects webhooks with a stale timestamp', function () {
    sendWebhook($this, 'evt_1', 'generation.succeeded', succeededGeneration(), timestamp: time() - 600)->assertStatus(400);
});

it('records the exact cost and charge when a generation succeeds', function () {
    sendWebhook($this, 'evt_1', 'generation.succeeded', succeededGeneration())->assertNoContent();

    $generation = $this->generation->fresh();
    expect($generation->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->cost_nano)->toBe(169_080_000)      // $0.169080000 from Pixelforge
        ->and($generation->charge_nano)->toBe(211_350_000)    // + 25%
        ->and($generation->image_url)->not->toBeNull()
        ->and($generation->completed_at->toIso8601String())->toBe('2026-11-20T10:00:20+00:00')
        ->and($this->user->fresh()->balance_nano)->toBe(5_000_000_000 - 211_350_000)
        ->and(CreditTransaction::where('generation_id', $generation->id)->sole()->amount_nano)->toBe(-211_350_000);
});

it('charges once when the same event is delivered twice', function () {
    sendWebhook($this, 'evt_1', 'generation.succeeded', succeededGeneration())->assertNoContent();
    sendWebhook($this, 'evt_1', 'generation.succeeded', succeededGeneration())->assertNoContent();

    expect(CreditTransaction::count())->toBe(1)
        ->and($this->user->fresh()->balance_nano)->toBe(5_000_000_000 - 211_350_000);
});

it('charges once even if two different succeeded events arrive for one generation', function () {
    sendWebhook($this, 'evt_1', 'generation.succeeded', succeededGeneration())->assertNoContent();
    sendWebhook($this, 'evt_2', 'generation.succeeded', succeededGeneration())->assertNoContent();

    expect(CreditTransaction::count())->toBe(1);
});

it('never moves a finished generation backwards when events arrive out of order', function () {
    sendWebhook($this, 'evt_2', 'generation.succeeded', succeededGeneration())->assertNoContent();
    sendWebhook($this, 'evt_1', 'generation.processing', remoteGeneration(['status' => 'processing']))->assertNoContent();

    expect($this->generation->fresh()->status)->toBe(GenerationStatus::Succeeded);
});

it('ignores a late failure event for a generation that already succeeded', function () {
    sendWebhook($this, 'evt_2', 'generation.succeeded', succeededGeneration())->assertNoContent();
    sendWebhook($this, 'evt_3', 'generation.failed', remoteGeneration(['status' => 'failed', 'error' => ['code' => 'internal_error', 'message' => 'x']]));

    expect($this->generation->fresh()->status)->toBe(GenerationStatus::Succeeded)
        ->and(CreditTransaction::count())->toBe(1);
});

it('moves through processing', function () {
    sendWebhook($this, 'evt_1', 'generation.processing', remoteGeneration(['status' => 'processing', 'started_at' => '2026-11-20T10:00:02Z']));

    expect($this->generation->fresh()->status)->toBe(GenerationStatus::Processing)
        ->and($this->generation->fresh()->started_at)->not->toBeNull();
});

it('does not charge for failed generations and releases the hold', function () {
    expect($this->user->fresh()->availableNano())->toBe(5_000_000_000 - 100_000_000);

    sendWebhook($this, 'evt_1', 'generation.failed', remoteGeneration([
        'status' => 'failed',
        'error' => ['code' => 'generation_failed', 'message' => 'The model could not produce an image.'],
        'completed_at' => '2026-11-20T10:00:20Z',
    ]))->assertNoContent();

    $generation = $this->generation->fresh();
    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->error_code)->toBe('generation_failed')
        ->and($generation->charge_nano)->toBeNull()
        ->and(CreditTransaction::count())->toBe(0)
        ->and($this->user->fresh()->availableNano())->toBe(5_000_000_000);
});

it('handles a webhook that arrives before the POST response, matching by metadata', function () {
    $pending = Generation::factory()->for($this->user)->create(); // no pixelforge_id yet

    sendWebhook($this, 'evt_9', 'generation.succeeded', succeededGeneration([
        'id' => 'gen_EARLY',
        'metadata' => ['app_generation_id' => $pending->uuid, 'user_id' => (string) $this->user->id],
    ]))->assertNoContent();

    expect($pending->fresh()->status)->toBe(GenerationStatus::Succeeded)
        ->and($pending->fresh()->pixelforge_id)->toBe('gen_EARLY');
});

it('acknowledges ping events', function () {
    sendWebhook($this, 'evt_ping', 'ping', [])->assertNoContent();
});
