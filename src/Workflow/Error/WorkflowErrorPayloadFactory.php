<?php

declare(strict_types=1);

namespace Fluxx\Workflow\Error;

use DateTimeImmutable;
use Throwable;

final class WorkflowErrorPayloadFactory
{
    /**
     * @param list<class-string> $businessExceptionClasses
     */
    public function __construct(
        private bool $autoBusinessClassification = true,
        private array $businessExceptionClasses = ['InvalidArgumentException', 'LogicException', 'DomainException', 'OutOfBoundsException'],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function fromThrowable(Throwable $throwable): array
    {
        $category = WorkflowErrorCategory::Technical;
        $errorCode = null;
        $context = [];

        if ($throwable instanceof WorkflowErrorInterface) {
            $category = $throwable->workflowErrorCategory();
            $errorCode = $throwable->workflowErrorCode();
            $context = $throwable->workflowErrorContext();
        } elseif ($this->autoBusinessClassification && $this->isBusinessThrowable($throwable)) {
            $category = WorkflowErrorCategory::Business;
        }

        return [
            'category' => $category->value,
            'class' => $throwable::class,
            'message' => $throwable->getMessage(),
            'code' => $errorCode,
            'context' => $context,
            'occurred_at' => (new DateTimeImmutable())->format(DATE_ATOM),
        ];
    }

    private function isBusinessThrowable(Throwable $throwable): bool
    {
        foreach ($this->businessExceptionClasses as $class) {
            if (is_a($throwable, $class, true)) {
                return true;
            }
        }

        return false;
    }
}
