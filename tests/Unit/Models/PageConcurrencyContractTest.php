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
