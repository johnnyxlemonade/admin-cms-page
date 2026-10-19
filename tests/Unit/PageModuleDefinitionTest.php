<?php

declare(strict_types=1);

namespace Lemonade\Cms\Page\Tests\Unit;

use Lemonade\Admin\Module\Contract\AdminModuleFileCapabilitiesInterface;
use Lemonade\Admin\Presentation\AdminFilePresentation;
use Lemonade\Admin\Presentation\AdminFileUsageRegistry;
use Lemonade\Cms\Page\PageModuleDefinition;
use PHPUnit\Framework\TestCase;

/**
 * Overuje file capability a shared usage kontrakty CMS stranek
 */
final class PageModuleDefinitionTest extends TestCase
{
    /**
     * Deklaruje stejne file capability jako ostatni plny CMS content modul
     */
    public function testDefinesCompleteFileCapabilities(): void
    {
        $definition = new PageModuleDefinition();

        self::assertInstanceOf(AdminModuleFileCapabilitiesInterface::class, $definition);
        self::assertSame(AdminFilePresentation::Landscape, $definition->fileCapabilities()->thumbnail);
        self::assertTrue($definition->fileCapabilities()->gallery);
        self::assertTrue($definition->fileCapabilities()->attachments);
        self::assertTrue($definition->fileCapabilities()->richTextImages);
        self::assertTrue($definition->fileCapabilities()->richTextFiles);
    }

    /**
     * Bootstrap capability rozvine vsechny upload usages pod canonical module kodem
     */
    public function testRegistersAllFileUsagesForCanonicalModuleCode(): void
    {
        $definition = new PageModuleDefinition();
        $registry = new AdminFileUsageRegistry();
        $registry->registerCapabilities($definition->code(), $definition->fileCapabilities());

        self::assertSame(AdminFilePresentation::Landscape, $registry->require('cms.page', 'thumbnail')->presentation());
        self::assertTrue($registry->require('cms.page', 'gallery')->sortable());
        self::assertTrue($registry->require('cms.page', 'attachment')->sortable());
        self::assertSame('content_image', $registry->richTextImage('cms.page')->usage());
        self::assertSame('content_file', $registry->richTextFile('cms.page')->usage());
    }
}
