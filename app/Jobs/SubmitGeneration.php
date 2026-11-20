<?php

namespace App\Jobs;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeException;
use App\Services\GenerationSync;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends a generation to Pixelforge. Rate limits, concurrency limits, overloads and network errors are
 * retried with backoff (honouring Retry-After). Every attempt reuses the generation's UUID as the
 * Idempotency-Key, so a retry after a timeout can never create or bill a second generation.
 */
class SubmitGeneration implements ShouldQueue
{
    use Queueable;

    /** Retries are bounded by retryUntil() instead of a fixed count. */
    public int $tries = 0;

    /** Unexpected exceptions (bugs, DB down) still fail the job eventually. */
    public int $maxExceptions = 3;

    public function __construct(public Generation $generation) {}

    public function retryUntil(): DateTimeInterface
    {
        return $this->deadline();
    }

    public function handle(PixelforgeClient $client, GenerationSync $sync): void
    {
        $generation = $this->generation->fresh();

        if (! $generation || $generation->status !== GenerationStatus::Pending) {
            return; // already accepted (e.g. a webhook beat us to it) or finished
        }

        $generation->increment('submit_attempts');

        try {
            $remote = $client->createGeneration($generation->toPixelforgeRequest(), $generation->uuid);
        } catch (PixelforgeException $e) {
            $this->handleError($generation, $e, $sync);

            return;
        }

        $generation->forceFill(['submitted_at' => now()])->save();
        $sync->apply($generation, $remote);
    }

    public function failed(?Throwable $e): void
    {
        app(GenerationSync::class)->failBeforeSubmission(
            $this->generation,
            'submission_failed',
            'We could not submit this generation. You were not charged.',
        );
    }

    private function handleError(Generation $generation, PixelforgeException $e, GenerationSync $sync): void
    {
        Log::warning('Pixelforge submission error', [
            'generation_id' => $generation->id,
            'code' => $e->errorCode,
            'status' => $e->status,
            'attempt' => $generation->submit_attempts,
        ]);

        if (! $e->isRetryable()) {
            $sync->failBeforeSubmission($generation, $e->errorCode ?? 'rejected', $this->rejectionMessage($e));

            return;
        }

        $delay = $this->backoffSeconds($generation->submit_attempts, $e->retryAfter);

        if (now()->addSeconds($delay)->greaterThan($this->deadline())) {
            $sync->failBeforeSubmission($generation, $e->errorCode ?? 'unavailable',
                'Pixelforge stayed busy, so we gave up. You were not charged — please try again.');

            return;
        }

        // Only annotate while still pending: after a timeout, a webhook may already have advanced it.
        Generation::whereKey($generation->id)
            ->where('status', GenerationStatus::Pending)
            ->update(['notice' => $this->retryNotice($e, $delay)]);

        $this->release($delay);
    }

    /** Exponential backoff (2, 4, 8 … capped at 60s) with jitter, never sooner than Retry-After. */
    private function backoffSeconds(int $attempt, ?int $retryAfter): int
    {
        $exponential = min(60, 2 ** min($attempt, 6));

        return max($retryAfter ?? 0, $exponential) + random_int(0, 2);
    }

    private function retryNotice(PixelforgeException $e, int $delay): string
    {
        $reason = match ($e->errorCode) {
            'concurrency_limit_exceeded' => 'Waiting for a free slot',
            'rate_limit_exceeded' => 'Rate limited',
            'overloaded' => 'Pixelforge is overloaded',
            default => 'Pixelforge is unreachable',
        };

        return "{$reason} — retrying in {$delay}s";
    }

    private function rejectionMessage(PixelforgeException $e): string
    {
        return match ($e->errorCode) {
            'content_policy_violation' => 'Pixelforge rejected this prompt under its content policy. You were not charged.',
            'daily_limit_exceeded' => 'The daily generation limit has been reached. Please try again after midnight UTC. You were not charged.',
            'invalid_api_key' => 'Image generation is temporarily unavailable (configuration problem). You were not charged.',
            default => "Pixelforge rejected the request: {$e->getMessage()} You were not charged.",
        };
    }

    private function deadline(): DateTimeInterface
    {
        return $this->generation->created_at->copy()->addMinutes(config('pixelforge.submit_give_up_after_minutes'));
    }
}
