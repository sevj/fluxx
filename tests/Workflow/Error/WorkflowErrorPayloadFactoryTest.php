<?php

declare(strict_types=1);

namespace Fluxx\Tests\Workflow\Error;

use Fluxx\Workflow\Error\BusinessWorkflowException;
use Fluxx\Workflow\Error\WorkflowErrorCategory;
use Fluxx\Workflow\Error\WorkflowErrorInterface;
use Fluxx\Workflow\Error\WorkflowErrorPayloadFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class WorkflowErrorPayloadFactoryTest extends TestCase
{
    #[Test]
    public function it_classifies_unknown_throwables_as_technical(): void
    {
        $payload = $this->factory()->fromThrowable(new RuntimeException('boom'));

        self::assertSame('technical', $payload['category']);
        self::assertSame('boom', $payload['message']);
        self::assertSame(RuntimeException::class, $payload['class']);
    }

    #[Test]
    public function it_preserves_business_error_metadata(): void
    {
        $payload = $this->factory()->fromThrowable(new BusinessWorkflowException(
            message: 'invalid record',
            workflowErrorCode: 'CONTACT_INVALID',
            context: ['record_id' => '42'],
        ));

        self::assertSame('business', $payload['category']);
        self::assertSame('CONTACT_INVALID', $payload['code']);
        self::assertSame(['record_id' => '42'], $payload['context']);
    }

    #[Test]
    public function it_classifies_invalid_argument_as_business_by_default(): void
    {
        $payload = $this->factory()->fromThrowable(new \InvalidArgumentException('record is invalid'));

        self::assertSame('business', $payload['category']);
    }

    #[Test]
    public function it_classifies_a_subclass_of_a_configured_business_exception_as_business(): void
    {
        $payload = $this->factory()->fromThrowable(new class extends \LogicException
        {
            // classless subclass of a configured business exception.
        });

        self::assertSame('business', $payload['category']);
        self::assertStringContainsString('@anonymous', $payload['class']);
    }

    #[Test]
    public function it_treats_a_business_exception_as_technical_when_auto_classification_is_disabled(): void
    {
        $payload = $this->factory(enabled: false)->fromThrowable(new \InvalidArgumentException('record is invalid'));

        self::assertSame('technical', $payload['category']);
    }

    #[Test]
    public function it_keeps_an_explicit_technical_workflow_error_even_when_the_class_is_configured_business(): void
    {
        $payload = $this->factory()->fromThrowable(new FixedCategoryError(
            new \InvalidArgumentException('record is invalid'),
            WorkflowErrorCategory::Technical,
        ));

        self::assertSame('technical', $payload['category']);
    }

    #[Test]
    public function it_supports_custom_business_exception_classes(): void
    {
        $payload = $this->factory(classes: [\RuntimeException::class])->fromThrowable(new RuntimeException('boom'));

        self::assertSame('business', $payload['category']);
    }

    #[Test]
    public function it_ignores_a_configured_class_that_does_not_exist(): void
    {
        $payload = $this->factory(classes: ['Fully\Inexistent\FantasyException'])->fromThrowable(new RuntimeException('boom'));

        self::assertSame('technical', $payload['category']);
    }

    /**
     * @param list<class-string> $classes
     */
    private function factory(bool $enabled = true, array $classes = ['InvalidArgumentException', 'LogicException', 'DomainException', 'OutOfBoundsException']): WorkflowErrorPayloadFactory
    {
        return new WorkflowErrorPayloadFactory($enabled, $classes);
    }
}

final class FixedCategoryError extends \InvalidArgumentException implements WorkflowErrorInterface
{
    public function __construct(\InvalidArgumentException $previous, private readonly WorkflowErrorCategory $category)
    {
        parent::__construct($previous->getMessage(), 0, $previous);
    }

    public function workflowErrorCategory(): WorkflowErrorCategory
    {
        return $this->category;
    }

    public function workflowErrorCode(): ?string
    {
        return null;
    }

    public function workflowErrorContext(): array
    {
        return [];
    }
}
