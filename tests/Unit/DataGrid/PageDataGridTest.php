<?php

declare(strict_types=1);

namespace Lemonade\Cms\Page\Tests\Unit\DataGrid;

use Lemonade\Admin\Action\ModuleActionRegistry;
use Lemonade\Admin\Action\Presentation\ModuleActionPresentationFactory;
use Lemonade\Admin\Authorization\AuthorizationService;
use Lemonade\Admin\Authorization\CurrentPrincipalProviderInterface;
use Lemonade\Admin\DataGrid\Query\DataGridQuery;
use Lemonade\Admin\Localization\LanguageRegistry;
use Lemonade\Admin\Module\AdminModuleRegistry;
use Lemonade\Admin\Module\AdminModuleRouteResolver;
use Lemonade\Admin\Modules\Registry\ModuleRegistry;
use Lemonade\Admin\Navigation\AdminNavigationGroupRegistry;
use Lemonade\Admin\Presentation\AdminThumbnailComponent;
use Lemonade\Admin\Presentation\Models\AdminFileModel;
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
            new ModuleRegistry(),
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
    private function query(): DataGridQuery
    {
        return new DataGridQuery(
            page: 1,
            pageSize: 5000,
            sortKey: 'sortOrder',
            sortDirection: 'asc',
            search: '',
            filters: ['locale' => 'cs'],
        );
    }
}
