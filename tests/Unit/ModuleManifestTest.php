<?php

declare(strict_types=1);

namespace Lemonade\Cms\Page\Tests\Unit;

use Lemonade\Cms\Page\CmsPageServiceProvider;
use Lemonade\Cms\Page\Migrations\CreateCmsPages;
use Lemonade\Cms\Page\ModuleManifest;
use PHPUnit\Framework\TestCase;

/**
 * Overuje Composer discovery a migration kontrakt modulu
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
        self::assertSame(CmsPageServiceProvider::class, $manifest->runtimeProvider());
        self::assertSame([CreateCmsPages::class], $manifest->migrations());
    }
}
