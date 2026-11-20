<?php

namespace App\Pricing;

use App\Support\Money;

/** Up-front price for a generation. Text and reasoning tokens are only known afterwards, so this is a range. */
final class CostEstimate
{
    /**
     * @param  array<string, array{min: int, expected: int, max: int}>  $tokens
     */
    public function __construct(
        public readonly array $tokens,
        public readonly int $minCostNano,
        public readonly int $expectedCostNano,
        public readonly int $maxCostNano,
        public readonly int $minChargeNano,
        public readonly int $expectedChargeNano,
        public readonly int $maxChargeNano,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'tokens' => $this->tokens,
            'charge' => [
                'min' => Money::nanoToDecimal($this->minChargeNano),
                'expected' => Money::nanoToDecimal($this->expectedChargeNano),
                'max' => Money::nanoToDecimal($this->maxChargeNano),
            ],
        ];
    }
}
