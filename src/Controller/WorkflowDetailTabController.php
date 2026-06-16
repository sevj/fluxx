<?php

declare(strict_types=1);

namespace Fluxx\Controller;

use Fluxx\Operations\WorkflowRunFilterFactory;
use Fluxx\Ui\WorkflowDetails;
use Fluxx\Ui\WorkflowRunFilters;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/fluxx/workflow/{code}/tab/{tab}', name: 'fluxx_workflow_tab_show', methods: ['GET'])]
final class WorkflowDetailTabController extends AbstractController
{
    public function __construct(
        private readonly WorkflowDetails $workflowDetails,
        private readonly WorkflowRunFilterFactory $workflowRunFilterFactory,
    ) {
    }

    public function __invoke(string $code, string $tab, Request $request): Response
    {
        $page = max($request->query->getInt('page', 1), 1);
        $range = (string) $request->query->get('range', 'month');
        $executionFilters = $this->buildExecutionFilters($request, $code);

        try {
            $workflow = $this->workflowDetails->forCode(
                $code,
                $page,
                statisticsRange: $range,
                executionFilters: $executionFilters,
            );
        } catch (InvalidArgumentException $exception) {
            throw $this->createNotFoundException(sprintf('Workflow "%s" was not found.', $code), $exception);
        }

        $template = match ($tab) {
            'steps' => '@Fluxx/workflow/_tab_steps.html.twig',
            'executions' => '@Fluxx/workflow/_tab_executions.html.twig',
            'statistics' => '@Fluxx/workflow/_tab_statistics.html.twig',
            default => throw $this->createNotFoundException(sprintf('Workflow tab "%s" was not found.', $tab)),
        };

        return $this->render($template, [
            'workflow' => $workflow,
            'executionFilters' => $executionFilters,
        ]);
    }

    private function buildExecutionFilters(Request $request, string $workflowCode): WorkflowRunFilters
    {
        return $this->workflowRunFilterFactory->fromArray($request->query->all(), $workflowCode);
    }
}
