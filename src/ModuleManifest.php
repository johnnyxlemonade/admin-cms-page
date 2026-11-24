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
use Lemonade\Admin\Modules\Manifest\ModuleManifestInterface;
use Lemonade\Admin\Modules\Migration\ModuleMigrationManifestInterface;
use Lemonade\Admin\Presentation\AdminFileCapabilities;
use Lemonade\Admin\Presentation\AdminFilePresentation;
use Lemonade\Framework\Database\Migration\MigrationInterface;

/**
 * Declares the optional CMS Page module and its schema
 */
final readonly class ModuleManifest implements
    ModuleManifestInterface,
    ModuleMigrationManifestInterface,
    AdminModuleDefinitionInterface,
    AdminModuleFileCapabilitiesInterface
{
    public const string CODE = 'cms.page';

    /**
     * Returns the stable page module code
     */
    public function code(): string
    {
        return self::CODE;
    }

    /**
     * Returns the module label translation key
     */
    public function labelKey(): string
    {
        return 'page.module.name';
    }

    /**
     * Returns the module runtime provider class
     */
    public function runtimeProvider(): string
    {
        return CmsPageServiceProvider::class;
    }

    /**
     * Returns migrations for the standard deployment lifecycle
     *
     * @return list<class-string<MigrationInterface>>
     */
    public function migrations(): array
    {
        return [Migrations\CreateCmsPages::class];
    }

    /**
     * Returns metadata for generic Admin module transport
     */
    public function adminMetadata(): AdminModuleMetadata
    {
        return new AdminModuleMetadata(
            nameKey: 'page.module.name',
            icon: AdminIcon::Files,
            navigationGroup: AdminNavigationGroup::Cms,
            navigationOrder: 5,
            routeSegment: 'pages',
            navigationPermission: 'cms.page.view_own',
            descriptionKey: 'page.module.description',
        );
    }

    /**
     * Returns the page module file capabilities
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
     * Returns permissions required by page operations
     *
     * @return list<PermissionDefinition>
     */
    public function permissionDefinitions(): array
    {
        return [
            new PermissionDefinition('cms.page.view_own', self::CODE, 'page.permissions.view_own'),
            new PermissionDefinition('cms.page.view', self::CODE, 'page.permissions.view', requires: ['cms.page.view_own']),
            new PermissionDefinition('cms.page.create', self::CODE, 'page.permissions.create', requires: ['cms.page.view_own']),
            new PermissionDefinition('cms.page.edit_own', self::CODE, 'page.permissions.edit_own', requires: ['cms.page.view_own']),
            new PermissionDefinition('cms.page.edit', self::CODE, 'page.permissions.edit', requires: ['cms.page.edit_own', 'cms.page.view']),
            new PermissionDefinition('cms.page.publish_own', self::CODE, 'page.permissions.publish_own', requires: ['cms.page.view_own']),
            new PermissionDefinition('cms.page.publish', self::CODE, 'page.permissions.publish', requires: ['cms.page.publish_own', 'cms.page.view']),
            new PermissionDefinition('cms.page.delete_own', self::CODE, 'page.permissions.delete_own', requires: ['cms.page.view_own']),
            new PermissionDefinition('cms.page.delete', self::CODE, 'page.permissions.delete', requires: ['cms.page.delete_own', 'cms.page.view']),
            new PermissionDefinition('cms.page.restore_own', self::CODE, 'page.permissions.restore_own', requires: ['cms.page.view_own']),
            new PermissionDefinition('cms.page.restore', self::CODE, 'page.permissions.restore', requires: ['cms.page.restore_own', 'cms.page.view']),
            new PermissionDefinition('cms.page.assign_owner', self::CODE, 'page.permissions.assign_owner', requires: ['cms.page.view_own']),
        ];
    }

    /**
     * Declares audit events for page mutations
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
            new AuditEventDefinition('owner_transferred', 'page.audit.events.owner_transferred', AdminIcon::PersonGear),
        ];
    }
}
