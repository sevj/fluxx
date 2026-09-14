<?php

declare(strict_types=1);

namespace Fluxx\Controller;

use Fluxx\Settings\DailyRecapSettingsManager;
use Fluxx\Settings\RuntimeSettingsManager;
use InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/fluxx/configuration', name: 'fluxx_configuration_', methods: ['GET', 'POST'])]
#[IsGranted('ROLE_ADMIN')]
final class ConfigurationController extends AbstractController
{
    private const ALLOWED_TABS = ['runtime', 'daily_recap'];
    private const DEFAULT_TAB = 'runtime';
    private const SECTIONS = ['runtime', 'daily_recap'];

    public function __construct(
        private readonly RuntimeSettingsManager $runtimeSettingsManager,
        private readonly DailyRecapSettingsManager $dailyRecapSettingsManager,
    ) {
    }

    #[Route('', name: 'index')]
    public function __invoke(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            return $this->handlePost($request);
        }

        return $this->renderPage($this->resolveTab($request));
    }

    private function handlePost(Request $request): Response
    {
        $section = (string) $request->request->get('_section', '');

        if (!in_array($section, self::SECTIONS, true)) {
            throw $this->createNotFoundException(sprintf('Unknown configuration section "%s".', $section));
        }

        if (!$this->isCsrfTokenValid('fluxx.configuration.' . $section, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        try {
            if ($section === 'runtime') {
                $this->runtimeSettingsManager->save([
                    'stale_lock_timeout_seconds' => $request->request->get('stale_lock_timeout_seconds', ''),
                    'worker_heartbeat_timeout_seconds' => $request->request->get('worker_heartbeat_timeout_seconds', ''),
                    'health_warning_threshold_seconds' => $request->request->get('health_warning_threshold_seconds', ''),
                    'health_critical_threshold_seconds' => $request->request->get('health_critical_threshold_seconds', ''),
                    'max_global_retries' => $request->request->get('max_global_retries', ''),
                ]);
            } else {
                $this->dailyRecapSettingsManager->save([
                    'enabled' => $request->request->has('enabled'),
                    'recipients' => $request->request->get('recipients', ''),
                    'sender' => $request->request->get('sender', ''),
                    'subject_prefix' => $request->request->get('subject_prefix', ''),
                    'timezone' => $request->request->get('timezone', ''),
                    'send_empty_report' => $request->request->has('send_empty_report'),
                ]);
            }

            $this->addFlash('success', 'Configuration saved.');
        } catch (InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('fluxx_configuration_index', ['tab' => $section]);
    }

    private function renderPage(string $activeTab): Response
    {
        return $this->render('@Fluxx/configuration/index.html.twig', [
            'activeTab' => $activeTab,
            'runtimeSettings' => $this->runtimeSettingsManager->get(),
            'dailyRecapSettings' => $this->dailyRecapSettingsManager->get(),
        ]);
    }

    private function resolveTab(Request $request): string
    {
        $tab = (string) $request->query->get('tab', self::DEFAULT_TAB);

        return in_array($tab, self::ALLOWED_TABS, true) ? $tab : self::DEFAULT_TAB;
    }
}
