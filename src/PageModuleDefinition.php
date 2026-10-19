<?php

declare(strict_types=1);

namespace Lemonade\Cms\Page;

use Lemonade\Admin\Audit\AuditEventDefinition;
use Lemonade\Admin\Authorization\PermissionDefinition;
use Lemonade\Admin\Icon\AdminIcon;
use Lemonade\Admin\Module\AdminModuleMetadata;
use Lemonade\Admin\Module\AdminNavigationGroup;
use Lemonade\Admin\Module\Contract\AdminModuleDefinitionInterface;
use Lemonade\Admin\Module\Contract\AdminModuleFileCapabilitiesInterface;
use Lemonade\Admin\Presentation\AdminFileCapabilities;
use Lemonade\Admin\Presentation\AdminFilePresentation;

/**
 * Deklaruje navigaci, prava a audit CMS stranek
 */
final readonly class PageModuleDefinition implements AdminModuleDefinitionInterface, AdminModuleFileCapabilitiesInterface
{
    /**
     * Vrati stabilni kod Pages modulu
     */
    public function code(): string
    {
        return 'cms.page';
    }

    /**
     * Vrati metadata generic Admin module transportu
     */
    public function adminMetadata(): AdminModuleMetadata
    {
        return new AdminModuleMetadata(
            nameKey: 'page.module.name',
            icon: AdminIcon::JournalText,
            navigationGroup: AdminNavigationGroup::Content,
            navigationOrder: 5,
            routeSegment: 'pages',
            navigationPermission: 'cms.page.view',
            descriptionKey: 'page.module.description',
        );
    }

    /**
     * Vraci file capability Pages modulu
     */
    public function fileCapabilities(): AdminFileCapabilities
    {
        return new AdminFileCapabilities(
            thumbnail: AdminFilePresentation::Landscape,
            gallery: true,
            attachments: true,
            richTextImages: true,
            richTextFiles: true,
        );
    }

    /**
     * Vrati prava vyzadovana pro skutecne Pages operace
     *
     * @return list<PermissionDefinition>
     */
    public function permissionDefinitions(): array
    {
        return [
            new PermissionDefinition('cms.page.view', $this->code(), 'page.permissions.view'),
            new PermissionDefinition('cms.page.create', $this->code(), 'page.permissions.create', requires: ['cms.page.view']),
            new PermissionDefinition('cms.page.edit', $this->code(), 'page.permissions.edit', requires: ['cms.page.view']),
            new PermissionDefinition('cms.page.publish', $this->code(), 'page.permissions.publish', requires: ['cms.page.view']),
            new PermissionDefinition('cms.page.delete', $this->code(), 'page.permissions.delete', requires: ['cms.page.view']),
            new PermissionDefinition('cms.page.restore', $this->code(), 'page.permissions.restore', requires: ['cms.page.view']),
        ];
    }

    /**
     * Deklaruje presentation udalosti skutecnych mutaci stranek
     *
     * @return list<AuditEventDefinition>
     */
    public function auditEventDefinitions(): array
    {
        return [
            new AuditEventDefinition('created', 'page.audit.events.created', AdminIcon::PlusLg),
            new AuditEventDefinition('updated', 'page.audit.events.updated', AdminIcon::PencilSquare),
            new AuditEventDefinition('translation_created', 'page.audit.events.translation_created', AdminIcon::PlusLg),
            new AuditEventDefinition('published', 'page.audit.events.published', AdminIcon::CheckCircle),
            new AuditEventDefinition('unpublished', 'page.audit.events.unpublished', AdminIcon::PauseCircle),
            new AuditEventDefinition('reordered', 'page.audit.events.reordered', AdminIcon::ArrowsMove),
            new AuditEventDefinition('deleted', 'page.audit.events.deleted', AdminIcon::Trash3),
            new AuditEventDefinition('restored', 'page.audit.events.restored', AdminIcon::ArrowCounterclockwise),
        ];
    }
}
