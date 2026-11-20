<?php

use App\Pricing\CostEstimator;
use App\Pricing\Markup;
use App\Pricing\PricingCatalog;
use App\Pricing\TokenRates;
use App\Support\Money;

it('converts between decimal strings and nanodollars exactly', function () {
    expect(Money::decimalToNano('0.046125000'))->toBe(46_125_000)
        ->and(Money::decimalToNano('12.50'))->toBe(12_500_000_000)
        ->and(Money::decimalToNano('0.000000001'))->toBe(1)
        ->and(Money::nanoToDecimal(169_080_000))->toBe('0.169080000')
        ->and(Money::nanoToDecimal(-5))->toBe('-0.000000005');
});

it('rejects amounts it cannot represent exactly', function () {
    Money::decimalToNano('0.0000000001');
})->throws(InvalidArgumentException::class);

it('computes the provider cost exactly, matching the docs usage example', function () {
    $rates = TokenRates::fromApi(pixelforgePricing()['rates']);

    $cost = $rates->costNano(['input_text_tokens' => 41, 'input_image_tokens' => 0, 'output_tokens' => 3300, 'reasoning_tokens' => 2950]);

    expect(Money::nanoToDecimal($cost))->toBe('0.169080000');
});

it('bills at the rates fixed on the generation, not the current price list', function () {
    $old = TokenRates::fromApi(['input_text_tokens' => '1.00', 'input_image_tokens' => '1.00', 'output_tokens' => '1.00', 'reasoning_tokens' => '1.00']);

    expect($old->costNano(['input_text_tokens' => 1, 'input_image_tokens' => 0, 'output_tokens' => 0, 'reasoning_tokens' => 0]))->toBe(1_000); // $0.000001
});

it('adds a 25% markup without losing precision', function () {
    expect((new Markup(25))->chargeFor(169_080_000))->toBe(211_350_000)
        ->and((new Markup(25))->chargeFor(1))->toBe(2) // 1.25 nano rounds up: never undercharge
        ->and((new Markup(25))->chargeFor(0))->toBe(0);
});

it('estimates the maximum cost exactly as the docs worked example', function () {
    // high, 1536x1024, 400-character prompt, 2 reference images => max $0.373875
    $estimate = (new CostEstimator(PricingCatalog::fromApi(pixelforgePricing()), new Markup(25)))
        ->estimate(400, '1536x1024', 'high', 2);

    expect($estimate->maxCostNano)->toBe(373_875_000)
        ->and($estimate->tokens['input_text_tokens'])->toBe(['min' => 85, 'expected' => 100, 'max' => 115])
        ->and($estimate->tokens['reasoning_tokens'])->toBe(['min' => 1000, 'expected' => 4500, 'max' => 8000])
        ->and($estimate->maxChargeNano)->toBe(467_343_750);
});
