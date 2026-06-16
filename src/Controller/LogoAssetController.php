<?php

declare(strict_types=1);

namespace Fluxx\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/fluxx/assets/logo.png', name: 'fluxx_logo_asset', methods: ['GET'])]
final class LogoAssetController extends AbstractController
{
    public function __invoke(): BinaryFileResponse
    {
        $response = new BinaryFileResponse(__DIR__ . '/../../logo.png');
        $response->headers->set('Content-Type', 'image/png');
        $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, 'logo.png');

        return $response;
    }
}
