<?php

namespace App\Console\Commands;

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeException;
use App\Services\GenerationSync;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Safety net for lost webhooks: Pixelforge only guarantees "at least once, eventually maybe",
 * so anything in flight that hasn't heard from Pixelforge recently is polled.
 */
#[Signature('generations:sync {--limit=20 : Max generations to poll per run (each poll uses rate limit)}')]
#[Description('Poll Pixelforge for in-flight generations whose webhooks may have been lost')]
class SyncGenerations extends Command
{
    public function handle(PixelforgeClient $client, GenerationSync $sync): int
    {
        $stale = Generation::inFlight()
            ->whereNotNull('pixelforge_id')
            ->where('updated_at', '<', now()->subSeconds(45))
            ->where(fn ($q) => $q->whereNull('last_synced_at')->orWhere('last_synced_at', '<', now()->subSeconds(45)))
            ->orderBy('last_synced_at')
            ->limit((int) $this->option('limit'))
            ->get();

        foreach ($stale as $generation) {
            try {
                $sync->apply($generation, $client->getGeneration($generation->pixelforge_id));
            } catch (PixelforgeException $e) {
                $this->warn("Generation {$generation->id}: {$e->getMessage()}");
                if ($e->errorCode === 'rate_limit_exceeded') {
                    break; // try again next minute
                }
            }
        }

        // Pending generations whose submit job vanished (e.g. worker crashed). Re-dispatching is safe:
        // the idempotency key means Pixelforge returns the existing generation if it was created.
        Generation::where('status', GenerationStatus::Pending)
            ->where('updated_at', '<', now()->subMinutes(3))
            ->oldest('updated_at')
            ->limit((int) $this->option('limit'))
            ->get()
            ->each(fn (Generation $g) => SubmitGeneration::dispatch($g));

        $this->info("Polled {$stale->count()} generation(s).");

        return self::SUCCESS;
    }
}
