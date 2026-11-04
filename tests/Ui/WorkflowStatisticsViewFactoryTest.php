<?php

declare(strict_types=1);

namespace Fluxx\Tests\Ui;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ManagerRegistry;
use Fluxx\Entity\WorkflowRun;
use Fluxx\Reporting\WorkflowStepRunStatistics;
use Fluxx\Repository\WorkflowRunRepository;
use Fluxx\Ui\WorkflowStatisticsMetricView;
use Fluxx\Ui\WorkflowStatisticsViewFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

final class WorkflowStatisticsViewFactoryTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function labels(): iterable
    {
        yield 'week in French' => ['fr', 'week', 'mer. 04 nov.', 'mer.'];
        yield 'week in English' => ['en', 'week', 'Wed Nov 04', 'Wed'];
        yield 'month in French' => ['fr', 'month', '04 nov. 2026', '04/11'];
        yield 'month in English' => ['en', 'month', 'Nov 04, 2026', '11/04'];
        yield 'year in French' => ['fr', 'year', 'nov. 2026', 'nov.'];
        yield 'year in English' => ['en', 'year', 'Nov 2026', 'Nov'];
    }

    #[Test]
    #[DataProvider('labels')]
    public function the_chart_labels_follow_the_locale(string $locale, string $range, string $label, string $axisLabel): void
    {
        $points = $this->factory()->build($range, null, new DateTimeImmutable('2026-11-04'))->points();
        $today = $points[array_key_last($points)];
        $translator = $this->translator($locale);

        self::assertSame($label, $today->label()->trans($translator));
        self::assertSame($axisLabel, $today->axisLabel()?->trans($translator));
    }

    #[Test]
    public function durations_and_large_counts_are_readable(): void
    {
        $metrics = $this->factory(durations: [3_600_000, 7_200_000], processed: 3_824_892)->build('week')->metrics();
        $values = [];

        foreach ($metrics as $metric) {
            self::assertInstanceOf(WorkflowStatisticsMetricView::class, $metric);
            $values[$metric->labelKey()] = $metric->value();
        }

        self::assertSame('1 h 30 min', $values['statistics_chart.metric_avg_duration']);
        self::assertSame('2 h 00 min', $values['statistics_chart.metric_p95_duration']);
        self::assertSame("3\u{00A0}824\u{00A0}892", $values['statistics_chart.metric_processed_total']);
    }

    #[Test]
    public function no_finished_run_shows_no_duration(): void
    {
        $metrics = $this->factory()->build('week')->metrics();

        self::assertSame('-', $metrics[0]->value());
    }

    private function translator(string $locale): Translator
    {
        $translator = new Translator($locale);
        $translator->addLoader('yaml', new YamlFileLoader());
        $translator->addResource('yaml', __DIR__ . '/../../translations/fluxx.' . $locale . '.yaml', $locale, 'fluxx');

        return $translator;
    }

    /**
     * @param list<int> $durations
     */
    private function factory(array $durations = [], int $processed = 0): WorkflowStatisticsViewFactory
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
        $connection->method('fetchFirstColumn')->willReturn($durations);
        $connection->method('fetchAssociative')->willReturn(['run_count' => 2]);
        $connection->method('fetchOne')->willReturn(0);
        $connection->method('fetchAllAssociative')->willReturnCallback(
            static fn (string $sql): array => str_contains($sql, 'step_name')
                ? [['step_name' => 'read', 'processed_total' => $processed]]
                : [],
        );

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getClassMetadata')->willReturn(new ClassMetadata(WorkflowRun::class));
        $entityManager->method('getConnection')->willReturn($connection);
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($entityManager);

        return new WorkflowStatisticsViewFactory(new WorkflowRunRepository($registry), new WorkflowStepRunStatistics($registry));
    }
}
