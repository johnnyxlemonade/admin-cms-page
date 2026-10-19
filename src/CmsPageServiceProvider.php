<?php

declare(strict_types=1);

namespace Lemonade\Cms\Page;

use Lemonade\Admin\Action\StandardEditorAction;
use Lemonade\Admin\Audit\AuditEventPresentationRegistry;
use Lemonade\Admin\Authorization\PermissionCatalogRegistry;
use Lemonade\Admin\DataGrid\DataGridRegistry;
use Lemonade\Admin\Editor\EditorDispatcher;
use Lemonade\Admin\Editor\EditorRegistry;
use Lemonade\Admin\Identity\LocalActorGuard;
use Lemonade\Admin\Localization\ClientTranslationGroupRegistry;
use Lemonade\Admin\Module\AdminModuleRegistry;
use Lemonade\Admin\Modules\Registry\ModuleRegistry;
use Lemonade\Admin\Page\ModulePageRegistry;
use Lemonade\Cms\Page\Actions\PageActionRegistrar;
use Lemonade\Cms\Page\Actions\PageReorderAction;
use Lemonade\Cms\Page\DataGrid\PageDataGrid;
use Lemonade\Cms\Page\Editor\PageEditor;
use Lemonade\Cms\Page\Services\PageService;
use Lemonade\Cms\Routing\Cms\PublicCmsRouteHandlerRegistry;
use Lemonade\Framework\Container\ContainerBuilderInterface;
use Lemonade\Framework\Container\ContainerInterface;
use Lemonade\Framework\Core\BootableServiceProviderInterface;
use Lemonade\Framework\Core\ServiceProviderInterface;
use Lemonade\Framework\Localization\TranslationResourceRegistry;
use Lemonade\Framework\View\ViewResourceRegistry;

/**
 * Registruje CMS Page contribution do sdileneho Admin a CMS runtime
 */
final readonly class CmsPageServiceProvider implements ServiceProviderInterface, BootableServiceProviderInterface
{
    /**
     * Registruje DI definitions modulu a host public handler
     */
    public function register(ContainerBuilderInterface $container): void
    {
        $container->scoped(
            PageActionRegistrar::CREATE_HANDLER,
            static fn(ContainerInterface $container): StandardEditorAction => StandardEditorAction::create(
                $container->get(EditorDispatcher::class),
                'cms.page',
            ),
        );
        $container->scoped(
            PageActionRegistrar::SAVE_HANDLER,
            static fn(ContainerInterface $container): StandardEditorAction => StandardEditorAction::save(
                $container->get(EditorDispatcher::class),
                'cms.page',
            ),
        );
        $container->scoped(
            PageActionRegistrar::MOVE_UP_HANDLER,
            static fn(ContainerInterface $container): PageReorderAction => new PageReorderAction(
                $container->get(PageService::class),
                $container->get(LocalActorGuard::class),
                -1,
            ),
        );
        $container->scoped(
            PageActionRegistrar::MOVE_DOWN_HANDLER,
            static fn(ContainerInterface $container): PageReorderAction => new PageReorderAction(
                $container->get(PageService::class),
                $container->get(LocalActorGuard::class),
                1,
            ),
        );
        $container->singleton(PageActionRegistrar::class, PageActionRegistrar::class);
        $container->scoped(PublicPageRouteHandler::class, PublicPageRouteHandler::class);
    }

    /**
     * Pripoji metadata, prava a package resources modulu po container freeze
     */
    public function boot(ContainerInterface $container): void
    {
        $definition = new PageModuleDefinition();
        $container->get(TranslationResourceRegistry::class)->register(
            __DIR__ . '/Resources/lang',
            'cms.page',
        );
        $container->get(ViewResourceRegistry::class)->register(
            'cms-page',
            __DIR__ . '/Resources/views',
        );
        $container->get(ClientTranslationGroupRegistry::class)->register('page');
        $container->get(ModuleRegistry::class)->register($definition);
        $container->get(AdminModuleRegistry::class)->register($definition);
        $container->get(DataGridRegistry::class)->register($definition->code(), PageDataGrid::class);
        $container->get(ModulePageRegistry::class)->registerIndex($definition->code(), PageModulePageProvider::class);
        $container->get(ModulePageRegistry::class)->registerEditor($definition->code(), PageModulePageProvider::class);
        $container->get(EditorRegistry::class)->register($definition->code(), PageEditor::class);
        $container->get(PermissionCatalogRegistry::class)->register(...$definition->permissionDefinitions());
        $container->get(PageActionRegistrar::class)->register();
        $container->get(AuditEventPresentationRegistry::class)->registerModuleDefinition($definition);
        $container->get(PublicCmsRouteHandlerRegistry::class)->registerFactory(
            'cms.page',
            static fn(ContainerInterface $container): PublicPageRouteHandler => $container->get(PublicPageRouteHandler::class),
        );
    }
}
