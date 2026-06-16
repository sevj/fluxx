<?php

declare(strict_types=1);

namespace Fluxx\Tests\Workflow\Error;

use Fluxx\Workflow\Error\BusinessWorkflowException;
use Fluxx\Workflow\Error\WorkflowErrorPayloadFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class WorkflowErrorPayloadFactoryTest extends TestCase
{
    #[Test]
    public function it_classifies_unknown_throwables_as_technical(): void
    {
        $payload = (new WorkflowErrorPayloadFactory())->fromThrowable(new RuntimeException('boom'));

        self::assertSame('technical', $payload['category']);
        self::assertSame('boom', $payload['message']);
        self::assertSame(RuntimeException::class, $payload['class']);
    }

    #[Test]
    public function it_preserves_business_error_metadata(): void
    {
        $payload = (new WorkflowErrorPayloadFactory())->fromThrowable(new BusinessWorkflowException(
            message: 'invalid record',
            workflowErrorCode: 'CONTACT_INVALID',
            context: ['record_id' => '42'],
        ));

        self::assertSame('business', $payload['category']);
        self::assertSame('CONTACT_INVALID', $payload['code']);
        self::assertSame(['record_id' => '42'], $payload['context']);
    }
}
