<?php

declare(strict_types=1);

namespace Lemonade\Cms\Page;

use Lemonade\Admin\Modules\Definition\ModuleKind;
use Lemonade\Admin\Modules\Manifest\ModuleManifestInterface;
use Lemonade\Admin\Modules\Migration\ModuleMigrationManifestInterface;
use Lemonade\Framework\Database\Migration\MigrationInterface;

/**
 * Deklaruje optional CMS Page modul a jeho schema
 */
final readonly class ModuleManifest implements ModuleManifestInterface, ModuleMigrationManifestInterface
{
    /**
     * Vrati stabilni kod Pages modulu
     */
    public function code(): string
    {
        return 'cms.page';
    }

    /**
     * Vrati optional lifecycle modulu
     */
    public function kind(): ModuleKind
    {
        return ModuleKind::Optional;
    }

    /**
     * Vrati prekladovy klic nazvu modulu
     */
    public function labelKey(): string
    {
        return 'page.module.name';
    }

    /**
     * Vrati provider runtime contributions modulu
     */
    public function runtimeProvider(): string
    {
        return CmsPageServiceProvider::class;
    }

    /**
     * Vrati migrace aplikovane pri instalaci modulu
     *
     * @return list<class-string<MigrationInterface>>
     */
    public function migrations(): array
    {
        return [Migrations\CreateCmsPages::class];
    }
}
