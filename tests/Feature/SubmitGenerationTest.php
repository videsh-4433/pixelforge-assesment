<?php

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use App\Services\GenerationSync;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function runSubmit(Generation $generation): SubmitGeneration
{
    $job = (new SubmitGeneration($generation))->withFakeQueueInteractions();
    $job->handle(app(PixelforgeClient::class), app(GenerationSync::class));

    return $job;
}

it('submits with the generation uuid as idempotency key and our id in metadata', function () {
    fakePixelforge(['pixelforge.promptedit.dev/v1/generations' => Http::response(remoteGeneration(['id' => 'gen_NEW']), 202)]);
    $generation = Generation::factory()->create(['reference_images' => ['https://example.com/a.png']]);

    runSubmit($generation)->assertNotReleased();

    expect($generation->fresh())
        ->status->toBe(GenerationStatus::Queued)
        ->pixelforge_id->toBe('gen_NEW');

    Http::assertSent(fn (Request $r) => $r->url() === 'https://pixelforge.promptedit.dev/v1/generations'
        && $r->header('Idempotency-Key')[0] === $generation->uuid
        && $r->header('Authorization')[0] === 'Bearer pf_test_key'
        && $r['metadata']['app_generation_id'] === $generation->uuid
        && $r['reference_images'] === ['https://example.com/a.png']);
});

it('does not send reference_images when there are none', function () {
    fakePixelforge(['pixelforge.promptedit.dev/v1/generations' => Http::response(remoteGeneration(), 202)]);

    runSubmit(Generation::factory()->create());

    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && ! array_key_exists('reference_images', $r->data()));
});

it('retries overloads and rate limits, honouring Retry-After', function (string $code, int $status, int $retryAfter) {
    fakePixelforge(['pixelforge.promptedit.dev/v1/generations' => Http::response(
        ['error' => ['type' => 'x', 'code' => $code, 'message' => 'busy']], $status, ['Retry-After' => (string) $retryAfter],
    )]);
    $generation = Generation::factory()->create();

    $job = runSubmit($generation);

    $job->assertReleased();
    expect($generation->fresh())
        ->status->toBe(GenerationStatus::Pending)
        ->notice->toContain('retrying');
})->with([
    'overloaded' => ['overloaded', 503, 2],
    'rate limited' => ['rate_limit_exceeded', 429, 20],
    'concurrency' => ['concurrency_limit_exceeded', 429, 5],
]);

it('fails without charging when the prompt is rejected', function () {
    fakePixelforge(['pixelforge.promptedit.dev/v1/generations' => Http::response(
        ['error' => ['type' => 'invalid_request_error', 'code' => 'content_policy_violation', 'message' => 'Rejected']], 400,
    )]);
    $generation = Generation::factory()->create();

    runSubmit($generation)->assertNotReleased();

    expect($generation->fresh())
        ->status->toBe(GenerationStatus::Failed)
        ->error_code->toBe('content_policy_violation')
        ->charge_nano->toBeNull();
});

it('does nothing if the generation was already accepted', function () {
    fakePixelforge();
    $generation = Generation::factory()->submitted()->create();

    runSubmit($generation);

    Http::assertNothingSent();
});

it('handles an idempotent replay that is already finished', function () {
    fakePixelforge(['pixelforge.promptedit.dev/v1/generations' => Http::response(succeededGeneration(), 202, ['Idempotent-Replayed' => 'true'])]);
    $generation = Generation::factory()->create();

    runSubmit($generation);

    expect($generation->fresh())->status->toBe(GenerationStatus::Succeeded)->charge_nano->toBe(211_350_000);
});

it('reopens a generation we gave up on if Pixelforge reports it after all', function () {
    fakePixelforge();
    $generation = Generation::factory()->create();
    app(GenerationSync::class)->failBeforeSubmission($generation, 'unavailable', 'gave up');

    sendWebhook($this, 'evt_1', 'generation.succeeded', succeededGeneration(['metadata' => ['app_generation_id' => $generation->uuid]]));

    expect($generation->fresh())->status->toBe(GenerationStatus::Succeeded)->charge_nano->toBe(211_350_000);
});
