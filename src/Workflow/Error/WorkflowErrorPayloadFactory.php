<?php

declare(strict_types=1);

namespace Fluxx\Workflow\Error;

use DateTimeImmutable;
use Throwable;

final class WorkflowErrorPayloadFactory
{
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
}
