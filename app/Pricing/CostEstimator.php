<?php

namespace App\Pricing;

use App\Support\Money;

/**
 * Estimates a generation's price before it's submitted, following Pixelforge's token rules:
 *  - input text:  ~1 token per 4 Unicode characters; the real count is within +/-15%
 *  - input image: exactly 765 per reference image
 *  - output:      exact, fixed by quality and size
 *  - reasoning:   somewhere in a per-quality range
 *
 * "expected" uses the text estimate and the middle of the reasoning range; "max" is the worst case,
 * which is what we hold against the user's balance while the generation is in flight.
 */
final class CostEstimator
{
    public function __construct(
        private readonly PricingCatalog $catalog,
        private readonly Markup $markup,
    ) {}

    public function estimate(int $promptCharacters, string $size, string $quality, int $referenceImages): CostEstimate
    {
        $textEstimate = Money::ceilDiv(max(0, $promptCharacters), $this->catalog->charactersPerTextToken());
        $variance = $this->catalog->textTokenVariancePercent();
        $reasoning = $this->catalog->reasoningRange($quality);
        $images = $referenceImages * $this->catalog->tokensPerReferenceImage();
        $output = $this->catalog->outputTokens($quality, $size);

        $tokens = [
            'input_text_tokens' => [
                'min' => intdiv($textEstimate * (100 - $variance), 100),
                'expected' => $textEstimate,
                'max' => Money::ceilDiv($textEstimate * (100 + $variance), 100),
            ],
            'input_image_tokens' => ['min' => $images, 'expected' => $images, 'max' => $images],
            'output_tokens' => ['min' => $output, 'expected' => $output, 'max' => $output],
            'reasoning_tokens' => [
                'min' => $reasoning['min'],
                'expected' => intdiv($reasoning['min'] + $reasoning['max'], 2),
                'max' => $reasoning['max'],
            ],
        ];

        $cost = fn (string $bound) => $this->catalog->rates->costNano(array_map(fn ($t) => $t[$bound], $tokens));

        [$min, $expected, $max] = [$cost('min'), $cost('expected'), $cost('max')];

        return new CostEstimate(
            $tokens,
            $min, $expected, $max,
            $this->markup->chargeFor($min),
            $this->markup->chargeFor($expected),
            $this->markup->chargeFor($max),
        );
    }
}
