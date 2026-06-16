<?php

declare(strict_types=1);

namespace Fluxx\Controller;

use LogicException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/fluxx/logout', name: 'fluxx_logout', methods: ['GET', 'POST'])]
final class LogoutController extends AbstractController
{
    public function __invoke(): Response
    {
        throw new LogicException('This code should never be reached. The firewall logout handles this route.');
    }
}
