<?php

declare(strict_types=1);

namespace Fluxx\Observer;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Posts to the ingest API of a Fluxx Observer instance, authenticated by the ingest token
 * the observer issued for this application.
 */
final readonly class ObserverClient
{
    public function __construct(
        private HttpClientInterface $httpClient,
        #[Autowire('%fluxx.observer.url%')]
        private string $url,
        #[Autowire('%fluxx.observer.token%')]
        private string $token,
        #[Autowire('%fluxx.observer.timeout_seconds%')]
        private int $timeoutSeconds,
    ) {
    }

    public function isConfigured(): bool
    {
        return trim($this->url) !== '' && trim($this->token) !== '';
    }

    /**
     * @param array<string, mixed> $snapshot
     *
     * @throws ObserverPushException
     */
    public function pushHealth(array $snapshot): void
    {
        $this->post('/api/ingest/health', $snapshot, false);
    }

    /**
     * @param list<array<string, mixed>> $reports
     *
     * @throws ObserverPushException
     */
    public function pushReports(array $reports): ObserverIngestResult
    {
        $body = $this->post('/api/ingest/reports', ['reports' => $reports], true);
        $rejections = [];

        foreach (is_array($body['errors'] ?? null) ? $body['errors'] : [] as $error) {
            if (is_array($error) && is_int($error['index'] ?? null)) {
                $rejections[] = ['index' => $error['index'], 'error' => (string) ($error['error'] ?? '')];
            }
        }

        return new ObserverIngestResult(is_int($body['accepted'] ?? null) ? $body['accepted'] : 0, $rejections);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function post(string $path, array $body, bool $partialRejectionAllowed): array
    {
        try {
            $response = $this->httpClient->request('POST', rtrim(trim($this->url), '/') . $path, [
                'auth_bearer' => trim($this->token),
                'json' => $body,
                'timeout' => $this->timeoutSeconds,
            ]);
            $statusCode = $response->getStatusCode();

            // The observer answers 422 when it accepted part of a batch and refused the rest.
            if ($statusCode === 200 || ($statusCode === 422 && $partialRejectionAllowed)) {
                return $response->toArray(false);
            }
        } catch (TransportExceptionInterface|DecodingExceptionInterface $exception) {
            throw ObserverPushException::unreachable($path, $exception);
        }

        throw ObserverPushException::unexpectedStatus($path, $statusCode);
    }
}
