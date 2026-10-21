<?php

declare(strict_types=1);

namespace Lemonade\Cms\Page\Tests\Unit\Services;

use Lemonade\Admin\Audit\AuditActor;
use Lemonade\Admin\Audit\AuditLogService;
use Lemonade\Admin\Event\DomainEventDispatcher;
use Lemonade\Admin\Event\TransactionalEventProcessor;
use Lemonade\Admin\Identity\LocalUserDirectory;
use Lemonade\Admin\Localization\LanguageRegistry;
use Lemonade\Cms\Page\Models\PageModel;
use Lemonade\Cms\Page\Publication\PagePublicationPlanResolver;
use Lemonade\Cms\Page\Routing\PageSubtreeRouteMapper;
use Lemonade\Cms\Page\Services\PageService;
use Lemonade\Cms\Page\Template\PageTemplateRendererInterface;
use Lemonade\Cms\Routing\Cms\CmsReservedPathGuard;
use Lemonade\Cms\Routing\Cms\CmsRouteCandidateResolver;
use Lemonade\Cms\Routing\Cms\CmsRouteRepository;
use Lemonade\Cms\Routing\Cms\CmsRouteReservationService;
use Lemonade\Cms\Routing\Locale\PublicLocaleRegistryInterface;
use Lemonade\Cms\Routing\Locale\PublicLocaleSnapshot;
use Lemonade\Framework\Database\Connection\ConnectionInterface;
use Lemonade\Framework\Database\Database;
use Lemonade\Framework\Database\DatabaseDriverInterface;
use Lemonade\Framework\Database\DatabaseResultInterface;
use Lemonade\Framework\Routing\ControllerAction;
use Lemonade\Framework\Routing\Router;
use Lemonade\Framework\Support\Slug\Slugger;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Overuje explicitni URL zmenu stranky a atomicky prepocet podstromu bez DB
 */
final class PageUrlSegmentTest extends TestCase
{
    /**
     * Zmena vlastniho segmentu prepocita potomky jen ve vybranem locale
     */
    public function testExplicitUrlChangeNormalizesAndRemapsLocalizedSubtreeAtomically(): void
    {
        $state = new PageUrlState([
            $this->route(7, 'cs', 'sekce'),
            $this->route(8, 'cs', 'sekce/kontakt'),
            $this->route(7, 'en', 'section'),
            $this->route(8, 'en', 'section/contact'),
        ]);
        $service = $this->service($state);

        $segment = $service->changeUrlSegment(AuditActor::user(4), 7, 'cs', 'Nová sekce!');

        self::assertSame('nova-sekce', $segment);
        self::assertSame('nova-sekce', $state->path(7, 'cs'));
        self::assertSame('nova-sekce/kontakt', $state->path(8, 'cs'));
        self::assertSame('section', $state->path(7, 'en'));
        self::assertSame('section/contact', $state->path(8, 'en'));
        self::assertSame(1, $state->transactions);
        self::assertCount(4, $state->routeWrites);
        self::assertCount(1, $state->domainWrites);
        self::assertCount(1, $state->auditWrites);
    }

    /**
     * Stejny segment neprepise routy podstromu ani audit
     */
    public function testExplicitUrlChangeNoOpWritesNeitherRoutesNorAudit(): void
    {
        $state = new PageUrlState([
            $this->route(7, 'cs', 'sekce'),
            $this->route(8, 'cs', 'sekce/kontakt'),
        ]);

        $segment = $this->service($state)->changeUrlSegment(
            AuditActor::user(4),
            7,
            'cs',
            'Sekce',
        );

        self::assertSame('sekce', $segment);
        self::assertSame([], $state->routeWrites);
        self::assertSame([], $state->domainWrites);
        self::assertSame([], $state->auditWrites);
    }

    private function service(PageUrlState $state): PageService
    {
        $connection = new PageUrlConnection($state);
        $driver = $this->driver($state);
        $database = new Database($connection, $driver);
        $router = new Router();
        $router->getNamed(
            'frontend.cms-route',
            '/{path:any}',
            ControllerAction::for(self::class, 'fallback'),
        );
        $routes = new CmsRouteReservationService(
            new CmsRouteRepository($database),
            new CmsRouteCandidateResolver(),
            new CmsReservedPathGuard(
                $router,
                new ServerRequest('GET', '/admin'),
                new PageUrlLocaleRegistry(),
            ),
        );
        $events = new TransactionalEventProcessor(
            $database,
            new AuditLogService($database),
            new DomainEventDispatcher($this->createMock(LoggerInterface::class)),
        );

        return new PageService(
            new PageModel($driver),
            $this->instanceWithoutConstructor(LanguageRegistry::class),
            $routes,
            new Slugger(),
            $events,
            $this->createMock(PageTemplateRendererInterface::class),
            new PageSubtreeRouteMapper(),
            new PagePublicationPlanResolver(),
            new LocalUserDirectory($database),
        );
    }

    private function driver(PageUrlState $state): DatabaseDriverInterface
    {
        $rows = [
            [
                ['id' => 7, 'parent_id' => null, 'sort_order' => 0, 'template_key' => 'content', 'updated_at' => '2026-10-19 12:00:00', 'deleted_at' => null],
                ['id' => 8, 'parent_id' => 7, 'sort_order' => 0, 'template_key' => 'content', 'updated_at' => '2026-10-19 12:00:00', 'deleted_at' => null],
            ],
            [[
                'id' => 17,
                'page_id' => 7,
                'locale' => 'cs',
                'title' => 'Sekce',
                'content' => null,
                'page_title' => null,
                'meta_description' => null,
                'publication_state' => 1,
                'published_at' => null,
            ]],
            array_map(
                static fn(array $route): array => [
                    'entity_id' => $route['entity_id'],
                    'locale' => $route['locale'],
                    'path' => $route['path'],
                ],
                $state->routes,
            ),
        ];
        $results = array_map(function (array $resultRows): DatabaseResultInterface {
            $result = $this->createMock(DatabaseResultInterface::class);
            $result->method('result_array')->willReturn($resultRows);
            $result->method('row_array')->willReturn($resultRows[0] ?? null);

            return $result;
        }, $rows);
        $driver = $this->createMock(DatabaseDriverInterface::class);
        $driver->method('protect_identifiers')->willReturnCallback(
            static fn(string $identifier): string => $identifier,
        );
        $driver->method('query')->willReturnCallback(
            static function (string $sql) use (&$results, $state): DatabaseResultInterface|bool {
                self::assertTrue($state->insideTransaction, 'Page hierarchy reads must stay inside the URL transaction.');
                if (!str_starts_with(strtolower(ltrim($sql)), 'select')) {
                    $state->domainWrites[] = $sql;

                    return true;
                }
                $result = array_shift($results);
                if (!$result instanceof DatabaseResultInterface) {
                    throw new \LogicException('Unexpected Page URL read.');
                }

                return $result;
            },
        );

        return $driver;
    }

    /** @return array{id:int,module_code:string,entity_id:int,locale:string,path:string} */
    private function route(int $entityId, string $locale, string $path): array
    {
        return [
            'id' => $entityId * 10 + ($locale === 'cs' ? 1 : 2),
            'module_code' => 'cms.page',
            'entity_id' => $entityId,
            'locale' => $locale,
            'path' => $path,
        ];
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function instanceWithoutConstructor(string $class): object
    {
        return (new \ReflectionClass($class))->newInstanceWithoutConstructor();
    }

    public function fallback(): void {}
}

/**
 * Poskytuje locale snapshot pro reserved-path kontrolu
 */
final readonly class PageUrlLocaleRegistry implements PublicLocaleRegistryInterface
{
    public function snapshot(): PublicLocaleSnapshot
    {
        return PublicLocaleSnapshot::fromRows([
            ['code' => 'cs', 'enabled' => 1, 'is_default' => 1],
            ['code' => 'en', 'enabled' => 1, 'is_default' => 0],
        ]);
    }
}

final class PageUrlState
{
    public bool $insideTransaction = false;

    public int $transactions = 0;

    /** @var list<string> */
    public array $routeWrites = [];

    /** @var list<string> */
    public array $auditWrites = [];

    /** @var list<string> */
    public array $domainWrites = [];

    /** @param list<array{id:int,module_code:string,entity_id:int,locale:string,path:string}> $routes */
    public function __construct(public array $routes) {}

    public function path(int $entityId, string $locale): ?string
    {
        foreach ($this->routes as $route) {
            if ($route['entity_id'] === $entityId && $route['locale'] === $locale) {
                return $route['path'];
            }
        }

        return null;
    }
}

final readonly class PageUrlConnection implements ConnectionInterface
{
    public function __construct(private PageUrlState $state) {}

    public function select(string $sql, array $bindings = []): array
    {
        if (str_contains($sql, 'module_code = ?')) {
            return array_values(array_filter(
                $this->state->routes,
                static fn(array $route): bool => $route['module_code'] === ($bindings[0] ?? null)
                    && $route['entity_id'] === ($bindings[1] ?? null)
                    && $route['locale'] === ($bindings[2] ?? null),
            ));
        }

        return array_values(array_filter(
            $this->state->routes,
            static fn(array $route): bool => $route['locale'] === ($bindings[0] ?? null),
        ));
    }

    public function cursor(string $sql, array $bindings = []): \Generator
    {
        yield from [];
    }

    public function statement(string $sql, array $bindings = []): int
    {
        if (!$this->state->insideTransaction) {
            throw new \LogicException('Page URL writes must stay inside the audited transaction.');
        }
        if (str_contains($sql, 'cms_route')) {
            foreach ($this->state->routes as &$route) {
                if ($route['module_code'] === ($bindings[2] ?? null)
                    && $route['entity_id'] === ($bindings[3] ?? null)
                    && $route['locale'] === ($bindings[4] ?? null)
                ) {
                    $route['path'] = (string) ($bindings[0] ?? '');
                }
            }
            unset($route);
            $this->state->routeWrites[] = $sql;

            return 1;
        }
        $this->state->auditWrites[] = $sql;

        return 1;
    }

    public function beginTransaction(): void {}

    public function commit(): void {}

    public function rollBack(): void {}

    public function inTransaction(): bool
    {
        return $this->state->insideTransaction;
    }

    public function transaction(callable $callback): mixed
    {
        if ($this->state->insideTransaction) {
            return $callback($this);
        }
        ++$this->state->transactions;
        $this->state->insideTransaction = true;
        try {
            return $callback($this);
        } finally {
            $this->state->insideTransaction = false;
        }
    }

    public function lastInsertId(): int|string|null
    {
        return null;
    }

    public function affectedRows(): int
    {
        return 0;
    }

    public function reconnect(): void {}

    public function close(): void {}

    public function serverVersion(): string
    {
        return 'test';
    }

    public function escapeString(string $value): string
    {
        return $value;
    }
}
