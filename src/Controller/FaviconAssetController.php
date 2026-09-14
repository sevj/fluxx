<?php

declare(strict_types=1);

namespace Fluxx\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/fluxx/assets/favicon.png', name: 'fluxx_favicon_asset', methods: ['GET'])]
final class FaviconAssetController extends AbstractController
{
    public function __invoke(): BinaryFileResponse
    {
        $response = new BinaryFileResponse(__DIR__ . '/../../favicon.png');
        $response->headers->set('Content-Type', 'image/png');
        $response->headers->set('Cache-Control', 'public, max-age=31536000, immutable');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, 'favicon.png');

        return $response;
    }
}
