<?php

declare(strict_types=1);

namespace Lemonade\Cms\Page\Tests\Unit\Migrations;

use Lemonade\Cms\Page\Migrations\CreateCmsPages;
use Lemonade\Framework\Database\Connection\DatabaseConfig;
use Lemonade\Framework\Database\DatabaseDriverInterface;
use Lemonade\Framework\Database\Driver\Mysql\MysqlIdentifierEscaper;
use Lemonade\Framework\Database\Driver\Mysql\MysqlSchemaGrammar;
use Lemonade\Framework\Database\Driver\Mysql\MysqlSqlEscaper;
use Lemonade\Framework\Database\Schema\Schema;
use Lemonade\Framework\Database\Schema\SchemaCompiler;
use PHPUnit\Framework\TestCase;

/**
 * Overuje fresh-install SQL schema bez databazovych zapisu
 */
final class CreateCmsPagesTest extends TestCase
{
    /**
     * Zkompiluje fresh schema presne dvou vlastnich tabulek bez databazoveho cteni
     */
    public function testFreshInstallCreatesOnlyRootAndTranslationTables(): void
    {
        $statements = [];
        $database = $this->createMock(DatabaseDriverInterface::class);
        $database
            ->method('query')
            ->willReturnCallback(static function (string $statement) use (&$statements): bool {
                if (preg_match('/^CREATE TABLE(?: IF NOT EXISTS)? `([^`]+)`/', $statement, $matches) === 1) {
                    $statements[$matches[1]] = $statement;
                }

                return true;
            });
        $configuration = DatabaseConfig::fromArray(['driver' => 'mysql']);
        $identifierEscaper = new MysqlIdentifierEscaper('');
        $schema = new Schema(
            new SchemaCompiler(new MysqlSchemaGrammar(new MysqlSqlEscaper($identifierEscaper), $configuration)),
            $database,
        );

        (new CreateCmsPages())->up($schema);

        self::assertSame(['cms_page', 'cms_page_translation'], array_keys($statements));
        self::assertStringContainsString('`parent_id` BIGINT UNSIGNED NULL', $statements['cms_page']);
        foreach (['created_by', 'updated_by', 'deleted_by', 'owner_id'] as $column) {
            self::assertStringContainsString(sprintf('`%s` BIGINT UNSIGNED NULL', $column), $statements['cms_page']);
        }
        foreach (['created_by', 'updated_by', 'deleted_by', 'owner'] as $suffix) {
            self::assertStringContainsString('CONSTRAINT `fk_cms_page_' . $suffix . '`', $statements['cms_page']);
        }
        self::assertSame(4, substr_count($statements['cms_page'], 'ON UPDATE RESTRICT ON DELETE SET NULL'));
        self::assertStringContainsString('KEY `idx_cms_page_owner_deleted` (`owner_id`, `deleted_at`)', $statements['cms_page']);
        self::assertStringContainsString('`sort_order` INT UNSIGNED NOT NULL DEFAULT 0', $statements['cms_page']);
        self::assertStringContainsString('`template_key` VARCHAR(100) NOT NULL', $statements['cms_page']);
        self::assertStringContainsString('CONSTRAINT `fk_cms_page_parent` FOREIGN KEY (`parent_id`)', $statements['cms_page']);
        self::assertStringContainsString('KEY `idx_cms_page_parent_order` (`parent_id`, `sort_order`, `id`)', $statements['cms_page']);
        self::assertStringContainsString('KEY `idx_cms_page_template` (`template_key`)', $statements['cms_page']);
        self::assertStringContainsString('UNIQUE KEY `uq_cms_page_translation_locale` (`page_id`, `locale`)', $statements['cms_page_translation']);
        self::assertStringContainsString('CONSTRAINT `fk_cms_page_translation_locale` FOREIGN KEY (`locale`)', $statements['cms_page_translation']);
        self::assertStringNotContainsString('slug', implode("\n", $statements));
    }
}
