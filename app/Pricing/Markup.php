<?php

namespace App\Pricing;

use App\Support\Money;

/** What we charge users: Pixelforge's cost plus a percentage markup. */
final class Markup
{
    public function __construct(private readonly int $percent) {}

    public static function fromConfig(): self
    {
        return new self((int) config('pixelforge.markup_percent'));
    }

    /**
     * Applied once to the generation's total cost (not per token type), rounding up to the next
     * nanodollar. With the published rates every result is already a whole nanodollar.
     */
    public function chargeFor(int $costNano): int
    {
        return Money::ceilDiv($costNano * (100 + $this->percent), 100);
    }
}
