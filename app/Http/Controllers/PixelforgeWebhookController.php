<?php

namespace App\Http\Controllers;

use App\Models\Generation;
use App\Models\WebhookEvent;
use App\Pixelforge\WebhookSignature;
use App\Services\GenerationSync;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PixelforgeWebhookController extends Controller
{
    public function __invoke(Request $request, WebhookSignature $signature, GenerationSync $sync): Response
    {
        $payload = $request->getContent(); // raw bytes: the signature covers the body exactly as sent

        if (! $signature->isValid($payload, $request->header('Pixelforge-Signature'))) {
            Log::warning('Rejected Pixelforge webhook with invalid signature', ['event' => $request->header('Pixelforge-Event-Id')]);

            return response('Invalid signature', 400);
        }

        $event = json_decode($payload, true);

        if (! is_array($event) || ! isset($event['id'], $event['type'])) {
            return response('Malformed event', 400);
        }

        if ($event['type'] === 'ping') {
            return response()->noContent();
        }

        $remote = $event['data']['object'] ?? [];

        // Record + apply atomically. If applying throws, the event row rolls back too, we answer 500,
        // and Pixelforge's retry is processed normally instead of being mistaken for a duplicate.
        DB::transaction(function () use ($event, $remote, $request, $sync) {
            $isNew = WebhookEvent::insertOrIgnore([
                'event_id' => $event['id'],
                'type' => $event['type'],
                'pixelforge_generation_id' => $remote['id'] ?? null,
                'delivery_attempt' => (int) $request->header('Pixelforge-Delivery-Attempt') ?: null,
                'payload' => json_encode($event),
                'created_at' => now(),
                'updated_at' => now(),
            ]) === 1;

            if (! $isNew) {
                return; // already processed this event id
            }

            $generation = $this->findGeneration($remote);

            if (! $generation) {
                Log::warning('Pixelforge webhook for unknown generation', ['event' => $event['id'], 'generation' => $remote['id'] ?? null]);

                return;
            }

            $sync->apply($generation, $remote);
        });

        return response()->noContent();
    }

    /**
     * Match by our own id in metadata first: the webhook can arrive before the POST /generations
     * response, i.e. before we've stored Pixelforge's gen_ id.
     *
     * @param  array<string, mixed>  $remote
     */
    private function findGeneration(array $remote): ?Generation
    {
        $uuid = $remote['metadata']['app_generation_id'] ?? null;

        if (is_string($uuid) && $uuid !== '') {
            $generation = Generation::where('uuid', $uuid)->first();
            if ($generation) {
                return $generation;
            }
        }

        return isset($remote['id']) ? Generation::where('pixelforge_id', $remote['id'])->first() : null;
    }
}
