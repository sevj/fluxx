<?php

declare(strict_types=1);

namespace Fluxx\Tests\Ui;

use Fluxx\Ui\WorkflowDetails;
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
        $method->setAccessible(true);

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
    public function it_places_parallel_merge_nodes_on_distinct_rows(): void
    {
        $reflection = new ReflectionClass(WorkflowDetails::class);
        $details = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('createGraphNode');
        $method->setAccessible(true);

        $lanePaths = [[0], [1], [2]];
        $branchPaths = [
            'contacts_write' => [0],
            'deals_write' => [1],
            'products_write' => [2],
            'contacts_linker' => [],
            'product_line_items_linker' => [],
        ];

        $contactsLinker = new WorkflowStepDefinitionView(
            'linker',
            'Linker',
            'linker',
            null,
            'contacts_linker',
            'Contacts Linker',
            ['contacts_write', 'deals_write'],
            4,
        );
        $productLineItemsLinker = new WorkflowStepDefinitionView(
            'linker',
            'Linker',
            'linker',
            null,
            'product_line_items_linker',
            'Product Line Items Linker',
            ['products_write', 'deals_write'],
            4,
        );

        $contactsNode = $method->invoke($details, $contactsLinker, $branchPaths, $lanePaths, 5);
        $productNode = $method->invoke($details, $productLineItemsLinker, $branchPaths, $lanePaths, 5);

        self::assertSame(1, $contactsNode->rowSpan());
        self::assertSame(1, $productNode->rowSpan());
        self::assertNotSame($contactsNode->rowStart(), $productNode->rowStart());
    }
}
