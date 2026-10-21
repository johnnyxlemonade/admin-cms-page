<?php

declare(strict_types=1);

namespace Lemonade\Cms\Page\Tests\Unit\DataGrid;

use Lemonade\Admin\Action\ModuleActionRegistry;
use Lemonade\Admin\Action\Presentation\ModuleActionPresentationFactory;
use Lemonade\Admin\Authorization\AuthorizationService;
use Lemonade\Admin\Authorization\CurrentPrincipalProviderInterface;
use Lemonade\Admin\DataGrid\DataGridRowDefinition;
use Lemonade\Admin\DataGrid\Query\DataGridQuery;
use Lemonade\Admin\Localization\LanguageRegistry;
use Lemonade\Admin\Module\AdminModuleRegistry;
use Lemonade\Admin\Module\AdminModuleRouteResolver;
use Lemonade\Admin\Navigation\AdminNavigationGroupRegistry;
use Lemonade\Admin\Presentation\AdminThumbnailComponent;
use Lemonade\Admin\Presentation\Models\AdminFileModel;
use Lemonade\Cms\CmsPublicationState;
use Lemonade\Cms\Page\Actions\PageActionRegistrar;
use Lemonade\Cms\Page\DataGrid\PageDataGrid;
use Lemonade\Cms\Page\Models\PageModel;
use Lemonade\Framework\Cache\CacheManager;
use Lemonade\Framework\Cache\Store\ArrayCacheItemPool;
use Lemonade\Framework\Database\Connection\ConnectionInterface;
use Lemonade\Framework\Database\Database;
use Lemonade\Framework\Database\DatabaseDriverInterface;
use Lemonade\Framework\Database\DatabaseResultInterface;
use Lemonade\Framework\Image\Value\ImageDimensions;
use Lemonade\Framework\Image\Value\ImageFormat;
use Lemonade\Framework\Image\Value\ImageQuality;
use Lemonade\Framework\Image\Value\ImageVariantDefinition;
use Lemonade\Framework\Localization\TranslatorInterface;
use Lemonade\Framework\Routing\ControllerAction;
use Lemonade\Framework\Routing\Router;
use Lemonade\Framework\Routing\UrlGenerator;
use Lemonade\Image\ImageUrlGenerator;
use Lemonade\Image\ImageVariantRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Overuje Pages specificky kontrakt hierarchickeho DataGridu
 */
final class PageDataGridTest extends TestCase
{
    /**
     * Grid bez strankovani zachovava pozadovanou page size i pro prazdny vysledek
     */
    public function testResultPreservesRequestedPageSize(): void
    {
        $grid = $this->grid([[], []]);

        $page = $grid->execute($this->query());

        self::assertSame(5000, $page->perPage());
        self::assertSame(0, $page->total());
    }

    /**
     * Titulek stranky pouziva shared thumbnail z file usage
     */
    public function testTitleCellContainsPageThumbnail(): void
    {
        $grid = $this->grid([
            [[
                'page_id' => 7,
                'title' => 'Kontakt',
                'publication_state' => 1,
                'published_at' => null,
                'canonical_path' => 'kontakt',
            ]],
            [[
                'id' => 7,
                'parent_id' => null,
                'sort_order' => 0,
                'template_key' => 'content',
                'updated_at' => '2026-10-19 10:00:00',
                'deleted_at' => null,
            ]],
            [[
                'id' => 31,
                'module_code' => 'cms.page',
                'entity_id' => 7,
                'usage' => 'thumbnail',
                'kind' => 'image',
                'original_filename' => 'kontakt.jpg',
                'display_name' => null,
                'caption' => null,
                'extension' => 'jpg',
                'mime_type' => 'image/jpeg',
                'file_size' => 1024,
                'width' => 1200,
                'height' => 800,
            ]],
        ]);

        $page = $grid->execute($this->query());
        $row = $page->items()[0]->toArray();
        $titleCell = $row['cells']['title'];
        self::assertIsArray($titleCell);
        self::assertArrayHasKey(0, $titleCell);
        $title = $titleCell[0];
        self::assertIsArray($title);
        self::assertArrayHasKey('secondary', $title);
        self::assertArrayHasKey('thumbnail', $title);
        $thumbnail = $title['thumbnail'] ?? null;
        self::assertIsArray($thumbnail);
        self::assertArrayHasKey('url', $thumbnail);

        self::assertSame('thumbnail', $title['type']);
        self::assertSame('Kontakt', $title['value']);
        self::assertSame('/kontakt', $title['secondary'] ?? null);
        self::assertSame('/api/image/cms.page/datagrid/31', $thumbnail['url']);
    }

    /**
     * Root bez zvolene jazykove mutace nevystupuje jako novy zaznam
     */
    public function testMissingLocaleTranslationIsNotPresentedAsNewRecord(): void
    {
        $grid = $this->grid([
            [],
            [$this->pageRow(7)],
        ]);

        $result = $grid->execute($this->query(locale: 'en'));

        self::assertSame(0, $result->total());
        self::assertSame([], $result->items());
    }

    /**
     * Prazdna existujici mutace pouziva shared placeholder a ulozeny stav
     */
    public function testExistingEmptyTranslationUsesSharedPlaceholderAndPersistedState(): void
    {
        $grid = $this->grid([
            [$this->translationRow(7, null, CmsPublicationState::New)],
            [$this->pageRow(7)],
            [],
        ]);

        $row = $grid->execute($this->query(locale: 'en'))->items()[0]->toArray();

        self::assertSame('admin.datagrid.untitled', $this->cellValue($row, 'title'));
        self::assertSame('admin.status.new', $this->cellValue($row, 'state'));
    }

    /**
     * Draft i publikovana mutace zustavaji ve zvolenem locale viditelne
     */
    public function testDraftAndPublishedTranslationsKeepTheirPublicationState(): void
    {
        $grid = $this->grid([
            [
                $this->translationRow(7, 'Draft', CmsPublicationState::Draft),
                $this->translationRow(8, 'Published', CmsPublicationState::Published),
            ],
            [
                $this->pageRow(7, sortOrder: 0),
                $this->pageRow(8, sortOrder: 1),
            ],
            [],
        ]);

        $rows = array_map(
            static fn(DataGridRowDefinition $row): array => $row->toArray(),
            $grid->execute($this->query(locale: 'en'))->items(),
        );

        self::assertSame('admin.status.draft', $this->cellValue($rows[0], 'state'));
        self::assertSame('admin.status.published', $this->cellValue($rows[1], 'state'));
    }

    /**
     * Chybejici mutace rodice je zobrazena pouze jako stromovy kontext potomka
     */
    public function testMissingAncestorTranslationIsMarkedAsStructuralContext(): void
    {
        $grid = $this->grid([
            [$this->translationRow(8, 'Child', CmsPublicationState::Draft)],
            [
                $this->pageRow(7, sortOrder: 0),
                $this->pageRow(8, parentId: 7, sortOrder: 0),
            ],
            [],
        ]);

        $rows = array_map(
            static fn(DataGridRowDefinition $row): array => $row->toArray(),
            $grid->execute($this->query(locale: 'en'))->items(),
        );

        self::assertSame('page.list.structural_context', $this->cellValue($rows[0], 'title'));
        self::assertSame('page.list.structural_context_state', $this->cellValue($rows[0], 'state'));
        self::assertSame('Child', $this->cellValue($rows[1], 'title'));
        $tree = $rows[1]['tree'] ?? null;
        self::assertIsArray($tree);
        self::assertSame(7, $tree['parentId']);
    }

    /**
     * Explicitni locale filtr zachova existujici mutaci i bez enabled lookupu
     */
    public function testExistingTranslationRemainsVisibleForExplicitDisabledLocale(): void
    {
        $grid = $this->grid([
            [$this->translationRow(7, 'Archiv', CmsPublicationState::Draft)],
            [$this->pageRow(7)],
            [],
        ]);

        $row = $grid->execute($this->query(locale: 'de'))->items()[0]->toArray();

        self::assertSame('Archiv', $this->cellValue($row, 'title'));
        self::assertSame('admin.status.draft', $this->cellValue($row, 'state'));
    }

    /**
     * Locale filtr nabizi aktivni jazyky i deaktivovany jazyk s mutaci
     */
    public function testDataGridLocalesIncludeDisabledLanguageWithTranslation(): void
    {
        $capturedSql = '';
        $result = $this->createMock(DatabaseResultInterface::class);
        $result->method('result_array')->willReturn([
            ['code' => 'cs', 'name' => 'Čeština'],
            ['code' => 'en', 'name' => 'English'],
        ]);
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

        $locales = (new PageModel($driver))->dataGridLocales();

        self::assertSame(['cs', 'en'], array_column($locales, 'code'));
        self::assertStringContainsString('LEFT JOIN cms_page_translation translation', $capturedSql);
        self::assertStringContainsString('language.enabled = 1 OR translation.id IS NOT NULL', $capturedSql);
    }

    /**
     * Vytvori grid nad predanymi DB-free vysledky dotazu
     *
     * @param list<list<array<string,mixed>>> $queryRows
     */
    private function grid(array $queryRows): PageDataGrid
    {
        $results = [];
        foreach ($queryRows as $rows) {
            $result = $this->createMock(DatabaseResultInterface::class);
            $result->method('result_array')->willReturn($rows);
            $results[] = $result;
        }
        $driver = $this->createMock(DatabaseDriverInterface::class);
        $driver->method('protect_identifiers')->willReturnCallback(
            static fn(string $identifier): string => $identifier,
        );
        $driver->method('query')->willReturnCallback(
            static function () use (&$results): DatabaseResultInterface {
                $result = array_shift($results);
                if (!$result instanceof DatabaseResultInterface) {
                    throw new \LogicException('Unexpected PageDataGrid query.');
                }

                return $result;
            },
        );
        $connection = $this->createMock(ConnectionInterface::class);
        $database = new Database($connection, $driver);
        $languages = new LanguageRegistry(
            $database,
            new CacheManager(new ArrayCacheItemPool()),
        );
        $authorization = new AuthorizationService(
            $this->createMock(CurrentPrincipalProviderInterface::class),
            $database,
        );
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('get')->willReturnCallback(
            static fn(string $key): string => $key,
        );
        $routes = new AdminModuleRouteResolver(
            new AdminModuleRegistry(new AdminNavigationGroupRegistry()),
        );
        $router = new Router();
        $router->getNamed(
            'api.image.show',
            '/api/image/{module}/{variant}/{image}',
            ControllerAction::for('TestController', 'show'),
        );
        $urls = new UrlGenerator($router);
        $actions = new ModuleActionRegistry();
        (new PageActionRegistrar($actions))->register();
        $actionPresentation = new ModuleActionPresentationFactory(
            $actions,
            $routes,
            $authorization,
            $translator,
            $urls,
        );
        $variants = new ImageVariantRegistry();
        $variants->register(
            variant: 'datagrid',
            definition: new ImageVariantDefinition(
                dimensions: new ImageDimensions(32, 32),
                format: ImageFormat::Webp,
                quality: ImageQuality::fromInt(82),
            ),
        );

        return new PageDataGrid(
            new PageModel($driver),
            $languages,
            $authorization,
            $translator,
            $actionPresentation,
            $routes,
            $urls,
            new AdminFileModel($driver),
            new AdminThumbnailComponent(new ImageUrlGenerator($urls, $variants)),
        );
    }

    /**
     * Vytvori vychozi dotaz stromoveho gridu
     */
    private function query(string $locale = 'cs'): DataGridQuery
    {
        return new DataGridQuery(
            page: 1,
            pageSize: 5000,
            sortKey: 'sortOrder',
            sortDirection: 'asc',
            search: '',
            filters: ['locale' => $locale],
        );
    }

    /**
     * Vytvori lokalizovanou DataGrid projekci stranky
     *
     * @return array<string,mixed>
     */
    private function translationRow(int $pageId, ?string $title, CmsPublicationState $state): array
    {
        return [
            'page_id' => $pageId,
            'title' => $title,
            'publication_state' => $state->value,
            'published_at' => $state === CmsPublicationState::Published ? '2026-10-20 10:00:00' : null,
            'canonical_path' => $title === null ? null : strtolower($title),
        ];
    }

    /**
     * Vytvori root radek stromu pro DB-free DataGrid scenar
     *
     * @return array<string,mixed>
     */
    private function pageRow(
        int $pageId,
        ?int $parentId = null,
        int $sortOrder = 0,
    ): array {
        return [
            'id' => $pageId,
            'parent_id' => $parentId,
            'sort_order' => $sortOrder,
            'template_key' => 'content',
            'updated_at' => '2026-10-20 10:00:00',
            'deleted_at' => null,
        ];
    }

    /**
     * Vrati textovou hodnotu serializovane typed bunky
     *
     * @param array<string,mixed> $row
     */
    private function cellValue(array $row, string $key): string
    {
        $cells = $row['cells'] ?? null;
        self::assertIsArray($cells);
        $cell = $cells[$key] ?? null;
        self::assertIsArray($cell);
        self::assertArrayHasKey(0, $cell);
        $presentation = $cell[0];
        self::assertIsArray($presentation);
        $value = $presentation['value'] ?? null;
        self::assertIsString($value);

        return $value;
    }
}
