<?php

declare(strict_types=1);

namespace Lemonade\Cms\Page\Tests\Unit\Models;

use Lemonade\Cms\Page\Models\PageModel;
use Lemonade\Framework\Database\DatabaseDriverInterface;
use Lemonade\Framework\Database\DatabaseResultInterface;
use PHPUnit\Framework\TestCase;

/**
 * Overuje databazovou projekci verejne publikovane stranky
 */
final class PagePublicProjectionContractTest extends TestCase
{
    /**
     * Verejny dotaz zachovava root metadata i lokalizovany obsah
     */
    public function testPublishedPageSelectsPageAndTranslationColumnsTogether(): void
    {
        $capturedSql = '';
        $result = $this->createMock(DatabaseResultInterface::class);
        $result->method('result_array')->willReturn([[
            'id' => 7,
            'parent_id' => null,
            'template_key' => 'content',
            'locale' => 'cs',
            'title' => 'Kontakt',
            'content' => null,
            'page_title' => null,
            'meta_description' => null,
            'published_at' => '2026-10-19 10:00:00',
        ]]);
        $driver = $this->createMock(DatabaseDriverInterface::class);
        $driver->method('protect_identifiers')->willReturnCallback(
            static fn(string $identifier): string => $identifier,
        );
        $driver->method('query')->willReturnCallback(
            static function (string $sql) use (&$capturedSql, $result): DatabaseResultInterface {
                $capturedSql = $sql;

                return $result;
            },
        );

        $page = (new PageModel($driver))->publishedPage(
            pageId: 7,
            locale: 'cs',
            now: '2026-10-19 11:00:00',
        );

        self::assertNotNull($page);
        self::assertStringContainsString('page.id', $capturedSql);
        self::assertStringContainsString('page.parent_id', $capturedSql);
        self::assertStringContainsString('page.template_key', $capturedSql);
        self::assertStringContainsString('translation.locale', $capturedSql);
        self::assertStringContainsString('translation.title', $capturedSql);
    }
}
