<?php

declare(strict_types=1);

namespace Lemonade\Cms\Page\Tests\Unit\Models;

use Lemonade\Cms\Page\Models\PageModel;
use Lemonade\Cms\Page\Services\PageService;
use Lemonade\Framework\Database\DatabaseDriverInterface;
use Lemonade\Framework\Database\DatabaseResultInterface;
use PHPUnit\Framework\TestCase;

/**
 * Overuje zamykaci kontrakt concurrent first-save bez databazove integrace
 */
final class PageConcurrencyContractTest extends TestCase
{
    /**
     * Novy root uklada stejneho lokalniho uzivatele jako tvurce i vlastnika
     */
    public function testCreateDraftPersistsCreatorAndOwnerOnRoot(): void
    {
        $queries = [];
        $bindings = [];
        $maximum = $this->createMock(DatabaseResultInterface::class);
        $maximum->method('row_array')->willReturn(['maximum_order' => -1]);
        $driver = $this->createMock(DatabaseDriverInterface::class);
        $driver->method('protect_identifiers')->willReturnCallback(
            static fn(string $identifier): string => $identifier,
        );
        $driver->method('query')->willReturnCallback(
            static function (string $sql, array|false $queryBindings = []) use (&$queries, &$bindings, $maximum): DatabaseResultInterface|bool {
                $queries[] = $sql;
                $bindings[] = $queryBindings === false ? [] : array_values($queryBindings);

                return str_starts_with($sql, 'SELECT') ? $maximum : true;
            },
        );
        $driver->method('insert_id')->willReturn(12);

        $pageId = (new PageModel($driver))->createDraft(7, 'cs', 'content', '2026-10-21 10:00:00');

        self::assertSame(12, $pageId);
        self::assertStringContainsString('created_by', $queries[1]);
        self::assertStringContainsString('owner_id', $queries[1]);
        self::assertSame([7, 7], array_slice($bindings[1], 0, 2));
    }

    /**
     * Aggregate root i existujici mutace se ctou pres write lock
     */
    public function testTreeAndTranslationReadsUseWriteLocks(): void
    {
        $queries = [];
        $result = $this->createMock(DatabaseResultInterface::class);
        $result->method('result_array')->willReturn([]);
        $driver = $this->createMock(DatabaseDriverInterface::class);
        $driver->method('protect_identifiers')->willReturnCallback(
            static fn(string $identifier): string => $identifier,
        );
        $driver->method('query')->willReturnCallback(
            static function (string $sql) use (&$queries, $result): DatabaseResultInterface {
                $queries[] = $sql;

                return $result;
            },
        );
        $pages = new PageModel($driver);

        $pages->activeTreeForUpdate();
        $pages->translationForUpdate(7, 'cs');

        self::assertCount(2, $queries);
        self::assertStringContainsString('FOR UPDATE', $queries[0]);
        self::assertStringContainsString('FOR UPDATE', $queries[1]);
    }

    /**
     * Service zamkne aggregate drive nez autoritativne znovu nacte mutation
     */
    public function testServiceReloadsTranslationAfterTreeLock(): void
    {
        $fileName = (new \ReflectionClass(PageService::class))->getFileName();
        self::assertIsString($fileName);
        $source = file_get_contents($fileName);
        self::assertIsString($source);
        $treeLock = strpos($source, '$this->pages->activeTreeForUpdate()');
        $translationLock = strpos($source, '$this->pages->translationForUpdate($pageId, $locale)');

        self::assertIsInt($treeLock);
        self::assertIsInt($translationLock);
        self::assertLessThan($translationLock, $treeLock);
    }

    /**
     * Hierarchicky save spousti publication persistence jen pri skutecne zmene
     */
    public function testHierarchySaveDoesNotReprocessUnchangedPublication(): void
    {
        $fileName = (new \ReflectionClass(PageService::class))->getFileName();
        self::assertIsString($fileName);
        $source = file_get_contents($fileName);
        self::assertIsString($source);

        self::assertStringContainsString('if ($publication->changed)', $source);
        self::assertStringNotContainsString('assertPublishedLocalesCanMove', $source);
    }
}
