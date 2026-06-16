<?php

declare(strict_types=1);

namespace Fluxx\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/fluxx/assets/scripts/{script}.js', name: 'fluxx_script_asset', methods: ['GET'])]
final class ScriptAssetController extends AbstractController
{
    private const SCRIPT_MAP = [
        'theme' => 'theme.js',
        'clickable-rows' => 'clickable-rows.js',
        'workflow-detail' => 'workflow-detail.js',
        'runtime-monitor' => 'runtime-monitor.js',
    ];

    public function __invoke(string $script): BinaryFileResponse
    {
        $filename = self::SCRIPT_MAP[$script] ?? null;

        if ($filename === null) {
            throw $this->createNotFoundException();
        }

        $response = new BinaryFileResponse(__DIR__ . '/../Resources/public/scripts/' . $filename);
        $response->headers->set('Content-Type', 'application/javascript; charset=UTF-8');
        $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $filename);

        return $response;
    }
}
