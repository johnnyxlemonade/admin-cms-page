<?php

declare(strict_types=1);

namespace Lemonade\Cms\Page\Tests\Unit\Services;

use Lemonade\Admin\Audit\AuditActor;
use Lemonade\Admin\Audit\AuditLogService;
use Lemonade\Admin\Event\DomainEventDispatcher;
use Lemonade\Admin\Event\TransactionalEventProcessor;
use Lemonade\Admin\Localization\LanguageRegistry;
use Lemonade\Cms\Page\Models\PageModel;
use Lemonade\Cms\Page\Publication\PagePublicationPlanResolver;
use Lemonade\Cms\Page\Routing\PageSubtreeRouteMapper;
use Lemonade\Cms\Page\Services\PageService;
use Lemonade\Cms\Page\Template\PageTemplateRendererInterface;
use Lemonade\Cms\Routing\Cms\CmsRouteReservationService;
use Lemonade\Framework\Database\Connection\ConnectionInterface;
use Lemonade\Framework\Database\Database;
use Lemonade\Framework\Database\DatabaseDriverInterface;
use Lemonade\Framework\Database\DatabaseResultInterface;
use Lemonade\Framework\Support\Slug\Slugger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Overuje no-op lifecycle vetve bez databazovych integracnich testu
 */
final class PageLifecycleNoOpTest extends TestCase
{
    public function testRestoreOfActivePageReturnsBeforeAuditedTransaction(): void
    {
        $state = new PageNoOpState();
        $service = $this->service($state, [[[
            'id' => 7,
            'parent_id' => null,
            'deleted_at' => null,
        ]]]);

        $service->restore(AuditActor::user(3), 7);

        self::assertSame(0, $state->transactions);
        self::assertSame([], $state->domainWrites);
        self::assertSame([], $state->auditWrites);
    }

    public function testReorderAtSiblingBoundaryIsExplicitTransactionalNoOp(): void
    {
        $state = new PageNoOpState();
        $service = $this->service($state, [[[
            'id' => 7,
            'parent_id' => null,
            'sort_order' => 0,
            'template_key' => 'content',
            'updated_at' => '2026-10-19 12:00:00',
            'deleted_at' => null,
        ]]]);

        $service->reorder(AuditActor::user(3), 7, -1);

        self::assertSame(1, $state->transactions);
        self::assertTrue($state->readInsideTransaction);
        self::assertSame([], $state->domainWrites);
        self::assertSame([], $state->auditWrites);
    }

    /**
     * @param list<list<array<string,mixed>>> $selectRows
     */
    private function service(PageNoOpState $state, array $selectRows): PageService
    {
        $connection = $this->connection($state);
        $driver = $this->driver($state, $selectRows);
        $database = new Database($connection, $driver);
        $events = new TransactionalEventProcessor(
            $database,
            new AuditLogService($database),
            new DomainEventDispatcher($this->createMock(LoggerInterface::class)),
        );

        return new PageService(
            new PageModel($driver),
            $this->instanceWithoutConstructor(LanguageRegistry::class),
            $this->instanceWithoutConstructor(CmsRouteReservationService::class),
            new Slugger(),
            $events,
            $this->createMock(PageTemplateRendererInterface::class),
            new PageSubtreeRouteMapper(),
            new PagePublicationPlanResolver(),
        );
    }

    private function connection(PageNoOpState $state): ConnectionInterface
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('inTransaction')->willReturnCallback(
            static fn(): bool => $state->insideTransaction,
        );
        $connection->method('transaction')->willReturnCallback(
            static function (callable $callback) use ($connection, $state): mixed {
                ++$state->transactions;
                $state->insideTransaction = true;
                try {
                    return $callback($connection);
                } finally {
                    $state->insideTransaction = false;
                }
            },
        );
        $connection->method('statement')->willReturnCallback(
            static function (string $sql) use ($state): int {
                $state->auditWrites[] = $sql;

                return 1;
            },
        );

        return $connection;
    }

    /**
     * @param list<list<array<string,mixed>>> $selectRows
     */
    private function driver(PageNoOpState $state, array $selectRows): DatabaseDriverInterface
    {
        $results = array_map(function (array $rows): DatabaseResultInterface {
            $result = $this->createMock(DatabaseResultInterface::class);
            $result->method('result_array')->willReturn($rows);

            return $result;
        }, $selectRows);
        $driver = $this->createMock(DatabaseDriverInterface::class);
        $driver->method('protect_identifiers')->willReturnCallback(
            static fn(string $identifier): string => $identifier,
        );
        $driver->method('query')->willReturnCallback(
            static function (string $sql) use (&$results, $state): DatabaseResultInterface|bool {
                if (str_starts_with(strtolower(ltrim($sql)), 'select')) {
                    $state->readInsideTransaction = $state->readInsideTransaction || $state->insideTransaction;
                    $result = array_shift($results);
                    if (!$result instanceof DatabaseResultInterface) {
                        throw new \LogicException('Unexpected Page no-op read.');
                    }

                    return $result;
                }
                $state->domainWrites[] = $sql;

                return true;
            },
        );

        return $driver;
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
}

final class PageNoOpState
{
    public bool $insideTransaction = false;

    public bool $readInsideTransaction = false;

    public int $transactions = 0;

    /** @var list<string> */
    public array $domainWrites = [];

    /** @var list<string> */
    public array $auditWrites = [];
}
