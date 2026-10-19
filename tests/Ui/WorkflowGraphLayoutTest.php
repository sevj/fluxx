<?php

declare(strict_types=1);

namespace Fluxx\Tests\Ui;

use Fluxx\Ui\WorkflowDetails;
use Fluxx\Ui\WorkflowGraphEdgeView;
use Fluxx\Ui\WorkflowStepDefinitionView;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class WorkflowGraphLayoutTest extends TestCase
{
    #[Test]
    public function it_keeps_distinct_terminal_lanes_for_parallel_branches(): void
    {
        $reflection = new ReflectionClass(WorkflowDetails::class);
        $details = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('computeTerminalLanePaths');

        $lanePaths = $method->invoke($details, [
            'companies_transform' => [0],
            'contacts_transform' => [1],
            'companies_write' => [0],
            'contacts_write' => [1],
            'contacts_linker' => [],
        ]);

        self::assertSame([[0], [1]], $lanePaths);
    }

    #[Test]
    public function it_orders_terminal_lanes_in_reading_order_across_levels(): void
    {
        $reflection = new ReflectionClass(WorkflowDetails::class);
        $details = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('computeTerminalLanePaths');

        $lanePaths = $method->invoke($details, [
            'root_a' => [0],
            'root_c' => [2],
            'root_b_first' => [1, 0],
            'root_b_second' => [1, 1],
        ]);

        self::assertSame([[0], [1, 0], [1, 1], [2]], $lanePaths);
    }

    #[Test]
    public function it_nests_wide_split_columns_inside_their_parent_span(): void
    {
        $reflection = new ReflectionClass(WorkflowDetails::class);
        $details = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('buildGraphRows');

        $graph = $method->invoke($details, $this->characterRefreshSteps());

        $rowsByCode = [];
        foreach ($graph['rows'] as $row) {
            foreach ($row->nodes() as $node) {
                $rowsByCode[$node->step()->code()] = [$node->rowStart(), $node->rowSpan()];
            }
        }

        self::assertSame([1, 8], $rowsByCode['verify_character']);
        self::assertSame([2, 6], $rowsByCode['battlenet_sync']);
        self::assertSame([1, 1], $rowsByCode['raiderio_sync']);
        self::assertSame([8, 1], $rowsByCode['warcraftlogs_sync']);
        self::assertSame([1, 8], $rowsByCode['mercure_notify']);

        foreach (['battlenet_gear', 'battlenet_achievements', 'battlenet_encounters', 'battlenet_pvp', 'battlenet_reputations', 'battlenet_statistics'] as $index => $code) {
            self::assertSame([$index + 2, 1], $rowsByCode[$code], "column of $code");
        }

        self::assertSame(4, $graph['columnCount']);
        self::assertSame(8, $graph['rowCount']);

        $edges = $graph['edges'];
        $pairs = [];

        foreach ($this->characterRefreshSteps() as $step) {
            foreach ($step->dependsOn() as $dependency) {
                $pairs[] = [$dependency, $step->code()];
            }
        }

        self::assertCount(count($pairs), $edges);

        $fanInEntries = [];

        foreach ($edges as $index => $edge) {
            [$from, $to] = $pairs[$index];
            $points = explode(' ', (string) preg_replace('/\s+/', ' ', $edge->path()));

            self::assertSame('M', $points[0]);
            self::assertSame('C', $points[3]);

            [$rowStart, $rowSpan] = $rowsByCode[$from];
            $laneY = ($rowStart - 1) * 120 + ($rowSpan * 120) / 2;

            self::assertEqualsWithDelta(
                $laneY,
                (float) $points[2],
                0.5,
                sprintf('edge %s -> %s leaves at the source lane', $from, $to),
            );

            if ($to !== 'mercure_notify') {
                [$toRowStart, $toRowSpan] = $rowsByCode[$to];
                $toCenterY = ($toRowStart - 1) * 120 + ($toRowSpan * 120) / 2;

                self::assertEqualsWithDelta(
                    (float) $points[9],
                    $toCenterY,
                    0.5,
                    sprintf('edge %s -> %s enters at the target card center', $from, $to),
                );

                continue;
            }

            $fanInEntries[] = [$laneY, (float) $points[9], $from];
        }

        usort($fanInEntries, static fn (array $left, array $right): int => $left[0] <=> $right[0]);

        $mergeCenterY = (1 - 1) * 120 + (8 * 120) / 2;
        $entryTop = $mergeCenterY - WorkflowGraphEdgeView::CARD_HEIGHT / 2 + WorkflowGraphEdgeView::CARD_END_INSET;
        $entryBottom = $mergeCenterY + WorkflowGraphEdgeView::CARD_HEIGHT / 2 - WorkflowGraphEdgeView::CARD_END_INSET;

        $previousEndY = null;

        foreach ($fanInEntries as [$laneY, $endY, $from]) {
            self::assertGreaterThanOrEqual(
                $entryTop - 0.5,
                $endY,
                sprintf('edge %s -> mercure_notify lands on the merge card', $from),
            );
            self::assertLessThanOrEqual(
                $entryBottom + 0.5,
                $endY,
                sprintf('edge %s -> mercure_notify lands on the merge card', $from),
            );

            if ($previousEndY !== null) {
                self::assertGreaterThan(
                    $previousEndY + 1,
                    $endY,
                    sprintf('edge from lane %.0f enters above its predecessor', $laneY),
                );
            }

            $previousEndY = $endY;
        }

        self::assertEqualsWithDelta($entryTop, $fanInEntries[0][1], 0.5);
        self::assertEqualsWithDelta($entryBottom, $fanInEntries[count($fanInEntries) - 1][1], 0.5);
    }

    /**
     * Returns the lane coverage the Wide-split layout produces for a
     * character refresh-shaped graph: a root fanning out to three branches
     * where the middle one splays into six parallel children feeding a merge.
     *
     * @return list<WorkflowStepDefinitionView>
     */
    private function characterRefreshSteps(): array
    {
        $level = ['verify_character' => 0];
        $dependsOn = [
            'verify_character' => [],
            'raiderio_sync' => ['verify_character'],
            'battlenet_sync' => ['verify_character'],
            'warcraftlogs_sync' => ['verify_character'],
            'battlenet_gear' => ['battlenet_sync'],
            'battlenet_achievements' => ['battlenet_sync'],
            'battlenet_encounters' => ['battlenet_sync'],
            'battlenet_pvp' => ['battlenet_sync'],
            'battlenet_reputations' => ['battlenet_sync'],
            'battlenet_statistics' => ['battlenet_sync'],
            'mercure_notify' => [
                'battlenet_gear',
                'battlenet_achievements',
                'battlenet_encounters',
                'battlenet_pvp',
                'battlenet_reputations',
                'battlenet_statistics',
                'raiderio_sync',
                'warcraftlogs_sync',
            ],
        ];

        $resolve = function (string $code) use (&$resolve, &$level, $dependsOn): int {
            if (isset($level[$code])) {
                return $level[$code];
            }

            $level[$code] = 0;

            foreach ($dependsOn[$code] as $dependency) {
                $level[$code] = max($level[$code], $resolve($dependency) + 1);
            }

            return $level[$code];
        };

        $steps = [];

        foreach ($dependsOn as $code => $dependencies) {
            $steps[] = new WorkflowStepDefinitionView(
                $code,
                $code,
                '',
                null,
                $code,
                str_replace('_', ' ', $code),
                $dependencies,
                $resolve($code),
            );
        }

        return $steps;
    }
}
