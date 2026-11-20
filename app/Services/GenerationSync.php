<?php

namespace App\Services;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Pricing\Markup;
use App\Pricing\TokenRates;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Applies a Pixelforge generation object (from a POST/GET response or a webhook) to our record.
 *
 * Every source of truth goes through this one method, under a row lock, so:
 *  - status only ever moves forward (a late "processing" event can't undo "succeeded");
 *  - once succeeded/failed, nothing changes, so the user is charged exactly once;
 *  - failed generations are never charged.
 */
class GenerationSync
{
    public function __construct(
        private readonly CreditLedger $ledger,
        private readonly Markup $markup,
    ) {}

    /** @param array<string, mixed> $remote */
    public function apply(Generation $generation, array $remote): Generation
    {
        return DB::transaction(function () use ($generation, $remote) {
            /** @var Generation $locked */
            $locked = Generation::whereKey($generation->id)->lockForUpdate()->firstOrFail();

            // We gave up submitting (e.g. repeated timeouts) but Pixelforge did accept it after all.
            // Reopen it so the user gets the image and our records match what Pixelforge bills us.
            if ($locked->status === GenerationStatus::Failed && $locked->pixelforge_id === null && isset($remote['id'])) {
                $locked->fill(['status' => GenerationStatus::Pending, 'error_code' => null, 'error_message' => null, 'completed_at' => null]);
            }

            $locked->pixelforge_id ??= $remote['id'] ?? null;
            $locked->rates ??= $remote['rates'] ?? null;
            $locked->last_synced_at = now();

            $next = GenerationStatus::tryFrom($remote['status'] ?? '');

            if ($next && $locked->status->canTransitionTo($next)) {
                $this->transition($locked, $next, $remote);
            }

            $locked->save();

            return $locked;
        });
    }

    /** Fail a generation that Pixelforge never accepted (rejected or gave up retrying). Free. */
    public function failBeforeSubmission(Generation $generation, string $code, string $message): Generation
    {
        return DB::transaction(function () use ($generation, $code, $message) {
            $locked = Generation::whereKey($generation->id)->lockForUpdate()->firstOrFail();

            if ($locked->status->isFinal()) {
                return $locked;
            }

            $locked->fill([
                'status' => GenerationStatus::Failed,
                'error_code' => $code,
                'error_message' => $message,
                'notice' => null,
                'completed_at' => now(),
            ])->save();

            return $locked;
        });
    }

    /** @param array<string, mixed> $remote */
    private function transition(Generation $generation, GenerationStatus $next, array $remote): void
    {
        $generation->status = $next;
        $generation->notice = null;
        $generation->started_at ??= $this->time($remote['started_at'] ?? null);

        match ($next) {
            GenerationStatus::Succeeded => $this->succeed($generation, $remote),
            GenerationStatus::Failed => $generation->fill([
                'error_code' => $remote['error']['code'] ?? 'generation_failed',
                'error_message' => $remote['error']['message'] ?? 'The generation failed.',
                'completed_at' => $this->time($remote['completed_at'] ?? null) ?? now(),
            ]),
            default => null,
        };
    }

    /** @param array<string, mixed> $remote */
    private function succeed(Generation $generation, array $remote): void
    {
        $usage = $remote['usage'];
        // Bill at the rates Pixelforge fixed when the generation was created, not today's price list.
        $cost = TokenRates::fromApi($remote['rates'])->costNano($usage);

        $generation->fill([
            'rates' => $remote['rates'],
            'input_text_tokens' => $usage['input_text_tokens'],
            'input_image_tokens' => $usage['input_image_tokens'],
            'output_tokens' => $usage['output_tokens'],
            'reasoning_tokens' => $usage['reasoning_tokens'],
            'cost_nano' => $cost,
            'charge_nano' => $this->markup->chargeFor($cost),
            'image_url' => $this->safeUrl($remote['image_url'] ?? null),
            'image_url_expires_at' => $this->time($remote['image_url_expires_at'] ?? null),
            'completed_at' => $this->time($remote['completed_at'] ?? null) ?? now(),
        ]);

        $generation->save();
        $this->ledger->chargeGeneration($generation);
    }

    /** The URL ends up in an <img src>/<a href>, so only allow https (never javascript: etc.). */
    private function safeUrl(?string $url): ?string
    {
        return is_string($url) && str_starts_with(strtolower($url), 'https://') ? $url : null;
    }

    private function time(?string $value): ?Carbon
    {
        return $value ? Carbon::parse($value)->utc() : null;
    }
}
