<?php

declare(strict_types=1);

namespace Lemonade\Cms\Page\Actions;

use Lemonade\Admin\Action\ModuleActionDefinition;
use Lemonade\Admin\Action\ModuleActionRegistry;
use Lemonade\Admin\Action\Presentation\ConfirmationDefinition;
use Lemonade\Cms\Page\ModuleManifest;

/**
 * Registruje shared editor a lifecycle akce CMS stranek
 */
final readonly class PageActionRegistrar
{
    public const string CREATE_HANDLER = 'admin.cms.page.action.create';
    public const string SAVE_HANDLER = 'admin.cms.page.action.save';
    public const string MOVE_UP_HANDLER = 'admin.cms.page.action.move_up';
    public const string MOVE_DOWN_HANDLER = 'admin.cms.page.action.move_down';

    /**
     * Nastavuje registry definic a handler service ID
     */
    public function __construct(
        private ModuleActionRegistry $actions,
    ) {}

    /**
     * Registruje autorizovane create, save, poradi a lifecycle akce Pages
     */
    public function register(): void
    {
        $moduleCode = ModuleManifest::CODE;
        $this->actions->register(
            moduleCode: $moduleCode,
            definition: new ModuleActionDefinition(
                key: 'create',
                permission: 'cms.page.create',
                labelKey: 'page.actions.create',
                refreshGrid: true,
            ),
            handlerServiceId: self::CREATE_HANDLER,
        );
        $this->actions->register(
            moduleCode: $moduleCode,
            definition: new ModuleActionDefinition(
                key: 'save',
                permission: 'cms.page.edit',
                labelKey: 'admin.common.save',
            ),
            handlerServiceId: self::SAVE_HANDLER,
        );
        $this->actions->register(
            moduleCode: $moduleCode,
            definition: new ModuleActionDefinition(
                key: 'change-url',
                permission: 'cms.page.edit',
                labelKey: 'admin.url_segment.save',
            ),
            handlerServiceId: PageChangeUrlAction::class,
        );
        $this->actions->register(
            moduleCode: $moduleCode,
            definition: new ModuleActionDefinition(
                key: 'move-up',
                permission: 'cms.page.edit',
                labelKey: 'page.actions.move_up',
                refreshGrid: true,
            ),
            handlerServiceId: self::MOVE_UP_HANDLER,
        );
        $this->actions->register(
            moduleCode: $moduleCode,
            definition: new ModuleActionDefinition(
                key: 'move-down',
                permission: 'cms.page.edit',
                labelKey: 'page.actions.move_down',
                refreshGrid: true,
            ),
            handlerServiceId: self::MOVE_DOWN_HANDLER,
        );
        $this->actions->register(
            moduleCode: $moduleCode,
            definition: new ModuleActionDefinition(
                key: 'delete',
                permission: 'cms.page.delete',
                labelKey: 'page.actions.delete',
                confirmation: new ConfirmationDefinition('page.confirm.delete'),
                refreshGrid: true,
            ),
            handlerServiceId: PageDeleteAction::class,
        );
        $this->actions->register(
            moduleCode: $moduleCode,
            definition: new ModuleActionDefinition(
                key: 'restore',
                permission: 'cms.page.restore',
                labelKey: 'page.actions.restore',
                confirmation: new ConfirmationDefinition('page.confirm.restore'),
                refreshGrid: true,
            ),
            handlerServiceId: PageRestoreAction::class,
        );
    }
}
