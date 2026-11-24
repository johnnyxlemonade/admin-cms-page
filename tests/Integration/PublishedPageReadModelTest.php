<?php

declare(strict_types=1);

namespace Lemonade\Cms\Page\Tests\Integration;

use Lemonade\Admin\File\FileReadModel;
use Lemonade\Admin\File\PublishedMediaInterface;
use Lemonade\Admin\Platform\Migrations\CreateCoreFiles;
use Lemonade\Cms\Page\Migrations\CreateCmsPages;
use Lemonade\Cms\Page\Public\PublishedPageReadModel;
use Lemonade\Cms\Routing\Cms\PublicCmsUrlBuilder;
use Lemonade\Cms\Routing\Locale\PublicLocaleResolution;
use Lemonade\Cms\Routing\Module\PublicModuleStateResolverInterface;
use Lemonade\Framework\Database\Connection\DatabaseConfig;
use Lemonade\Framework\Database\Database;
use Lemonade\Framework\Database\Driver\Mysql\MysqlConnection;
use Lemonade\Framework\Database\Driver\Mysql\MysqlDatabaseDriver;
use Lemonade\Framework\Database\Driver\Mysql\MysqlIdentifierEscaper;
use Lemonade\Framework\Database\Driver\Mysql\MysqlSchemaGrammar;
use Lemonade\Framework\Database\Driver\Mysql\MysqlSqlEscaper;
use Lemonade\Framework\Database\Instrumentation\Internal\DatabaseInstrumentation;
use Lemonade\Framework\Database\Instrumentation\Internal\DatabaseMeasurements;
use Lemonade\Framework\Database\Schema\Schema;
use Lemonade\Framework\Database\Schema\SchemaCompiler;
use Lemonade\Framework\Database\Sql\IdentifierProtector;
use PHPUnit\Framework\TestCase;

/**
 * Verifies public page visibility, hierarchy and batched media against MySQL
 */
final class PublishedPageReadModelTest extends TestCase
{
    /**
     * Covers visibility invariants and public projections in one isolated database
     */
    public function testPublishedPagesAndHierarchy(): void
    {
        $name = getenv('PAGE_PUBLIC_INTEGRATION_DATABASE');
        self::assertIsString($name);
        self::assertStringStartsWith('cms_page_test_', $name);
        $config = DatabaseConfig::fromArray([
            'driver' => 'mysql',
            'host' => 'db',
            'database' => $name,
            'username' => 'db',
            'password' => 'db',
        ]);
        $instrumentation = new DatabaseInstrumentation();
        $connection = new MysqlConnection($config, $instrumentation);
        $escaper = new MysqlIdentifierEscaper('');
        $driver = new MysqlDatabaseDriver($connection, $config, $escaper, new IdentifierProtector($escaper));
        $database = new Database($connection, $driver);
        $database->statement('CREATE TABLE system_user (id BIGINT UNSIGNED PRIMARY KEY)');
        $database->statement('CREATE TABLE system_language (code VARCHAR(10) PRIMARY KEY, enabled TINYINT, sort_order INT, is_default TINYINT)');
        $database->statement('CREATE TABLE cms_route (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, module_code VARCHAR(100), entity_id BIGINT UNSIGNED, locale VARCHAR(10), path VARCHAR(700), created_at DATETIME, updated_at DATETIME, deleted_at DATETIME NULL, UNIQUE KEY uq_target (module_code,entity_id,locale))');
        $database->statement("INSERT INTO system_language VALUES ('cs',1,1,1),('en',1,2,0),('de',0,3,0)");
        $schema = new Schema(new SchemaCompiler(new MysqlSchemaGrammar(new MysqlSqlEscaper($escaper), $config)), $driver);
        (new CreateCmsPages())->up($schema);
        (new CreateCoreFiles())->up($schema);

        foreach ([
            [1, null, 0, 'default', null],
            [2, 1, 0, 'content', null],
            [3, 1, 1, 'content', null],
            [4, 1, 2, 'content', null],
            [5, 4, 0, 'content', null],
            [6, null, 0, 'content', null],
            [7, null, 0, 'content', null],
            [8, null, 0, 'content', null],
            [9, null, 0, 'content', '2025-01-01 00:00:00'],
            [10, null, 0, 'content', null],
            [11, 2, 0, 'content', null],
            [12, 1, 3, 'content', null],
        ] as [$id, $parentId, $sortOrder, $templateKey, $deletedAt]) {
            $database->statement(
                'INSERT INTO cms_page (id,parent_id,sort_order,template_key,created_at,updated_at,deleted_at) VALUES (?,?,?,?,?,?,?)',
                [$id, $parentId, $sortOrder, $templateKey, '2025-01-01 00:00:00', '2025-01-02 00:00:00', $deletedAt],
            );
        }
        $database->statement('UPDATE cms_page SET parent_id = 7 WHERE id = 6');
        $database->statement('UPDATE cms_page SET parent_id = 6 WHERE id = 7');

        foreach ([
            [1, 'cs', 2, 'Kořen', 'Úvod', '<p>Obsah</p>', 'SEO název', 'SEO popis', '2020-01-01 00:00:00'],
            [1, 'en', 2, 'Root', null, null, null, null, '2020-01-01 00:00:00'],
            [2, 'cs', 2, 'Dítě', null, null, null, null, '2020-01-01 00:00:00'],
            [2, 'en', 2, 'Child', null, null, null, null, '2020-01-01 00:00:00'],
            [2, 'de', 2, 'Kind', null, null, null, null, '2020-01-01 00:00:00'],
            [3, 'cs', 2, 'Budoucí', null, null, null, null, '2999-01-01 00:00:00'],
            [4, 'cs', 1, 'Koncept', null, null, null, null, null],
            [5, 'cs', 2, 'Skryté dítě', null, null, null, null, '2020-01-01 00:00:00'],
            [6, 'cs', 2, 'Cyklus A', null, null, null, null, '2020-01-01 00:00:00'],
            [7, 'cs', 2, 'Cyklus B', null, null, null, null, '2020-01-01 00:00:00'],
            [8, 'cs', 2, 'Bez routy', null, null, null, null, '2020-01-01 00:00:00'],
            [9, 'cs', 2, 'Smazaná', null, null, null, null, '2020-01-01 00:00:00'],
            [10, 'cs', 2, 'Neaktivní route', null, null, null, null, '2020-01-01 00:00:00'],
            [11, 'cs', 2, 'Vnouče', null, null, null, null, '2020-01-01 00:00:00'],
            [12, 'cs', 2, 'Sourozenec', null, null, null, null, '2020-01-01 00:00:00'],
        ] as [$id, $language, $state, $title, $summary, $content, $pageTitle, $description, $publishedAt]) {
            $database->statement(
                'INSERT INTO cms_page_translation (page_id,locale,publication_state,title,summary,content,page_title,meta_description,published_at,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                [$id, $language, $state, $title, $summary, $content, $pageTitle, $description, $publishedAt, '2025-01-01 00:00:00', '2025-01-03 00:00:00'],
            );
        }

        foreach ([
            [1, 'cs', 'root', null], [1, 'en', 'root', null],
            [2, 'cs', 'root/child', null], [2, 'en', 'root/child', null], [2, 'de', 'root/kind', null],
            [3, 'cs', 'root/future', null], [4, 'cs', 'root/draft', null], [5, 'cs', 'root/draft/hidden', null],
            [6, 'cs', 'cycle-a', null], [7, 'cs', 'cycle-b', null],
            [9, 'cs', 'deleted', null], [10, 'cs', 'inactive', '2025-01-01 00:00:00'],
            [11, 'cs', 'root/child/grandchild', null],
            [12, 'cs', 'root/sibling', null],
        ] as [$id, $language, $path, $deletedAt]) {
            $database->statement(
                'INSERT INTO cms_route (module_code,entity_id,locale,path,created_at,updated_at,deleted_at) VALUES (?,?,?,?,?,?,?)',
                ['cms.page', $id, $language, $path, '2025-01-01 00:00:00', $id === 2 && $language === 'cs' ? '2025-01-04 00:00:00' : '2025-01-02 00:00:00', $deletedAt],
            );
        }

        foreach ([
            [20, 'thumbnail', 'image', 0, 'asset-20', 'v1', null],
            [30, 'gallery', 'image', 2, 'asset-30', 'v1', null],
            [31, 'gallery', 'image', 1, 'asset-31', 'v1', null],
            [32, 'gallery', 'image', 3, null, null, null],
            [40, 'attachment', 'file', 2, null, null, 'docs/40.pdf'],
            [41, 'attachment', 'file', 1, null, null, 'docs/41.pdf'],
        ] as [$id, $usage, $kind, $order, $assetId, $version, $storagePath]) {
            $database->statement(
                'INSERT INTO system_file (id,module_code,entity_id,`usage`,kind,media_category,asset_id,source_version,storage_path,original_filename,extension,mime_type,file_size,sort_order,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [$id, 'cms.page', 2, $usage, $kind, $kind === 'image' ? 'image' : 'document', $assetId, $version, $storagePath, (string) $id, $kind === 'image' ? 'jpg' : 'pdf', $kind === 'image' ? 'image/jpeg' : 'application/pdf', 123, $order, '2025-01-01 00:00:00', '2025-01-02 00:00:00'],
            );
        }

        $modules = self::createStub(PublicModuleStateResolverInterface::class);
        $modules->method('isDiscoveredInstalledAndEnabled')->willReturn(true);
        $reader = new PublishedPageReadModel($database, new FileReadModel($driver), new PublicCmsUrlBuilder(), $modules);
        $cs = PublicLocaleResolution::route('cs', 'cs', 'root/child');
        $de = PublicLocaleResolution::route('cs', 'de', 'root/kind');
        $page = $reader->record(2, $cs);
        self::assertNotNull($page);
        self::assertInstanceOf(PublishedMediaInterface::class, $page);
        self::assertSame('content', $page->getTemplateKey());
        self::assertSame(1, $page->getParentId());
        self::assertSame('/root/child', $page->getPublicUrl());
        self::assertSame('2020-01-01', $page->getPublishedAt()->format('Y-m-d'));
        self::assertSame(20, $page->getThumbnail()?->id());
        self::assertSame([31, 30], array_map(static fn($file): int => $file->id(), $page->getGallery()));
        self::assertSame([41, 40], array_map(static fn($file): int => $file->id(), $page->getAttachments()));
        $root = $reader->record(1, $cs);
        self::assertNotNull($root);
        self::assertSame('Úvod', $root->getSummary());
        self::assertSame('<p>Obsah</p>', $root->getContent());
        self::assertSame('SEO název', $root->getPageTitle());
        self::assertSame('SEO popis', $root->getMetaDescription());
        self::assertNull($root->getThumbnail());
        self::assertSame([], $root->getGallery());
        self::assertSame([], $root->getAttachments());
        self::assertSame([1], array_map(static fn($match): int => $match->getId(), $reader->search($cs, 'Obsah')));
        self::assertSame([], $reader->search($cs, 'Draft'));

        foreach ([3, 4, 5, 6, 7, 8, 9, 10] as $hiddenId) {
            self::assertNull($reader->record($hiddenId, $cs), 'Hidden page ' . $hiddenId);
        }
        self::assertNull($reader->record(2, $de));
        self::assertSame(1, $reader->parent($page, $cs)?->getId());
        self::assertNull($reader->parent($root, $cs));

        $children = $instrumentation->collect(static function (DatabaseMeasurements $measurements) use ($reader, $cs): array {
            $result = $reader->children(1, $cs);
            self::assertSame(2, $measurements->queryCount());

            return $result;
        });
        self::assertSame([2, 12], array_map(static fn($child): int => $child->getId(), $children));
        $ancestors = $instrumentation->collect(static function (DatabaseMeasurements $measurements) use ($reader, $cs): array {
            $grandchild = $reader->record(11, $cs);
            self::assertNotNull($grandchild);
            $result = $reader->ancestors($grandchild, $cs);
            self::assertSame(4, $measurements->queryCount());

            return $result;
        });
        self::assertSame([1, 2], array_map(static fn($ancestor): int => $ancestor->getId(), $ancestors));
        self::assertSame([1], array_map(static fn($child): int => $child->getId(), $reader->children(null, $cs)));
        self::assertSame([11], array_map(static fn($child): int => $child->getId(), $reader->children(2, $cs)));
        self::assertSame([], $reader->children(4, $cs));

        $alternatives = $reader->languageAlternatives(2, $cs);
        self::assertSame(['cs', 'en'], array_map(static fn($alternative): string => $alternative->getLocale(), $alternatives));
        self::assertSame(['/root/child', '/en/root/child'], array_map(static fn($alternative): string => $alternative->getPublicUrl(), $alternatives));
        self::assertTrue($reader->isPublicInAnyLocale(2));
        self::assertFalse($reader->isPublicInAnyLocale(5));
        $entries = $instrumentation->collect(static function (DatabaseMeasurements $measurements) use ($reader, $cs): array {
            $result = iterator_to_array($reader->sitemapEntries($cs));
            self::assertSame(1, $measurements->queryCount());

            return $result;
        });
        self::assertSame(['/root', '/root/child', '/root/child/grandchild', '/root/sibling'], array_map(static fn($entry): string => $entry->getPublicUrl(), $entries));
        self::assertSame('content', $entries[1]->getTemplateKey());
        self::assertSame('2025-01-04', $entries[1]->getUpdatedAt()->format('Y-m-d'));
        $database->statement("UPDATE cms_page SET updated_at = '2030-01-01 00:00:00' WHERE id = 2");
        $database->statement("UPDATE cms_page_translation SET updated_at = '2031-01-01 00:00:00' WHERE page_id = 2 AND locale = 'cs'");
        $database->statement("UPDATE cms_route SET updated_at = '2032-01-01 00:00:00' WHERE entity_id = 2 AND locale = 'cs'");
        $updatedEntries = iterator_to_array($reader->sitemapEntries($cs));
        self::assertSame('2032-01-01', $updatedEntries[1]->getUpdatedAt()->format('Y-m-d'));

        $database->statement("UPDATE cms_route SET deleted_at = '2025-01-05 00:00:00' WHERE entity_id = 1 AND locale = 'cs'");
        self::assertNull($reader->record(2, $cs));
        self::assertSame([], iterator_to_array($reader->sitemapEntries($cs)));
        $database->statement("UPDATE cms_route SET deleted_at = NULL WHERE entity_id = 1 AND locale = 'cs'");
        $database->statement("UPDATE cms_page SET deleted_at = '2025-01-05 00:00:00' WHERE id = 1");
        self::assertNull($reader->record(2, $cs));
        $database->statement('UPDATE cms_page SET deleted_at = NULL WHERE id = 1');

        $disabledModules = self::createStub(PublicModuleStateResolverInterface::class);
        $disabledModules->method('isDiscoveredInstalledAndEnabled')->willReturn(false);
        $disabledReader = new PublishedPageReadModel($database, new FileReadModel($driver), new PublicCmsUrlBuilder(), $disabledModules);
        self::assertNull($disabledReader->record(1, $cs));
        self::assertSame([], iterator_to_array($disabledReader->sitemapEntries($cs)));
    }
}
