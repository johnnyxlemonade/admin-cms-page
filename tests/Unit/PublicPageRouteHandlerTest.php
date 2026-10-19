<?php

declare(strict_types=1);

namespace Lemonade\Cms\Page\Tests\Unit;

use Lemonade\Admin\Localization\LanguageRegistry;
use Lemonade\Cms\Page\Models\PageModel;
use Lemonade\Cms\Page\PublicPage;
use Lemonade\Cms\Page\PublicPageRouteHandler;
use Lemonade\Cms\Page\Template\PageTemplateDefinition;
use Lemonade\Cms\Page\Template\PageTemplateRendererInterface;
use Lemonade\Cms\Routing\Cms\CmsRoute;
use Lemonade\Cms\Routing\Locale\PublicLocaleResolution;
use Lemonade\Framework\Cache\CacheManager;
use Lemonade\Framework\Cache\Store\ArrayCacheItemPool;
use Lemonade\Framework\Database\Connection\ConnectionInterface;
use Lemonade\Framework\Database\Database;
use Lemonade\Framework\Database\DatabaseDriverInterface;
use Lemonade\Framework\Database\DatabaseResultInterface;
use Lemonade\Framework\View\ViewRendererInterface;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * Overuje public file access Pages bez databazove integrace
 */
final class PublicPageRouteHandlerTest extends TestCase
{
    /**
     * Povoli vlastnika publikovane root stranky v enabled locale
     */
    public function testAllowsPublishedPageAttachmentInEnabledLocale(): void
    {
        $handler = $this->handler([
            $this->publishedPageRow(templateKey: 'content'),
        ]);

        self::assertTrue($handler->isPublicAttachmentOwner(7));
    }

    /**
     * Odmitne soubor pokud ancestor chain neni ve stejnem locale publikovany
     */
    public function testRejectsAttachmentWhenAncestorIsUnavailable(): void
    {
        $handler = $this->handler([
            $this->publishedPageRow(parentId: 3, templateKey: 'content'),
            null,
        ]);

        self::assertFalse($handler->isPublicAttachmentOwner(7));
    }

    /**
     * Odmitne soubor publikovane stranky s odebranou host sablonou
     */
    public function testRejectsAttachmentWhenTemplateIsUnavailable(): void
    {
        $handler = $this->handler([
            $this->publishedPageRow(templateKey: 'removed-template'),
        ]);

        self::assertFalse($handler->isPublicAttachmentOwner(7));
    }

    /**
     * @param list<array<string,mixed>|null> $rows
     */
    private function handler(array $rows): PublicPageRouteHandler
    {
        $results = [];
        foreach ($rows as $row) {
            $result = $this->createMock(DatabaseResultInterface::class);
            $result->method('result_array')->willReturn($row === null ? [] : [$row]);
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
                    throw new \LogicException('Unexpected PageModel query.');
                }

                return $result;
            },
        );
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('select')->willReturn([
            ['code' => 'cs', 'name' => 'Cestina', 'is_default' => 1],
        ]);

        return new PublicPageRouteHandler(
            new PageModel($driver),
            new PublicPageTemplateRendererStub(),
            new LanguageRegistry(
                new Database($connection, $driver),
                new CacheManager(new ArrayCacheItemPool()),
            ),
        );
    }

    /**
     * @return array<string,int|string|null>
     */
    private function publishedPageRow(?int $parentId = null, string $templateKey = 'content'): array
    {
        return [
            'id' => 7,
            'parent_id' => $parentId,
            'template_key' => $templateKey,
            'locale' => 'cs',
            'title' => 'Kontakt',
            'content' => null,
            'page_title' => null,
            'meta_description' => null,
            'published_at' => '2026-10-19 10:00:00',
        ];
    }
}

/**
 * Poskytuje dostupnou content sablonu bez host renderingu
 */
final readonly class PublicPageTemplateRendererStub implements PageTemplateRendererInterface
{
    public function templates(): array
    {
        return [new PageTemplateDefinition('content', 'page.template.content')];
    }

    public function render(
        PublicPage $page,
        PublicLocaleResolution $locale,
        CmsRoute $route,
        ViewRendererInterface $views,
    ): ?ResponseInterface {
        return null;
    }
}
