<?php

declare(strict_types=1);

namespace Fluxx\Tests\Http;

use Fluxx\Http\InternalRedirectTarget;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class InternalRedirectTargetTest extends TestCase
{
    private function requestWithRedirect(?string $redirect): Request
    {
        $request = Request::create('/fluxx/workflow/sync/run/run-1/relaunch', 'POST');
        $request->request->set('_token', 'csrf-token');

        if ($redirect !== null) {
            $request->request->set('_redirect', $redirect);
        }

        return $request;
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function acceptedTargets(): iterable
    {
        yield 'root path' => ['/'];
        yield 'app path' => ['/fluxx/workflow/sync'];
        yield 'path with query' => ['/fluxx/workflow/sync?tab=runs'];
        yield 'path with fragment' => ['/fluxx/workflow/sync#run-1'];
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function rejectedTargets(): iterable
    {
        yield 'protocol relative' => ['//evil.example.com'];
        yield 'protocol relative with path' => ['//evil.example.com/path'];
        yield 'backslash protocol relative' => ['/\\evil.example.com'];
        yield 'absolute https' => ['https://evil.example.com'];
        yield 'absolute http with same host' => ['http://localhost/fluxx'];
        yield 'relative no leading slash' => ['fluxx/workflow/sync'];
        yield 'javascript scheme' => ['javascript:alert(1)'];
    }

    #[Test]
    #[DataProvider('acceptedTargets')]
    public function it_accepts_internal_paths(string $redirect): void
    {
        $request = $this->requestWithRedirect($redirect);

        self::assertSame($redirect, InternalRedirectTarget::extract($request));
    }

    #[Test]
    #[DataProvider('rejectedTargets')]
    public function it_rejects_external_or_unsafe_targets(string $redirect): void
    {
        $request = $this->requestWithRedirect($redirect);

        self::assertNull(InternalRedirectTarget::extract($request));
    }

    #[Test]
    public function it_returns_null_when_the_field_is_missing(): void
    {
        $request = $this->requestWithRedirect(null);

        self::assertNull(InternalRedirectTarget::extract($request));
    }

    #[Test]
    public function it_returns_null_for_a_blank_target(): void
    {
        $request = $this->requestWithRedirect('   ');

        self::assertNull(InternalRedirectTarget::extract($request));
    }
}
