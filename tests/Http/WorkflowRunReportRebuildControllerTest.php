<?php

declare(strict_types=1);

namespace Fluxx\Tests\Http;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Fluxx\Controller\WorkflowRunReportRebuildController;
use Fluxx\Entity\WorkflowRun;
use Fluxx\Entity\WorkflowStepRun;
use Fluxx\Repository\WorkflowRunReportRepository;
use Fluxx\Repository\WorkflowRunRepository;
use Fluxx\Repository\WorkflowStepRunLookupInterface;
use Fluxx\Repository\WorkflowStepRunRepository;
use Fluxx\Tests\Fixture\FixtureWorkflow;
use Fluxx\Tests\Fixture\StubExecutableStep;
use Fluxx\Workflow\Report\RunReportBuilder;
use Fluxx\Workflow\SynchronizationRegistry;
use Fluxx\Workflow\WorkflowDefinition;
use Fluxx\Workflow\WorkflowStepDefinition;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Runs against SQLite by default. Set FLUXX_TEST_DATABASE_URL to use another database:
 * its Fluxx tables are dropped and created again, so point it at a throwaway one.
 */
final class WorkflowRunReportRebuildControllerTest extends TestCase
{
    private const REDIRECT = '/fluxx/workflow/sync/run/run-1?tab=report';

    private Connection $connection;
    private EntityManager $entityManager;
    private SchemaTool $schemaTool;
    private Session $session;

    /** @var list<\Doctrine\ORM\Mapping\ClassMetadata<object>> */
    private array $metadata;

    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        $url = getenv('FLUXX_TEST_DATABASE_URL') ?: null;

        if ($url === null && !extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('Requires pdo_sqlite or FLUXX_TEST_DATABASE_URL.');
        }

        $this->connection = DriverManager::getConnection(
            $url !== null
                ? (new DsnParser(['postgresql' => 'pdo_pgsql', 'mysql' => 'pdo_mysql']))->parse($url)
                : ['driver' => 'pdo_sqlite', 'memory' => true],
        );

        $configuration = ORMSetup::createAttributeMetadataConfig([__DIR__ . '/../../src/Entity'], true);
        $configuration->setNamingStrategy(new UnderscoreNamingStrategy(CASE_LOWER, true));
        $configuration->enableNativeLazyObjects(true);
        $this->entityManager = new EntityManager($this->connection, $configuration);

        $this->metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $this->schemaTool = new SchemaTool($this->entityManager);
        $this->schemaTool->dropSchema($this->metadata);
        $this->schemaTool->createSchema($this->metadata);
        $this->session = new Session(new MockArraySessionStorage());
    }

    protected function tearDown(): void
    {
        if (isset($this->schemaTool)) {
            $this->schemaTool->dropSchema($this->metadata);
            $this->connection->close();
        }
    }

    #[Test]
    public function rebuild_requires_admin(): void
    {
        $class = new \ReflectionClass(WorkflowRunReportRebuildController::class);
        $granted = array_map(static fn (\ReflectionAttribute $attribute): mixed => $attribute->newInstance()->attribute, $class->getAttributes(IsGranted::class));
        $route = $class->getAttributes(Route::class)[0]->newInstance();

        self::assertSame(['ROLE_ADMIN'], $granted);
        self::assertSame(['POST'], $route->methods);
    }

    #[Test]
    public function an_invalid_token_is_refused(): void
    {
        $this->storeRun('run-1', completed: true);

        $this->expectException(AccessDeniedException::class);

        try {
            $this->invoke($this->controller(), 'forged');
        } finally {
            self::assertSame(0, $this->reportCount());
        }
    }

    #[Test]
    public function rebuild_stores_the_report_and_flashes_it(): void
    {
        $this->storeRun('run-1', completed: true);

        $response = $this->invoke($this->controller());

        self::assertSame(self::REDIRECT, $response->getTargetUrl());
        self::assertSame(1, $this->reportCount());
        self::assertSame(['run_report.flash.rebuilt'], $this->flashKeys('success'));
    }

    #[Test]
    public function a_run_that_did_not_complete_gets_an_error_flash(): void
    {
        $this->storeRun('run-1', completed: false);

        $response = $this->invoke($this->controller());

        self::assertSame(self::REDIRECT, $response->getTargetUrl());
        self::assertSame(0, $this->reportCount());
        self::assertSame(['run_report.flash.not_reportable'], $this->flashKeys('error'));
    }

    #[Test]
    public function an_unknown_run_gets_an_error_flash(): void
    {
        $this->invoke($this->controller());

        self::assertSame(['run_report.flash.run_not_found'], $this->flashKeys('error'));
    }

    #[Test]
    public function a_run_of_another_workflow_is_treated_as_not_found(): void
    {
        $this->storeRun('run-1', completed: true);

        $response = $this->invoke($this->controller(), workflowCode: 'other');

        self::assertSame(self::REDIRECT, $response->getTargetUrl());
        self::assertSame(0, $this->reportCount());
        self::assertSame(['run_report.flash.run_not_found'], $this->flashKeys('error'));
    }

    #[Test]
    public function a_pruned_run_gets_its_report_rebuilt(): void
    {
        $this->storeRun('run-1', completed: true);
        $this->connection->executeStatement("UPDATE fluxx_workflow_run SET status = 'payloads_pruned'");

        $this->invoke($this->controller());

        self::assertSame(1, $this->reportCount());
        self::assertSame(['run_report.flash.rebuilt'], $this->flashKeys('success'));
    }

    #[Test]
    public function a_build_failure_is_flashed_and_logged_instead_of_failing_the_request(): void
    {
        $this->storeRun('run-1', completed: true);
        $lookup = $this->createStub(WorkflowStepRunLookupInterface::class);
        $lookup->method('findByWorkflowRunOrdered')->willThrowException(new \RuntimeException('Connection lost'));

        $response = $this->invoke($this->controller($lookup));

        self::assertSame(self::REDIRECT, $response->getTargetUrl());
        self::assertSame(0, $this->reportCount());
        self::assertSame(['run_report.flash.rebuild_failed'], $this->flashKeys('error'));
        self::assertCount(1, $this->logged);
        self::assertStringContainsString('could not be rebuilt', $this->logged[0]);
    }

    private function invoke(WorkflowRunReportRebuildController $controller, string $token = 'valid', string $workflowCode = 'sync'): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        $request = Request::create('/fluxx/workflow/sync/run/run-1/report/rebuild', 'POST', ['_token' => $token, '_redirect' => self::REDIRECT]);
        $request->setSession($this->session);
        $requestStack = new RequestStack();
        $requestStack->push($request);

        $tokens = $this->createStub(CsrfTokenManagerInterface::class);
        $tokens->method('isTokenValid')->willReturnCallback(static fn (CsrfToken $token): bool => $token->getId() === 'fluxx.report.rebuild.run-1' && $token->getValue() === 'valid');

        $controller->setContainer(new ServiceLocator([
            'request_stack' => static fn (): RequestStack => $requestStack,
            'security.csrf.token_manager' => static fn (): CsrfTokenManagerInterface => $tokens,
            'router' => fn (): UrlGeneratorInterface => $this->createStub(UrlGeneratorInterface::class),
        ]));

        return $controller($workflowCode, 'run-1', $request);
    }

    private function controller(?WorkflowStepRunLookupInterface $stepRunLookup = null): WorkflowRunReportRebuildController
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($this->entityManager);
        $registry->method('getManager')->willReturn($this->entityManager);

        $workflows = new SynchronizationRegistry([new FixtureWorkflow(new WorkflowDefinition('sync', 'Sync', 'src', 'tgt', [
            new WorkflowStepDefinition('read', 'Read', 'read', new StubExecutableStep('read', 'Read')),
        ]))]);
        $logged = &$this->logged;

        return new WorkflowRunReportRebuildController(
            new WorkflowRunRepository($registry),
            new RunReportBuilder($workflows, $stepRunLookup ?? new WorkflowStepRunRepository($registry), new ServiceLocator([])),
            new WorkflowRunReportRepository($registry),
            new class ($logged) extends AbstractLogger {
                /** @param list<string> $logged */
                public function __construct(private array &$logged)
                {
                }

                public function log($level, \Stringable|string $message, array $context = []): void
                {
                    $this->logged[] = (string) $message;
                }
            },
        );
    }

    private function storeRun(string $runId, bool $completed): void
    {
        $origin = new DateTimeImmutable('2026-11-17 08:00:00');
        $run = new WorkflowRun($runId, 'sync', 'src', 'tgt', 'manual');
        $run->markRunning($origin);
        $step = new WorkflowStepRun($run, 'read', 'read', 1);
        $step->markRunning($origin);

        if ($completed) {
            $step->markCompleted(10, 10, durationMs: 10000, finishedAt: $origin->modify('+10 seconds'));
            $run->markCompleted($origin->modify('+10 seconds'));
        } else {
            $step->markFailed('boom');
            $run->markFailed('boom');
        }

        $this->entityManager->persist($run);
        $this->entityManager->flush();
        $this->entityManager->clear();
    }

    /**
     * @return list<string>
     */
    private function flashKeys(string $type): array
    {
        return array_map(
            static fn (TranslatableMessage|string $message): string => $message instanceof TranslatableMessage ? $message->getMessage() : $message,
            $this->session->getFlashBag()->peek($type),
        );
    }

    private function reportCount(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM fluxx_workflow_run_report');
    }
}
