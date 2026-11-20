<?php

namespace App\Pricing;

use App\Support\Money;

/**
 * Pixelforge's per-token rates, published as "USD per 1M tokens" decimal strings.
 *
 * Internally each rate is held as nanodollars per 1M tokens, so
 *   cost_nano = tokens * rate_nano_per_million / 1,000,000
 * which is exact for every published rate (e.g. $12.50/1M = 12,500 nano per token).
 */
final class TokenRates
{
    public const TYPES = ['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'];

    /** @param array<string, int> $nanoPerMillion */
    private function __construct(private readonly array $nanoPerMillion) {}

    /** @param array<string, string> $rates e.g. ['output_tokens' => '40.00', ...] */
    public static function fromApi(array $rates): self
    {
        $parsed = [];
        foreach (self::TYPES as $type) {
            if (! isset($rates[$type])) {
                throw new \InvalidArgumentException("Missing rate for {$type}");
            }
            $parsed[$type] = Money::decimalToNano((string) $rates[$type]);
        }

        return new self($parsed);
    }

    /**
     * Exact cost in nanodollars. Pixelforge never rounds; if a rate ever produced a fraction of a
     * nanodollar we round *up*, so we can never undercharge relative to the provider.
     *
     * @param  array<string, int>  $tokens  keyed by TYPES
     */
    public function costNano(array $tokens): int
    {
        $total = 0;
        foreach (self::TYPES as $type) {
            $total += (int) ($tokens[$type] ?? 0) * $this->nanoPerMillion[$type];
        }

        return Money::ceilDiv($total, 1_000_000);
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return array_map(fn (int $nano) => Money::nanoToDecimal($nano), $this->nanoPerMillion);
    }
}
