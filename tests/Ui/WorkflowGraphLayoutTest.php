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

    #[Test]
    public function it_keeps_shared_dependency_chains_on_compact_entity_lanes(): void
    {
        $reflection = new ReflectionClass(WorkflowDetails::class);
        $details = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('buildGraphRows');

        $graph = $method->invoke($details, $this->sharedDependencyFanoutSteps());

        $rowsByCode = [];
        foreach ($graph['rows'] as $row) {
            foreach ($row->nodes() as $node) {
                $rowsByCode[$node->step()->code()] = ($node->rowStart() - 1) . '+' . $node->rowSpan();
            }
        }

        self::assertSame(9, $graph['columnCount']);
        self::assertSame(3, $graph['rowCount'], 'lanes must stay compact: one lane per entity chain, no empty middle lanes');

        // Spine steps and the fan-in merge span the whole entity fan.
        foreach (['pipeline_root', 'users_sync', 'companies_write', 'association_fetch', 'association_link'] as $code) {
            self::assertSame('0+3', $rowsByCode[$code], "widened card $code");
        }

        // Every entity chain keeps its three steps stacked on one dedicated lane.
        $entityLanes = [];

        foreach (['contacts', 'invoices', 'marches'] as $index => $entity) {
            [$fetch, $transform, $write] = [
                "efficy_{$entity}_fetch",
                "efficy_{$entity}_transform",
                "hubspot_{$entity}_write",
            ];

            self::assertSame($rowsByCode[$fetch], $rowsByCode[$transform], "$entity chain lane drift");
            self::assertSame($rowsByCode[$fetch], $rowsByCode[$write], "$entity chain lane drift");

            $lanes = $entityLanes;
            $lanes[] = $rowsByCode[$fetch];
            self::assertSame(count(array_unique($lanes)), count($lanes), "$entity lane collides with a sibling chain");

            $entityLanes[] = $rowsByCode[$fetch];
        }

        self::assertSame(['0+1', '1+1', '2+1'], $entityLanes, 'entity chains follow branch reading order');
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

    /**
     * Returns the lane coverage for a synchronization-shaped graph: a spine
     * where every entity transform also depends on a shared "users" step, so
     * naive lane unions would expand every transform to a full-graph spanner
     * and scatter the whole fan across dozens of collision lanes.
     *
     * @return list<WorkflowStepDefinitionView>
     */
    private function sharedDependencyFanoutSteps(): array
    {
        $entityChain = static function (string $entity): array {
            return [
                "efficy_{$entity}_fetch" => ['companies_write'],
                "efficy_{$entity}_transform" => ["efficy_{$entity}_fetch", 'users_sync'],
                "hubspot_{$entity}_write" => ["efficy_{$entity}_transform"],
            ];
        };

        $dependsOn = [
            'pipeline_root' => [],
            'companies_sync' => ['pipeline_root'],
            'users_sync' => ['companies_sync'],
            'companies_write' => ['companies_sync', 'users_sync'],
            ...$entityChain('contacts'),
            ...$entityChain('invoices'),
            ...$entityChain('marches'),
            'association_fetch' => [
                'hubspot_contacts_write',
                'hubspot_invoices_write',
                'hubspot_marches_write',
            ],
            'association_link' => ['association_fetch'],
        ];

        return $this->stepsFromDependsOn($dependsOn);
    }

    /**
     * @param array<string, list<string>> $dependsOn
     * @return list<WorkflowStepDefinitionView>
     */
    private function stepsFromDependsOn(array $dependsOn): array
    {
        $level = [];
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
