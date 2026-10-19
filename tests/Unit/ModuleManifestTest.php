<?php

declare(strict_types=1);

namespace Lemonade\Cms\Page\Tests\Unit;

use Lemonade\Admin\Modules\Definition\ModuleKind;
use Lemonade\Cms\Page\CmsPageServiceProvider;
use Lemonade\Cms\Page\Migrations\CreateCmsPages;
use Lemonade\Cms\Page\ModuleManifest;
use PHPUnit\Framework\TestCase;

/**
 * Overuje Composer discovery a optional module lifecycle kontrakt
 */
final class ModuleManifestTest extends TestCase
{
    /**
     * Deklaruje optional modul s jedinou lifecycle migraci
     */
    public function testDeclaresOptionalCmsPageModuleAndItsSingleMigration(): void
    {
        $manifest = new ModuleManifest();

        self::assertSame('cms.page', $manifest->code());
        self::assertSame(ModuleKind::Optional, $manifest->kind());
        self::assertSame(CmsPageServiceProvider::class, $manifest->runtimeProvider());
        self::assertSame([CreateCmsPages::class], $manifest->migrations());
    }
}
