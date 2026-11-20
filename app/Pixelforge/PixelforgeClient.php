<?php

namespace App\Pixelforge;

use App\Pricing\PricingCatalog;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class PixelforgeClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $apiKey,
        private readonly int $timeout = 15,
    ) {}

    /**
     * POST /v1/generations. The idempotency key makes retries safe: re-sending the same request returns
     * the original generation instead of creating (and billing) a second one.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed> the generation object
     */
    public function createGeneration(array $params, string $idempotencyKey): array
    {
        return $this->send(fn (PendingRequest $http) => $http
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->post('generations', $params));
    }

    /** @return array<string, mixed> */
    public function getGeneration(string $id): array
    {
        return $this->send(fn (PendingRequest $http) => $http->get('generations/'.rawurlencode($id)));
    }

    /** @return array<string, mixed> */
    public function usage(string $startDate, string $endDate): array
    {
        return $this->send(fn (PendingRequest $http) => $http->get('usage', [
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]));
    }

    /** Published pricing; public and rarely changes, so cached for an hour. */
    public function pricing(): PricingCatalog
    {
        $data = Cache::remember('pixelforge.pricing', now()->addHour(), fn () => $this->send(
            fn (PendingRequest $http) => $http->get('pricing'),
        ));

        return PricingCatalog::fromApi($data);
    }

    /**
     * @param  callable(PendingRequest): Response  $request
     * @return array<string, mixed>
     */
    private function send(callable $request): array
    {
        $http = Http::baseUrl(rtrim($this->baseUrl, '/').'/')
            ->withToken((string) $this->apiKey)
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeout)
            ->connectTimeout(5);

        try {
            $response = $request($http);
        } catch (ConnectionException $e) {
            throw PixelforgeException::connection($e->getMessage());
        }

        if ($response->successful()) {
            return $response->json();
        }

        throw new PixelforgeException(
            $response->json('error.message') ?? "Pixelforge returned HTTP {$response->status()}",
            $response->json('error.code'),
            $response->status(),
            is_numeric($response->header('Retry-After')) ? (int) $response->header('Retry-After') : null,
        );
    }
}
