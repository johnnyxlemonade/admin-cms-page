<?php

declare(strict_types=1);

namespace Lemonade\Cms\Page\Tests\Unit;

use Lemonade\Cms\Page\Actions\PageActionRegistrar;
use Lemonade\Cms\Page\CmsPageServiceProvider;
use Lemonade\Framework\Container\Container;
use Lemonade\Framework\Routing\RouteRegistrarInterface;
use PHPUnit\Framework\TestCase;

/**
 * Overuje oddeleni administracniho Page modulu od verejneho routingu hosta
 */
final class CmsPageServiceProviderTest extends TestCase
{
    /**
     * Registruje pouze administracni sluzby bez verejneho handleru a rout
     */
    public function testItDoesNotActivatePublicPageRouting(): void
    {
        $container = new Container();

        (new CmsPageServiceProvider())->register($container);

        self::assertTrue($container->isBound(PageActionRegistrar::CREATE_HANDLER));
        self::assertSame([], iterator_to_array($container->tagged(RouteRegistrarInterface::class)));
    }
}
