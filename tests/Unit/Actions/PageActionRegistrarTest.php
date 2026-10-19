<?php

declare(strict_types=1);

namespace Lemonade\Cms\Page\Tests\Unit\Actions;

use Lemonade\Admin\Action\ModuleActionRegistry;
use Lemonade\Cms\Page\Actions\PageActionRegistrar;
use PHPUnit\Framework\TestCase;

/**
 * Overuje registraci URL bezpecnych akci CMS stranek
 */
final class PageActionRegistrarTest extends TestCase
{
    /**
     * Registruje editor, poradi a lifecycle akce pod stabilnimi klici
     */
    public function testRegistersPageActions(): void
    {
        $actions = new ModuleActionRegistry();
        (new PageActionRegistrar($actions))->register();

        foreach (['create', 'save', 'change-url', 'move-up', 'move-down', 'delete', 'restore'] as $key) {
            self::assertTrue($actions->has('cms.page', $key));
        }
        self::assertSame(
            'cms.page.edit',
            $actions->action('cms.page', 'change-url')['definition']->permission(),
        );
        self::assertSame(
            PageActionRegistrar::MOVE_UP_HANDLER,
            $actions->action('cms.page', 'move-up')['handlerServiceId'],
        );
        self::assertSame(
            PageActionRegistrar::MOVE_DOWN_HANDLER,
            $actions->action('cms.page', 'move-down')['handlerServiceId'],
        );
    }
}
