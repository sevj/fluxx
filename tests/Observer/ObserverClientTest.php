<?php

declare(strict_types=1);

namespace Fluxx\Tests\Observer;

use Fluxx\Observer\ObserverClient;
use Fluxx\Observer\ObserverPushException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ObserverClientTest extends TestCase
{
    #[Test]
    public function it_is_disabled_without_url_or_token(): void
    {
        self::assertFalse((new ObserverClient(new MockHttpClient(), '', 'token', 5))->isConfigured());
        self::assertFalse((new ObserverClient(new MockHttpClient(), 'https://observer.example.com', ' ', 5))->isConfigured());
        self::assertTrue((new ObserverClient(new MockHttpClient(), 'https://observer.example.com', 'token', 5))->isConfigured());
    }

    #[Test]
    public function it_posts_the_health_snapshot_with_the_ingest_token(): void
    {
        $response = new MockResponse('{"status":"ok"}');
        $client = new ObserverClient(new MockHttpClient($response), 'https://observer.example.com/', 'secret', 5);

        $client->pushHealth(['overallState' => 'healthy']);

        self::assertSame('POST', $response->getRequestMethod());
        self::assertSame('https://observer.example.com/api/ingest/health', $response->getRequestUrl());
        self::assertContains('Authorization: Bearer secret', $response->getRequestOptions()['headers']);
        self::assertSame('{"overallState":"healthy"}', $response->getRequestOptions()['body']);
    }

    #[Test]
    public function it_returns_the_reports_the_observer_refused(): void
    {
        $response = new MockResponse(
            '{"accepted":1,"rejected":1,"errors":[{"index":1,"error":"Unknown status \"weird\"."}]}',
            ['http_code' => 422],
        );
        $client = new ObserverClient(new MockHttpClient($response), 'https://observer.example.com', 'secret', 5);

        $result = $client->pushReports([['runId' => 'a'], ['runId' => 'b']]);

        self::assertSame('https://observer.example.com/api/ingest/reports', $response->getRequestUrl());
        self::assertSame('{"reports":[{"runId":"a"},{"runId":"b"}]}', $response->getRequestOptions()['body']);
        self::assertSame(1, $result->accepted);
        self::assertSame([['index' => 1, 'error' => 'Unknown status "weird".']], $result->rejections);
    }

    #[Test]
    public function it_fails_when_the_token_is_refused(): void
    {
        $client = new ObserverClient(new MockHttpClient(new MockResponse('{"error":"unknown_source"}', ['http_code' => 401])), 'https://observer.example.com', 'secret', 5);

        $this->expectException(ObserverPushException::class);
        $this->expectExceptionMessage('The observer answered 401 on /api/ingest/reports.');

        $client->pushReports([]);
    }

    #[Test]
    public function it_fails_when_a_health_snapshot_is_refused(): void
    {
        $client = new ObserverClient(new MockHttpClient(new MockResponse('{}', ['http_code' => 422])), 'https://observer.example.com', 'secret', 5);

        $this->expectException(ObserverPushException::class);

        $client->pushHealth([]);
    }

    #[Test]
    public function it_fails_when_the_observer_cannot_be_reached(): void
    {
        $client = new ObserverClient(new MockHttpClient(new MockResponse(info: ['error' => 'Connection refused'])), 'https://observer.example.com', 'secret', 5);

        $this->expectException(ObserverPushException::class);
        $this->expectExceptionMessage('could not be reached');

        $client->pushHealth([]);
    }
}
