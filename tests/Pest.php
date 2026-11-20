<?php

use App\Pixelforge\WebhookSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/** The real GET /v1/pricing response. */
function pixelforgePricing(): array
{
    return [
        'object' => 'pricing',
        'currency' => 'usd',
        'unit' => 'per_1m_tokens',
        'rates' => ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'],
        'tokens' => [
            'characters_per_text_token' => 4,
            'text_token_variance' => 0.15,
            'per_reference_image' => 765,
            'output' => [
                'low' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420],
                'medium' => ['1024x1024' => 1100, '1536x1024' => 1650, '1024x1536' => 1650],
                'high' => ['1024x1024' => 4300, '1536x1024' => 6450, '1024x1536' => 6450],
            ],
            'reasoning' => [
                'low' => ['min' => 0, 'max' => 500],
                'medium' => ['min' => 200, 'max' => 2000],
                'high' => ['min' => 1000, 'max' => 8000],
            ],
        ],
    ];
}

/** A Pixelforge generation object, like the docs' examples. */
function remoteGeneration(array $overrides = []): array
{
    return array_replace([
        'id' => 'gen_TEST1',
        'object' => 'generation',
        'status' => 'queued',
        'prompt' => 'A lighthouse',
        'size' => '1024x1024',
        'quality' => 'medium',
        'reference_images' => [],
        'metadata' => [],
        'image_url' => null,
        'image_url_expires_at' => null,
        'usage' => null,
        'rates' => pixelforgePricing()['rates'],
        'error' => null,
        'created_at' => '2026-11-20T10:00:00Z',
        'started_at' => null,
        'completed_at' => null,
    ], $overrides);
}

function succeededGeneration(array $overrides = []): array
{
    return remoteGeneration(array_replace([
        'status' => 'succeeded',
        'image_url' => 'https://pixelforge.promptedit.dev/images/gen_TEST1.png?sig=x',
        'image_url_expires_at' => '2026-11-21T10:00:20Z',
        // 41 text + 3300 output + 2950 reasoning = $0.169080000 (the /v1/usage example in the docs)
        'usage' => ['input_text_tokens' => 41, 'input_image_tokens' => 0, 'output_tokens' => 3300, 'reasoning_tokens' => 2950, 'total_tokens' => 6291],
        'started_at' => '2026-11-20T10:00:02Z',
        'completed_at' => '2026-11-20T10:00:20Z',
    ], $overrides));
}

function fakePixelforge(array $extra = []): void
{
    Http::preventStrayRequests();
    Http::fake([
        'pixelforge.promptedit.dev/v1/pricing' => Http::response(pixelforgePricing()),
        ...$extra,
    ]);
}

/** Send a correctly signed webhook to the app, as Pixelforge would. */
function sendWebhook(TestCase $test, string $eventId, string $type, array $generation, ?int $timestamp = null, ?string $secret = null)
{
    $body = json_encode([
        'id' => $eventId,
        'object' => 'event',
        'type' => $type,
        'created_at' => '2026-11-20T10:00:20Z',
        'data' => ['object' => $generation],
    ]);

    $signer = new WebhookSignature($secret ?? config('pixelforge.webhook_secret'));

    return $test->call('POST', '/api/webhooks/pixelforge', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_PIXELFORGE_SIGNATURE' => $signer->header($body, $timestamp ?? time()),
        'HTTP_PIXELFORGE_EVENT_ID' => $eventId,
        'HTTP_PIXELFORGE_EVENT_TYPE' => $type,
        'HTTP_PIXELFORGE_DELIVERY_ATTEMPT' => '1',
    ], $body);
}
