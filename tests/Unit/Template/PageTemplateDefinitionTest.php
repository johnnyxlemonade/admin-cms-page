<?php

declare(strict_types=1);

namespace Lemonade\Cms\Page\Tests\Unit\Template;

use InvalidArgumentException;
use Lemonade\Cms\Page\Template\PageTemplateDefinition;
use PHPUnit\Framework\TestCase;

/**
 * Overuje minimalni host kontrakt page templates
 */
final class PageTemplateDefinitionTest extends TestCase
{
    /**
     * Prijme stabilni template klic a hostem vlastneny prekladovy klic
     */
    public function testAcceptsStableTemplateKey(): void
    {
        $template = new PageTemplateDefinition('news-list', 'page_template.news_list');

        self::assertSame('news-list', $template->key());
        self::assertSame('page_template.news_list', $template->labelKey());
    }

    /**
     * Odmitne template klic ktery nelze bezpecne ulozit
     */
    public function testRejectsInvalidTemplateKey(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PageTemplateDefinition('News list', 'page_template.news_list');
    }
}
