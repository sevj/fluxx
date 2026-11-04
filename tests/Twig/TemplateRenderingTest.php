<?php

declare(strict_types=1);

namespace Fluxx\Tests\Twig;

use DateTimeImmutable;
use Fluxx\Twig\AssetExtension;
use Fluxx\Twig\DurationExtension;
use Fluxx\Ui\AssetVersion;
use Fluxx\Ui\WorkflowChoiceView;
use Fluxx\Ui\WorkflowStatisticsPointView;
use Fluxx\Ui\WorkflowStatisticsRangeView;
use Fluxx\Ui\WorkflowStatisticsView;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Component\Translation\Translator;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\Source;
use Twig\TwigFunction;

final class TemplateRenderingTest extends TestCase
{
    private const TEMPLATES = __DIR__ . '/../../templates';
    private const RELAUNCH_FORM = 'id="manual-operations"';
    private const RELAUNCH_LINK = 'href="#manual-operations"';

    #[Test]
    public function every_template_compiles(): void
    {
        $twig = $this->twig(admin: true);
        $templates = 0;

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::TEMPLATES, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            $name = '@Fluxx/' . substr($file->getPathname(), strlen(self::TEMPLATES) + 1);
            $twig->compileSource(new Source((string) file_get_contents($file->getPathname()), $name, $file->getPathname()));
            ++$templates;
        }

        self::assertGreaterThan(20, $templates);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function workflowStatuses(): iterable
    {
        yield 'pending' => ['pending', false];
        yield 'relaunched' => ['relaunched', false];
        yield 'retrying' => ['retrying', false];
        yield 'running' => ['running', false];
        yield 'payloads pruned' => ['payloads_pruned', false];
        yield 'completed' => ['completed', true];
        yield 'failed' => ['failed', true];
        yield 'partially failed' => ['partially_failed', true];
        yield 'cancelled' => ['cancelled', true];
    }

    #[Test]
    #[DataProvider('workflowStatuses')]
    public function an_admin_gets_the_step_relaunch_form_only_when_it_would_be_accepted(string $status, bool $expected): void
    {
        $html = $this->twig(admin: true)->render('@Fluxx/workflow/step_run_show.html.twig', [
            'stepRun' => $this->stepRun($status, $expected),
        ]);

        self::assertSame($expected, str_contains($html, self::RELAUNCH_FORM));
        self::assertSame($expected, str_contains($html, self::RELAUNCH_LINK));
    }

    #[Test]
    public function a_regular_user_gets_no_action_on_a_step(): void
    {
        $twig = $this->twig(admin: false);

        $finished = $twig->render('@Fluxx/workflow/step_run_show.html.twig', ['stepRun' => $this->stepRun('failed', true)]);
        $active = $twig->render('@Fluxx/workflow/step_run_show.html.twig', ['stepRun' => $this->stepRun('running', false)]);

        self::assertStringNotContainsString('manual-operations', $finished);
        self::assertStringNotContainsString('method="post"', $finished);
        self::assertStringNotContainsString('method="post"', $active);
    }

    #[Test]
    public function run_actions_on_the_run_page_are_reserved_to_admins(): void
    {
        foreach (['failed', 'running'] as $status) {
            $context = ['run' => $this->runView($status)];

            self::assertStringContainsString('method="post"', $this->twig(admin: true)->render('@Fluxx/workflow/run_show.html.twig', $context));
            self::assertStringNotContainsString('method="post"', $this->twig(admin: false)->render('@Fluxx/workflow/run_show.html.twig', $context));
        }
    }

    #[Test]
    public function only_an_active_run_refreshes_on_its_own(): void
    {
        $twig = $this->twig(admin: false);
        $active = $twig->render('@Fluxx/workflow/run_show.html.twig', ['run' => $this->runView('running') + ['isActive' => true]]);
        $finished = $twig->render('@Fluxx/workflow/run_show.html.twig', ['run' => $this->runView('completed') + ['isActive' => false]]);

        self::assertStringContainsString('data-run-detail-poll-toggle', $active);
        self::assertStringContainsString('run-detail', $active);
        self::assertStringNotContainsString('data-run-detail-poll-toggle', $finished);
        self::assertStringNotContainsString("run-detail.js", $finished);
    }

    #[Test]
    public function the_run_page_shows_its_parameters_and_the_runs_that_relaunched_it(): void
    {
        $html = $this->twig(admin: false)->render('@Fluxx/workflow/run_show.html.twig', ['run' => $this->runView('failed') + [
            'parametersJson' => '{"siret": "<123>"}',
            'relaunches' => [['runId' => 'run-2', 'status' => 'running', 'createdAt' => new DateTimeImmutable()]],
        ]]);

        self::assertStringContainsString('&lt;123&gt;', $html);
        self::assertStringContainsString('Relaunched as', $html);
        self::assertStringContainsString('run-2', $html);
        self::assertStringContainsString('status-badge-running', $html);
    }

    #[Test]
    public function a_finished_run_without_parameters_or_relaunch_shows_neither(): void
    {
        $html = $this->twig(admin: false)->render('@Fluxx/workflow/run_show.html.twig', ['run' => $this->runView('completed')]);

        self::assertStringNotContainsString('Launch parameters', $html);
        self::assertStringNotContainsString('Relaunched as', $html);
    }

    #[Test]
    public function the_actions_column_of_the_run_catalog_is_reserved_to_admins(): void
    {
        $context = [
            'runPage' => ['items' => [$this->runView('failed')], 'totalPages' => 1],
            'executionFilters' => ['searchQuery' => '', 'workflowCode' => '', 'status' => '', 'errorPresence' => '', 'dateFrom' => null, 'dateTo' => null, 'hasActiveFilters' => false],
        ];

        $admin = $this->twig(admin: true)->render('@Fluxx/runs/_run_catalog_content.html.twig', $context);
        $user = $this->twig(admin: false)->render('@Fluxx/runs/_run_catalog_content.html.twig', $context);

        self::assertStringContainsString('execution-cell-actions', $admin);
        self::assertStringContainsString('method="post"', $admin);
        self::assertStringNotContainsString('execution-cell-actions', $user);
        self::assertStringNotContainsString('method="post"', $user);
        self::assertSame(7, substr_count($admin, '<th>'));
        self::assertSame(6, substr_count($user, '<th>'));
        self::assertSame(7, substr_count($admin, '<col '));
        self::assertSame(6, substr_count($user, '<col '));
    }

    #[Test]
    public function the_actions_column_of_the_executions_tab_is_reserved_to_admins(): void
    {
        $context = [
            'workflow' => [
                'code' => 'contacts',
                'executionPage' => ['items' => [$this->runView('failed')], 'totalPages' => 1],
                'statistics' => ['selectedRange' => 'week'],
            ],
            'executionFilters' => ['searchQuery' => '', 'status' => '', 'errorPresence' => '', 'dateFrom' => null, 'dateTo' => null, 'hasActiveFilters' => false],
        ];

        $admin = $this->twig(admin: true)->render('@Fluxx/workflow/_tab_executions.html.twig', $context);
        $user = $this->twig(admin: false)->render('@Fluxx/workflow/_tab_executions.html.twig', $context);

        self::assertStringContainsString('execution-cell-actions', $admin);
        self::assertStringContainsString('fluxx_workflow_run_relaunch', $admin);
        self::assertStringNotContainsString('execution-cell-actions', $user);
        self::assertStringNotContainsString('fluxx_workflow_run_relaunch', $user);
    }

    #[Test]
    public function an_empty_statistics_page_keeps_its_filter_and_its_period_links(): void
    {
        $html = $this->twig(admin: true)->render('@Fluxx/statistics/index.html.twig', [
            'globalStatistics' => new WorkflowStatisticsView(
                selectedRange: 'week',
                ranges: [new WorkflowStatisticsRangeView('week', 'statistics_index.range_week', true), new WorkflowStatisticsRangeView('year', 'statistics_index.range_year', false)],
                points: [],
                metrics: [],
                stepMetrics: [],
                executionTotal: 0,
                errorTotal: 0,
                maxValue: 1,
                yAxisTicks: [1, 0],
                workflowCode: 'contacts',
            ),
            'workflowChoices' => [new WorkflowChoiceView('contacts', 'Contacts'), new WorkflowChoiceView('orders', 'Orders')],
        ]);

        self::assertStringContainsString('fluxx_statistics_index?workflow=contacts&amp;range=year', $html);
        self::assertStringContainsString('name="range" value="week"', $html);
        self::assertMatchesRegularExpression('/value="contacts" selected/', $html);
        self::assertDoesNotMatchRegularExpression('/value="orders" selected/', $html);
        self::assertStringContainsString('class="empty"', $html);
    }

    #[Test]
    public function the_chart_places_its_ticks_on_the_scale_of_the_bars_and_draws_nothing_for_an_empty_day(): void
    {
        $html = $this->twig(admin: true)->render('@Fluxx/_statistics_chart.html.twig', [
            'statistics' => new WorkflowStatisticsView(
                selectedRange: 'week',
                ranges: [],
                points: [
                    new WorkflowStatisticsPointView(new TranslatableMessage('statistics_chart.month_nov', [], 'fluxx'), new TranslatableMessage('statistics_chart.weekday_mon', [], 'fluxx'), 8, 2, 100.0, 25.0),
                    new WorkflowStatisticsPointView(new TranslatableMessage('statistics_chart.month_nov', [], 'fluxx'), null, 0, 0, 0.0, 0.0),
                ],
                metrics: [],
                stepMetrics: [],
                executionTotal: 8,
                errorTotal: 2,
                maxValue: 8,
                yAxisTicks: [8, 4, 0],
            ),
            'range_route' => 'fluxx_statistics_index',
            'range_params' => [],
        ]);

        self::assertSame(1, substr_count($html, 'statistics-bar-executions'));
        self::assertSame(1, substr_count($html, 'statistics-bar-errors'));
        self::assertSame(1, substr_count($html, 'statistics-axis-label'));
        self::assertStringContainsString('<span style="bottom: 100%;">8</span>', $html);
        self::assertStringContainsString('<span style="bottom: 50%;">4</span>', $html);
        self::assertStringContainsString('<span style="bottom: 0%;">0</span>', $html);
        self::assertSame(2, substr_count($html, 'statistics-gridline'));
    }

    #[Test]
    public function the_run_catalog_searches_and_filters_through_a_single_form(): void
    {
        $html = $this->twig(admin: true)->render('@Fluxx/runs/index.html.twig', [
            'runPage' => ['items' => [], 'totalPages' => 1],
            'executionFilters' => ['searchQuery' => 'contacts', 'status' => 'failed', 'errorPresence' => 'all', 'hasActiveFilters' => true],
            'workflowChoices' => [],
        ]);

        self::assertSame(1, substr_count($html, '<form'));
        self::assertSame(1, substr_count($html, 'type="submit"'));

        foreach (['q', 'workflow', 'status', 'errors', 'from', 'to'] as $field) {
            self::assertStringContainsString('name="' . $field . '"', $html);
        }

        self::assertStringContainsString('value="contacts"', $html);
        self::assertMatchesRegularExpression('/value="failed"\s+selected/', $html);
    }

    #[Test]
    public function the_run_count_is_part_of_the_refreshed_content(): void
    {
        $html = $this->twig(admin: true)->render('@Fluxx/runs/_run_catalog_content.html.twig', [
            'runPage' => ['items' => [$this->runView('failed')], 'totalPages' => 1, 'firstItemNumber' => 1, 'lastItemNumber' => 1, 'totalItems' => 1],
            'executionFilters' => ['hasActiveFilters' => false],
        ]);

        self::assertStringContainsString('list-count', $html);
    }

    #[Test]
    public function flashes_are_translated_and_the_page_declares_the_request_locale(): void
    {
        $html = $this->twig(admin: true, locale: 'fr_FR', flashes: [
            'success' => [new TranslatableMessage('flash.run_cancelled', ['%runId%' => 'abc'], 'fluxx')],
            'error' => ['An untranslated service error'],
        ])->render('@Fluxx/base.html.twig');

        self::assertStringContainsString('<html lang="fr-FR"', $html);
        self::assertStringContainsString('Exécution abc annulée.', $html);
        self::assertStringContainsString('An untranslated service error', $html);
    }

    #[Test]
    public function asset_urls_carry_the_computed_version(): void
    {
        $version = (new AssetVersion())->current();
        $html = $this->twig(admin: true)->render('@Fluxx/base.html.twig');

        self::assertStringContainsString('fluxx_workflow_stylesheet?v=' . $version, $html);
        self::assertStringContainsString('fluxx_script_asset?v=' . $version, $html);
        self::assertStringContainsString('fluxx_favicon_asset?v=' . $version, $html);
        self::assertStringContainsString('fluxx_logo_asset?v=' . $version, $html);
    }

    /**
     * @return iterable<string, array{string, string, array<string, mixed>}>
     */
    public static function livePages(): iterable
    {
        yield 'active runs' => ['@Fluxx/runs/active.html.twig', 'data-run-active-poll-toggle', []];
        yield 'run catalog' => ['@Fluxx/runs/index.html.twig', 'data-run-catalog-poll-toggle', [
            'runPage' => ['items' => [], 'totalPages' => 1],
            'executionFilters' => ['hasActiveFilters' => false],
            'workflowChoices' => [],
        ]];
        yield 'runtime' => ['@Fluxx/runtime/index.html.twig', 'data-runtime-poll-toggle', []];
        yield 'workflow' => ['@Fluxx/workflow/show.html.twig', 'data-executions-poll-toggle', [
            'workflow' => ['code' => 'contacts', 'name' => 'Contacts'],
        ]];
    }

    /**
     * @param array<string, mixed> $context
     */
    #[Test]
    #[DataProvider('livePages')]
    public function live_polling_controls_sit_in_the_page_header(string $template, string $toggle, array $context): void
    {
        $html = $this->twig(admin: true)->render($template, $context);
        $header = substr($html, (int) strpos($html, '<header'), (int) strpos($html, '</header>') - (int) strpos($html, '<header'));

        self::assertSame(1, substr_count($html, $toggle));
        self::assertStringContainsString($toggle, $header);
    }

    /**
     * @param array<string, list<mixed>> $flashes
     */
    private function twig(bool $admin, string $locale = 'en', array $flashes = []): Environment
    {
        $loader = new FilesystemLoader();
        $loader->addPath(self::TEMPLATES, 'Fluxx');

        $translator = new Translator(str_starts_with($locale, 'fr') ? 'fr' : 'en');
        $translator->addLoader('yaml', new YamlFileLoader());
        $translator->addResource('yaml', __DIR__ . '/../../translations/fluxx.en.yaml', 'en', 'fluxx');
        $translator->addResource('yaml', __DIR__ . '/../../translations/fluxx.fr.yaml', 'fr', 'fluxx');

        $twig = new Environment($loader, ['strict_variables' => false, 'autoescape' => 'html']);
        $twig->addExtension(new TranslationExtension($translator));
        $twig->addExtension(new DurationExtension());
        $twig->addExtension(new AssetExtension(new AssetVersion()));
        $twig->addFunction(new TwigFunction('path', static function (string $route, array $parameters = []): string {
            $query = isset($parameters['v']) ? ['v' => $parameters['v']] : $parameters;

            return $route . ($query !== [] ? '?' . http_build_query($query) : '');
        }));
        $twig->addFunction(new TwigFunction('csrf_token', static fn (string $id): string => 'token'));
        $twig->addFunction(new TwigFunction('is_granted', static fn (string $role): bool => $admin));
        $twig->addGlobal('app', [
            'request' => [
                'locale' => $locale,
                'attributes' => new class {
                    public function get(string $key): string
                    {
                        return 'fluxx_run_index';
                    }
                },
                'query' => new class {
                    public function get(string $key, ?string $default = null): ?string
                    {
                        return $default;
                    }

                    /**
                     * @return array<string, string>
                     */
                    public function all(): array
                    {
                        return [];
                    }
                },
            ],
            'flashes' => $flashes,
            'user' => null,
        ]);

        return $twig;
    }

    /**
     * @return array<string, mixed>
     */
    private function stepRun(string $workflowStatus, bool $canRelaunch): array
    {
        return [
            'workflowCode' => 'contacts',
            'workflowName' => 'Contacts',
            'runId' => 'run-1',
            'workflowStatus' => $workflowStatus,
            'canRelaunch' => $canRelaunch,
            'stepCode' => 'write',
            'stepName' => 'Write',
            'stepStatus' => 'completed',
            'deduplicationStatus' => 'none',
            'createdAt' => new DateTimeImmutable(),
            'metadata' => [],
            'inputPayloads' => [],
            'outputPayloads' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function runView(string $status): array
    {
        return [
            'runId' => 'run-1',
            'workflowCode' => 'contacts',
            'workflowName' => 'Contacts',
            'status' => $status,
            'trigger' => 'manual',
            'sourceSystem' => 'CSV',
            'targetSystem' => 'Hubspot',
            'createdAt' => new DateTimeImmutable(),
            'steps' => [],
            'timeline' => null,
        ];
    }
}
