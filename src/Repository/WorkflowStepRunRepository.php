<?php

declare(strict_types=1);

namespace Fluxx\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Fluxx\Entity\Enum\WorkflowStepRunStatus;
use Fluxx\Entity\Enum\WorkflowRunStatus;
use Fluxx\Entity\WorkflowRun;
use Fluxx\Entity\WorkflowStepRun;

/**
 * @extends ServiceEntityRepository<WorkflowStepRun>
 */
final class WorkflowStepRunRepository extends ServiceEntityRepository implements WorkflowStepRunLookupInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkflowStepRun::class);
    }

    /**
     * @return list<WorkflowStepRun>
     */
    public function findByWorkflowRunOrdered(WorkflowRun $workflowRun): array
    {
        return $this->createQueryBuilder('workflow_step_run')
            ->andWhere('workflow_step_run.workflowRun = :workflowRun')
            ->setParameter('workflowRun', $workflowRun)
            ->orderBy('workflow_step_run.position', 'ASC')
            ->addOrderBy('workflow_step_run.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findLatestByWorkflowRunAndType(
        WorkflowRun $workflowRun,
        string $stepType,
    ): ?WorkflowStepRun {
        /** @var WorkflowStepRun|null $stepRun */
        $stepRun = $this->createQueryBuilder('workflow_step_run')
            ->andWhere('workflow_step_run.workflowRun = :workflowRun')
            ->andWhere('workflow_step_run.stepType = :stepType')
            ->setParameter('workflowRun', $workflowRun)
            ->setParameter('stepType', $stepType)
            ->orderBy('workflow_step_run.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $stepRun;
    }

    public function findLatestByWorkflowRunAndStepName(WorkflowRun $workflowRun, string $stepName): ?WorkflowStepRun
    {
        /** @var WorkflowStepRun|null $stepRun */
        $stepRun = $this->createQueryBuilder('workflow_step_run')
            ->andWhere('workflow_step_run.workflowRun = :workflowRun')
            ->andWhere('workflow_step_run.stepName = :stepName')
            ->setParameter('workflowRun', $workflowRun)
            ->setParameter('stepName', $stepName)
            ->orderBy('workflow_step_run.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $stepRun;
    }

    /**
     * @param list<string> $stepNames
     * @return array<string, WorkflowStepRun>
     */
    public function findCompletedByWorkflowRunAndStepNames(WorkflowRun $workflowRun, array $stepNames): array
    {
        if ($stepNames === []) {
            return [];
        }

        /** @var list<WorkflowStepRun> $stepRuns */
        $stepRuns = $this->createQueryBuilder('workflow_step_run')
            ->andWhere('workflow_step_run.workflowRun = :workflowRun')
            ->andWhere('workflow_step_run.stepName IN (:stepNames)')
            ->andWhere('workflow_step_run.status = :status')
            ->setParameter('workflowRun', $workflowRun)
            ->setParameter('stepNames', $stepNames)
            ->setParameter('status', WorkflowStepRunStatus::Completed)
            ->orderBy('workflow_step_run.id', 'DESC')
            ->getQuery()
            ->getResult();

        $grouped = [];

        foreach ($stepRuns as $stepRun) {
            $grouped[$stepRun->stepName()] ??= $stepRun;
        }

        return $grouped;
    }

    /**
     * @param list<WorkflowRun> $workflowRuns
     * @return array<string, list<WorkflowStepRun>>
     */
    public function findByWorkflowRunsGrouped(array $workflowRuns): array
    {
        if ($workflowRuns === []) {
            return [];
        }

        /** @var list<WorkflowStepRun> $stepRuns */
        $stepRuns = $this->createQueryBuilder('workflow_step_run')
            ->andWhere('workflow_step_run.workflowRun IN (:workflowRuns)')
            ->setParameter('workflowRuns', $workflowRuns)
            ->orderBy('workflow_step_run.position', 'ASC')
            ->addOrderBy('workflow_step_run.id', 'ASC')
            ->getQuery()
            ->getResult();

        $grouped = [];

        foreach ($stepRuns as $stepRun) {
            $grouped[$stepRun->workflowRun()->runId()][] = $stepRun;
        }

        return $grouped;
    }

    public function findOneByWorkflowNameRunIdAndStepName(string $workflowName, string $runId, string $stepName): ?WorkflowStepRun
    {
        /** @var WorkflowStepRun|null $stepRun */
        $stepRun = $this->createQueryBuilder('workflow_step_run')
            ->innerJoin('workflow_step_run.workflowRun', 'workflow_run')
            ->addSelect('workflow_run')
            ->andWhere('workflow_run.workflowName = :workflowName')
            ->andWhere('workflow_run.runId = :runId')
            ->andWhere('workflow_step_run.stepName = :stepName')
            ->setParameter('workflowName', $workflowName)
            ->setParameter('runId', $runId)
            ->setParameter('stepName', $stepName)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $stepRun;
    }

    public function findLatestCompletedByWorkflowNameAndStepNameAndIdempotenceKey(
        string $workflowName,
        string $stepName,
        string $idempotenceKey,
    ): ?WorkflowStepRun {
        /** @var WorkflowStepRun|null $stepRun */
        $stepRun = $this->createQueryBuilder('workflow_step_run')
            ->innerJoin('workflow_step_run.workflowRun', 'workflow_run')
            ->addSelect('workflow_run')
            ->andWhere('workflow_run.workflowName = :workflowName')
            ->andWhere('workflow_step_run.stepName = :stepName')
            ->andWhere('workflow_step_run.idempotenceKey = :idempotenceKey')
            ->andWhere('workflow_step_run.status = :status')
            ->setParameter('workflowName', $workflowName)
            ->setParameter('stepName', $stepName)
            ->setParameter('idempotenceKey', $idempotenceKey)
            ->setParameter('status', WorkflowStepRunStatus::Completed)
            ->orderBy('workflow_step_run.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $stepRun;
    }

    /**
     * @param list<string> $workflowNames
     * @return list<WorkflowStepRun>
     */
    public function findFailedByWorkflowNames(array $workflowNames): array
    {
        if ($workflowNames === []) {
            return [];
        }

        /** @var list<WorkflowStepRun> $stepRuns */
        return $this->createQueryBuilder('workflow_step_run')
            ->innerJoin('workflow_step_run.workflowRun', 'workflow_run')
            ->addSelect('workflow_run')
            ->andWhere('workflow_run.workflowName IN (:workflowNames)')
            ->andWhere('workflow_step_run.status = :stepStatus')
            ->andWhere('workflow_run.status IN (:runStatuses)')
            ->setParameter('workflowNames', array_values(array_unique($workflowNames)))
            ->setParameter('stepStatus', WorkflowStepRunStatus::Failed)
            ->setParameter('runStatuses', [
                WorkflowRunStatus::Failed,
                WorkflowRunStatus::PartiallyFailed,
            ])
            ->orderBy('workflow_run.workflowName', 'ASC')
            ->addOrderBy('workflow_step_run.finishedAt', 'DESC')
            ->addOrderBy('workflow_run.createdAt', 'DESC')
            ->addOrderBy('workflow_step_run.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @param list<string> $workflowNames
     * @param list<string> $matchedWorkflowCodes
     * @param array<string, list<string>> $matchedStepCodesByWorkflowCode
     */
    public function countTroubleshootingIssuesByWorkflowNames(
        array $workflowNames,
        string $searchQuery = '',
        array $matchedWorkflowCodes = [],
        array $matchedStepCodesByWorkflowCode = [],
    ): int {
        if ($workflowNames === []) {
            return 0;
        }

        [$searchSql, $params, $types] = $this->buildTroubleshootingSearchClause(
            $workflowNames,
            $searchQuery,
            $matchedWorkflowCodes,
            $matchedStepCodesByWorkflowCode,
        );

        return (int) $this->getEntityManager()->getConnection()->fetchOne(
            sprintf(
                'SELECT COUNT(*)
                 FROM (%s) failed_issue
                 INNER JOIN fluxx_workflow_step_run workflow_step_run ON workflow_step_run.id = failed_issue.latest_failed_step_run_id
                 INNER JOIN fluxx_workflow_run workflow_run ON workflow_run.id = workflow_step_run.workflow_run_id
                 %s',
                $this->failedTroubleshootingIssueSubquery(),
                $searchSql,
            ),
            $params,
            $types,
        );
    }

    /**
     * @param list<string> $workflowNames
     * @param list<string> $matchedWorkflowCodes
     * @param array<string, list<string>> $matchedStepCodesByWorkflowCode
     * @return list<array{
     *     workflowCode: string,
     *     sourceSystem: string,
     *     targetSystem: string,
     *     runId: string,
     *     stepCode: string,
     *     failedAt: ?DateTimeImmutable,
     *     errorMessage: string,
     *     failureCount: int
     * }>
     */
    public function findTroubleshootingIssueRowsByWorkflowNames(
        array $workflowNames,
        int $limit,
        int $offset,
        string $searchQuery = '',
        array $matchedWorkflowCodes = [],
        array $matchedStepCodesByWorkflowCode = [],
    ): array {
        if ($workflowNames === []) {
            return [];
        }

        [$searchSql, $params, $types] = $this->buildTroubleshootingSearchClause(
            $workflowNames,
            $searchQuery,
            $matchedWorkflowCodes,
            $matchedStepCodesByWorkflowCode,
        );

        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            sprintf(
                'SELECT
                    workflow_run.workflow_name AS workflow_code,
                    workflow_run.source_system,
                    workflow_run.target_system,
                    workflow_run.run_id,
                    workflow_step_run.step_name,
                    COALESCE(workflow_step_run.finished_at, workflow_step_run.created_at) AS failed_at,
                    COALESCE(NULLIF(TRIM(workflow_step_run.error_message), \'\'), \'No error message stored.\') AS error_message,
                    failed_issue.failure_count
                 FROM (%s) failed_issue
                 INNER JOIN fluxx_workflow_step_run workflow_step_run ON workflow_step_run.id = failed_issue.latest_failed_step_run_id
                 INNER JOIN fluxx_workflow_run workflow_run ON workflow_run.id = workflow_step_run.workflow_run_id
                 %s
                 ORDER BY COALESCE(workflow_step_run.finished_at, workflow_step_run.created_at) DESC, workflow_run.workflow_name ASC, workflow_step_run.step_name ASC
                 LIMIT :limit OFFSET :offset',
                $this->failedTroubleshootingIssueSubquery(),
                $searchSql,
            ),
            [
                ...$params,
                'limit' => max($limit, 1),
                'offset' => max($offset, 0),
            ],
            [
                ...$types,
                'limit' => Types::INTEGER,
                'offset' => Types::INTEGER,
            ],
        );

        return array_values(array_filter(array_map(
            static function (array $row): ?array {
                $workflowCode = (string) ($row['workflow_code'] ?? '');
                $runId = (string) ($row['run_id'] ?? '');
                $stepCode = (string) ($row['step_name'] ?? '');

                if ($workflowCode === '' || $runId === '' || $stepCode === '') {
                    return null;
                }

                return [
                    'workflowCode' => $workflowCode,
                    'sourceSystem' => (string) ($row['source_system'] ?? ''),
                    'targetSystem' => (string) ($row['target_system'] ?? ''),
                    'runId' => $runId,
                    'stepCode' => $stepCode,
                    'failedAt' => isset($row['failed_at']) && is_string($row['failed_at']) && $row['failed_at'] !== ''
                        ? new DateTimeImmutable($row['failed_at'])
                        : null,
                    'errorMessage' => (string) ($row['error_message'] ?? 'No error message stored.'),
                    'failureCount' => (int) ($row['failure_count'] ?? 0),
                ];
            },
            $rows,
        )));
    }

    private function failedTroubleshootingIssueSubquery(): string
    {
        return <<<'SQL'
            SELECT
                workflow_run.workflow_name,
                workflow_run.run_id,
                workflow_step_run.step_name,
                MAX(workflow_step_run.id) AS latest_failed_step_run_id,
                COUNT(*) AS failure_count
            FROM fluxx_workflow_step_run workflow_step_run
            INNER JOIN fluxx_workflow_run workflow_run ON workflow_run.id = workflow_step_run.workflow_run_id
            WHERE workflow_run.workflow_name IN (:workflowNames)
              AND workflow_step_run.status = :stepStatus
              AND workflow_run.status IN (:runStatuses)
            GROUP BY workflow_run.workflow_name, workflow_run.run_id, workflow_step_run.step_name
        SQL;
    }

    /**
     * @param list<string> $workflowNames
     * @param list<string> $matchedWorkflowCodes
     * @param array<string, list<string>> $matchedStepCodesByWorkflowCode
     * @return array{0: string, 1: array<string, mixed>, 2: array<string, mixed>}
     */
    private function buildTroubleshootingSearchClause(
        array $workflowNames,
        string $searchQuery,
        array $matchedWorkflowCodes,
        array $matchedStepCodesByWorkflowCode,
    ): array {
        $params = [
            'workflowNames' => array_values(array_unique($workflowNames)),
            'stepStatus' => WorkflowStepRunStatus::Failed->value,
            'runStatuses' => [
                WorkflowRunStatus::Failed->value,
                WorkflowRunStatus::PartiallyFailed->value,
            ],
        ];
        $types = [
            'workflowNames' => ArrayParameterType::STRING,
            'runStatuses' => ArrayParameterType::STRING,
        ];

        $searchQuery = trim($searchQuery);

        if ($searchQuery === '') {
            return ['', $params, $types];
        }

        $clauses = [
            'LOWER(workflow_run.workflow_name) LIKE :search',
            'LOWER(workflow_run.source_system) LIKE :search',
            'LOWER(workflow_run.target_system) LIKE :search',
            'LOWER(workflow_run.run_id) LIKE :search',
            'LOWER(workflow_step_run.step_name) LIKE :search',
            'LOWER(COALESCE(workflow_step_run.error_message, \'\')) LIKE :search',
        ];
        $params['search'] = '%' . mb_strtolower($searchQuery) . '%';

        $matchedWorkflowCodes = array_values(array_unique($matchedWorkflowCodes));

        if ($matchedWorkflowCodes !== []) {
            $clauses[] = 'workflow_run.workflow_name IN (:matchedWorkflowCodes)';
            $params['matchedWorkflowCodes'] = $matchedWorkflowCodes;
            $types['matchedWorkflowCodes'] = ArrayParameterType::STRING;
        }

        $stepMatchIndex = 0;

        foreach ($matchedStepCodesByWorkflowCode as $workflowCode => $stepCodes) {
            $stepCodes = array_values(array_unique($stepCodes));

            if ($stepCodes === []) {
                continue;
            }

            $workflowParam = sprintf('matchedStepWorkflowCode%d', $stepMatchIndex);
            $stepsParam = sprintf('matchedStepCodes%d', $stepMatchIndex);
            $clauses[] = sprintf('(workflow_run.workflow_name = :%s AND workflow_step_run.step_name IN (:%s))', $workflowParam, $stepsParam);
            $params[$workflowParam] = $workflowCode;
            $params[$stepsParam] = $stepCodes;
            $types[$stepsParam] = ArrayParameterType::STRING;
            ++$stepMatchIndex;
        }

        return [
            ' WHERE (' . implode(' OR ', $clauses) . ')',
            $params,
            $types,
        ];
    }
}
