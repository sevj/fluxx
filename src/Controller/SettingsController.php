<?php

declare(strict_types=1);

namespace Fluxx\Controller;

use Fluxx\Settings\DailyRecapSettingsManager;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/fluxx/settings', name: 'fluxx_settings_', methods: ['GET', 'POST'])]
final class SettingsController extends AbstractController
{
    public function __construct(
        private readonly DailyRecapSettingsManager $dailyRecapSettingsManager,
    ) {
    }

    #[Route('', name: 'index')]
    public function __invoke(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('fluxx.settings.daily_recap', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException();
            }

            try {
                $this->dailyRecapSettingsManager->save([
                    'enabled' => $request->request->has('enabled'),
                    'recipients' => $request->request->get('recipients', ''),
                    'sender' => $request->request->get('sender', ''),
                    'subject_prefix' => $request->request->get('subject_prefix', ''),
                    'timezone' => $request->request->get('timezone', ''),
                    'send_empty_report' => $request->request->has('send_empty_report'),
                ]);
                $this->addFlash('success', 'Settings saved.');
            } catch (InvalidArgumentException $exception) {
                $this->addFlash('error', $exception->getMessage());
            }

            return $this->redirectToRoute('fluxx_settings_index');
        }

        return $this->render('@Fluxx/settings/index.html.twig', [
            'dailyRecapSettings' => $this->dailyRecapSettingsManager->get(),
        ]);
    }
}
