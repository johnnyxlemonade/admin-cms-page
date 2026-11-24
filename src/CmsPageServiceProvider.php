<?php

declare(strict_types=1);

namespace Lemonade\Cms\Page;

use Lemonade\Admin\Action\ModuleActionRegistry;
use Lemonade\Admin\Action\StandardEditorAction;
use Lemonade\Admin\DataGrid\DataGridRegistry;
use Lemonade\Admin\Editor\EditorDispatcher;
use Lemonade\Admin\Editor\EditorRegistry;
use Lemonade\Admin\Identity\LocalActorGuard;
use Lemonade\Admin\Localization\ClientTranslationGroupRegistry;
use Lemonade\Admin\Page\ModulePageRegistry;
use Lemonade\Cms\Page\Actions\PageActionRegistrar;
use Lemonade\Cms\Page\Actions\PageReorderAction;
use Lemonade\Cms\Page\DataGrid\PageDataGrid;
use Lemonade\Cms\Page\Editor\PageEditor;
use Lemonade\Cms\Page\Public\PublishedPageReadModel;
use Lemonade\Cms\Page\Services\PageService;
use Lemonade\Cms\Page\Template\PageTemplateProvider;
use Lemonade\Cms\Page\Template\PageTemplateProviderInterface;
use Lemonade\Framework\Container\ContainerBuilderInterface;
use Lemonade\Framework\Container\ContainerInterface;
use Lemonade\Framework\Core\BootableServiceProviderInterface;
use Lemonade\Framework\Core\ServiceProviderInterface;
use Lemonade\Framework\Localization\TranslationResourceRegistry;

/**
 * Registers CMS Page contributions with the shared Admin runtime
 */
final readonly class CmsPageServiceProvider implements ServiceProviderInterface, BootableServiceProviderInterface
{
    /**
     * Registers service definitions for the page module
     */
    public function register(ContainerBuilderInterface $container): void
    {
        $container->singleton(PageTemplateProviderInterface::class, PageTemplateProvider::class);
        $container->scoped(PublishedPageReadModel::class, PublishedPageReadModel::class);
        $container->scoped(
            PageActionRegistrar::CREATE_HANDLER,
            static fn(ContainerInterface $container): StandardEditorAction => StandardEditorAction::create(
                $container->get(EditorDispatcher::class),
                ModuleManifest::CODE,
            ),
        );
        $container->scoped(
            PageActionRegistrar::SAVE_HANDLER,
            static fn(ContainerInterface $container): StandardEditorAction => StandardEditorAction::save(
                $container->get(EditorDispatcher::class),
                ModuleManifest::CODE,
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
    }

    /**
     * Adds page resources and runtime contributions after container freeze
     */
    public function boot(ContainerInterface $container): void
    {
        $container->get(TranslationResourceRegistry::class)->register(
            __DIR__ . '/Resources/lang',
            ModuleManifest::CODE,
        );
        $container->get(ClientTranslationGroupRegistry::class)->register('page');
        $container->get(DataGridRegistry::class)->register(ModuleManifest::CODE, PageDataGrid::class);
        $container->get(ModulePageRegistry::class)->registerIndex(ModuleManifest::CODE, PageModulePageProvider::class);
        $container->get(ModulePageRegistry::class)->registerEditor(ModuleManifest::CODE, PageModulePageProvider::class);
        $container->get(EditorRegistry::class)->register(ModuleManifest::CODE, PageEditor::class);
        (new PageActionRegistrar($container->get(ModuleActionRegistry::class)))->register();
    }
}
