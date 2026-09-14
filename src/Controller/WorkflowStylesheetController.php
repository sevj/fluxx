<?php

declare(strict_types=1);

namespace Fluxx\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/fluxx/assets/workflow-index.css', name: 'fluxx_workflow_stylesheet', methods: ['GET'])]
final class WorkflowStylesheetController extends AbstractController
{
    public function __invoke(): BinaryFileResponse
    {
        $response = new BinaryFileResponse(__DIR__ . '/../Resources/public/styles/workflow-index.css');
        $response->headers->set('Content-Type', 'text/css; charset=UTF-8');
        $response->headers->set('Cache-Control', 'public, max-age=31536000, immutable');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, 'workflow-index.css');

        return $response;
    }
}
