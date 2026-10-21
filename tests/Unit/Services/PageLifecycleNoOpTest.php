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

    public function testOwnerTransferWritesAggregateAndAuditOnlyForChangedOwner(): void
    {
        $state = new PageNoOpState(userExists: true);
        $service = $this->service($state, [[['owner_id' => 3]]]);

        $service->transferOwner(AuditActor::user(7), 42, 9);

        self::assertSame(1, $state->transactions);
        self::assertCount(1, $state->domainWrites);
        self::assertStringNotContainsString('created_by', $state->domainWrites[0]);
        self::assertCount(1, $state->auditWrites);
        self::assertSame(
            ['previous_owner_id' => 3, 'new_owner_id' => 9],
            json_decode((string) $state->auditBindings[0][7], true, flags: JSON_THROW_ON_ERROR),
        );
    }

    /**
     * Preda osirelou stranku a zachova null v auditni historii puvodniho vlastnika
     */
    public function testOrphanOwnerTransferWritesNullPreviousOwnerToAudit(): void
    {
        $state = new PageNoOpState(userExists: true);
        $service = $this->service($state, [[['owner_id' => null]]]);

        $service->transferOwner(AuditActor::user(7), 42, 9);

        self::assertCount(1, $state->domainWrites);
        self::assertStringNotContainsString('created_by', $state->domainWrites[0]);
        self::assertCount(1, $state->auditWrites);
        self::assertSame(
            ['previous_owner_id' => null, 'new_owner_id' => 9],
            json_decode((string) $state->auditBindings[0][7], true, flags: JSON_THROW_ON_ERROR),
        );
    }

    public function testOwnerTransferToCurrentOwnerIsAuditedNoOp(): void
    {
        $state = new PageNoOpState(userExists: true);
        $service = $this->service($state, [[['owner_id' => 3]]]);

        $service->transferOwner(AuditActor::user(7), 42, 3);

        self::assertSame(1, $state->transactions);
        self::assertSame([], $state->domainWrites);
        self::assertSame([], $state->auditWrites);
    }

    public function testOwnerTransferRejectsMissingOrDeletedLocalUser(): void
    {
        $state = new PageNoOpState(userExists: false);
        $service = $this->service($state, [[['owner_id' => 3]]]);

        try {
            $service->transferOwner(AuditActor::user(7), 42, 9);
            self::fail('Missing owner must be rejected.');
        } catch (\DomainException $exception) {
            self::assertSame('page.validation.owner_invalid', $exception->getMessage());
        }

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
            new LocalUserDirectory($database),
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
            static function (string $sql, array $bindings = []) use ($state): int {
                $state->auditWrites[] = $sql;
                $state->auditBindings[] = array_values($bindings);

                return 1;
            },
        );
        $connection->method('select')->willReturnCallback(
            static fn(string $sql): array => str_contains($sql, 'FROM system_user') && $state->userExists
                ? [['1' => 1]]
                : [],
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
            $result->method('row_array')->willReturn($rows[0] ?? null);

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

    public function __construct(public bool $userExists = false) {}

    /** @var list<string> */
    public array $domainWrites = [];

    /** @var list<string> */
    public array $auditWrites = [];

    /** @var list<list<mixed>> */
    public array $auditBindings = [];
}
