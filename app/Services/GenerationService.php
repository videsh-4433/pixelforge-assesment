<?php

namespace App\Services;

use App\Enums\GenerationStatus;
use App\Exceptions\GenerationLimitException;
use App\Exceptions\InsufficientCreditsException;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Pixelforge\PixelforgeClient;
use App\Pricing\CostEstimate;
use App\Pricing\CostEstimator;
use App\Pricing\Markup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GenerationService
{
    public function __construct(private readonly PixelforgeClient $client) {}

    public function estimate(int $promptCharacters, string $size, string $quality, int $referenceImages): CostEstimate
    {
        return (new CostEstimator($this->client->pricing(), Markup::fromConfig()))
            ->estimate($promptCharacters, $size, $quality, $referenceImages);
    }

    /**
     * Records the generation and queues it for submission. The worst-case price is held against the
     * user's balance until it finishes, so concurrent generations can never overdraw the account.
     *
     * @param  array{prompt: string, size: string, quality: string, reference_images?: list<string>}  $input
     *
     * @throws InsufficientCreditsException
     * @throws GenerationLimitException
     */
    public function create(User $user, array $input): Generation
    {
        $references = array_values($input['reference_images'] ?? []);
        $estimate = $this->estimate(mb_strlen($input['prompt']), $input['size'], $input['quality'], count($references));

        $generation = DB::transaction(function () use ($user, $input, $references, $estimate) {
            // Serialises a user's submissions so two tabs can't both spend the same credits
            // or slip past the usage limits.
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();

            $this->enforceLimits($locked);

            if ($locked->availableNano() < $estimate->maxChargeNano) {
                throw new InsufficientCreditsException($estimate->maxChargeNano, $locked->availableNano());
            }

            return $locked->generations()->create([
                'uuid' => (string) Str::uuid(),
                'status' => GenerationStatus::Pending,
                'prompt' => $input['prompt'],
                'size' => $input['size'],
                'quality' => $input['quality'],
                'reference_images' => $references,
                'estimated_cost_nano' => $estimate->expectedCostNano,
                'estimated_charge_nano' => $estimate->expectedChargeNano,
                'max_charge_nano' => $estimate->maxChargeNano,
            ]);
        });

        SubmitGeneration::dispatch($generation);

        return $generation;
    }

    /**
     * Per-user caps protecting the Pixelforge account limits that all users share.
     *
     * @throws GenerationLimitException
     */
    private function enforceLimits(User $user): void
    {
        $maxInFlight = (int) config('pixelforge.limits.max_in_flight_per_user');
        if ($user->generations()->inFlight()->count() >= $maxInFlight) {
            throw new GenerationLimitException(
                "You already have {$maxInFlight} generations in progress. Please wait for one to finish.",
            );
        }

        $maxPerDay = (int) config('pixelforge.limits.max_generations_per_user_per_day');
        $today = $user->generations()->where('created_at', '>=', now('UTC')->startOfDay())->count();
        if ($today >= $maxPerDay) {
            throw new GenerationLimitException(
                "You've reached the limit of {$maxPerDay} generations per day. It resets at midnight UTC.",
            );
        }
    }
}
