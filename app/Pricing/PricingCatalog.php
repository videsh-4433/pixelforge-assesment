<?php

namespace App\Pricing;

/** Pixelforge's published pricing (GET /v1/pricing): token rates plus how many tokens each request uses. */
final class PricingCatalog
{
    /** @param array<string, mixed> $tokens the "tokens" section of /v1/pricing */
    public function __construct(
        public readonly TokenRates $rates,
        private readonly array $tokens,
    ) {}

    /** @param array<string, mixed> $response */
    public static function fromApi(array $response): self
    {
        return new self(TokenRates::fromApi($response['rates']), $response['tokens']);
    }

    /** @return list<string> */
    public function sizes(): array
    {
        return array_keys($this->tokens['output']['medium']);
    }

    /** @return list<string> */
    public function qualities(): array
    {
        return array_keys($this->tokens['output']);
    }

    public function charactersPerTextToken(): int
    {
        return (int) $this->tokens['characters_per_text_token'];
    }

    /** Allowed deviation of the real text token count from the estimate, in whole percent (0.15 -> 15). */
    public function textTokenVariancePercent(): int
    {
        return (int) round(((float) $this->tokens['text_token_variance']) * 100);
    }

    public function tokensPerReferenceImage(): int
    {
        return (int) $this->tokens['per_reference_image'];
    }

    public function outputTokens(string $quality, string $size): int
    {
        return (int) $this->tokens['output'][$quality][$size];
    }

    /** @return array{min: int, max: int} */
    public function reasoningRange(string $quality): array
    {
        return [
            'min' => (int) $this->tokens['reasoning'][$quality]['min'],
            'max' => (int) $this->tokens['reasoning'][$quality]['max'],
        ];
    }
}
