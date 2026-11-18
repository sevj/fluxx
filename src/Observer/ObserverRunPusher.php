<?php

declare(strict_types=1);

namespace Fluxx\Observer;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Fluxx\Entity\WorkflowRun;
use Fluxx\Repository\FluxxSettingRepository;
use Fluxx\Repository\WorkflowRunRepository;
use Fluxx\Repository\WorkflowStepRunRepository;

/**
 * Pushes this application's run reports to the Fluxx Observer.
 *
 * Each push sends the runs created, started or finished since the previous successful push,
 * plus every run still active, so the observer sees runs in flight and then their outcome.
 * The observer upserts reports on their run id: sending a run twice is harmless, which lets
 * each push overlap the previous one a little instead of risking a gap.
 */
final readonly class ObserverRunPusher
{
    private const CURSOR_SETTING_KEY = 'observer.run_push';
    private const BATCH_SIZE = 100;
    private const FIRST_PUSH_LOOKBACK = '-1 day';
    private const CURSOR_OVERLAP = '-2 minutes';

    public function __construct(
        private ObserverClient $client,
        private RunReportPayloadFactory $payloadFactory,
        private WorkflowRunRepository $runRepository,
        private WorkflowStepRunRepository $stepRunRepository,
        private FluxxSettingRepository $settingRepository,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param DateTimeImmutable|null $since resend the runs changed since this instant instead of
     *                                      since the previous push
     *
     * @throws ObserverPushException when the reports cannot be delivered; the next push then
     *                               starts again from the same point
     */
    public function push(?DateTimeImmutable $since = null): ObserverRunPushReport
    {
        $startedAt = new DateTimeImmutable();
        $since ??= $this->lastPushAt()?->modify(self::CURSOR_OVERLAP) ?? $startedAt->modify(self::FIRST_PUSH_LOOKBACK);
        $pushedRunCount = 0;
        $rejectedRuns = [];
        $afterId = 0;

        do {
            $runs = $this->runRepository->findObservableSince($since, $afterId, self::BATCH_SIZE);

            if ($runs === []) {
                break;
            }

            $stepRuns = $this->stepRunRepository->findByWorkflowRunsGrouped($runs);
            $reports = array_map(
                fn (WorkflowRun $run) => $this->payloadFactory->create($run, $stepRuns[$run->runId()] ?? []),
                $runs,
            );
            $result = $this->client->pushReports($reports);
            $pushedRunCount += $result->accepted;

            foreach ($result->rejections as $rejection) {
                $rejectedRuns[] = [
                    'runId' => (string) ($reports[$rejection['index']]['runId'] ?? '?'),
                    'error' => $rejection['error'],
                ];
            }

            $afterId = (int) end($runs)->id();
            $this->entityManager->clear();
        } while (count($runs) === self::BATCH_SIZE);

        // Refused reports are not sent again: the observer would refuse them again.
        $this->settingRepository->saveValue(self::CURSOR_SETTING_KEY, ['last_push_at' => $startedAt->format(DATE_ATOM)]);

        return new ObserverRunPushReport($since, $pushedRunCount, $rejectedRuns);
    }

    private function lastPushAt(): ?DateTimeImmutable
    {
        $value = $this->settingRepository->findValue(self::CURSOR_SETTING_KEY)['last_push_at'] ?? null;

        if (!is_string($value)) {
            return null;
        }

        return DateTimeImmutable::createFromFormat(DATE_ATOM, $value) ?: null;
    }
}
