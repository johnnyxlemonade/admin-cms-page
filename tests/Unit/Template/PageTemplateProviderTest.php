<?php

declare(strict_types=1);

namespace Lemonade\Cms\Page\Tests\Unit\Template;

use Lemonade\Cms\Page\CmsPageServiceProvider;
use Lemonade\Cms\Page\Template\PageTemplateCatalogInterface;
use Lemonade\Cms\Page\Template\PageTemplateDefinition;
use Lemonade\Cms\Page\Template\PageTemplateProviderInterface;
use Lemonade\Framework\Container\Container;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the package-owned page template offer and host contributions
 */
final class PageTemplateProviderTest extends TestCase
{
    /**
     * Makes the general template available without host registration
     */
    public function testProvidesGeneralTemplateWithoutHostContributions(): void
    {
        $container = new Container();
        (new CmsPageServiceProvider())->register($container);

        $templates = $container->get(PageTemplateProviderInterface::class)->templates();

        self::assertCount(1, $templates);
        self::assertSame('default', $templates[0]->getKey());
        self::assertSame('page.templates.default', $templates[0]->getLabelKey());
    }

    /**
     * Adds tagged host templates after the general template
     */
    public function testIncludesTaggedHostTemplate(): void
    {
        $container = new Container();
        (new CmsPageServiceProvider())->register($container);
        $container->singletonTagged(
            'app.page.template.landing',
            new PageTemplateDefinition('landing', 'page_template.landing'),
            PageTemplateDefinition::class,
        );

        $templates = $container->get(PageTemplateProviderInterface::class)->templates();

        self::assertSame(['default', 'landing'], array_map(
            static fn(PageTemplateDefinition $template): string => $template->getKey(),
            $templates,
        ));
    }

    /**
     * Adds all templates from one host catalog after the general template
     */
    public function testIncludesHostTemplateCatalog(): void
    {
        $container = new Container();
        (new CmsPageServiceProvider())->register($container);
        $container->singletonTagged(
            HostPageTemplateCatalog::class,
            HostPageTemplateCatalog::class,
            PageTemplateCatalogInterface::class,
        );

        $templates = $container->get(PageTemplateProviderInterface::class)->templates();

        self::assertSame(['default', 'landing', 'contact'], array_map(
            static fn(PageTemplateDefinition $template): string => $template->getKey(),
            $templates,
        ));
    }

    /**
     * Prevents a host catalog from replacing the package-owned general template
     */
    public function testRejectsGeneralTemplateFromHostCatalog(): void
    {
        $container = new Container();
        (new CmsPageServiceProvider())->register($container);
        $container->singletonTagged(
            DuplicateGeneralPageTemplateCatalog::class,
            DuplicateGeneralPageTemplateCatalog::class,
            PageTemplateCatalogInterface::class,
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Page template code "default" is registered more than once.');

        $container->get(PageTemplateProviderInterface::class)->templates();
    }

    /**
     * Rejects a host contribution that repeats the general template code
     */
    public function testRejectsDuplicateGeneralTemplate(): void
    {
        $container = new Container();
        (new CmsPageServiceProvider())->register($container);
        $container->singletonTagged(
            'app.page.template.default',
            new PageTemplateDefinition('default', 'page_template.duplicate'),
            PageTemplateDefinition::class,
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Page template code "default" is registered more than once.');

        $container->get(PageTemplateProviderInterface::class)->templates();
    }

    /**
     * Rejects duplicate codes across separate host contributions
     */
    public function testRejectsDuplicateHostTemplates(): void
    {
        $container = new Container();
        (new CmsPageServiceProvider())->register($container);
        $container->singletonTagged(
            'app.page.template.landing',
            new PageTemplateDefinition('landing', 'page_template.landing'),
            PageTemplateDefinition::class,
        );
        $container->singletonTagged(
            'app.page.template.landing-again',
            new PageTemplateDefinition('landing', 'page_template.landing_again'),
            PageTemplateDefinition::class,
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Page template code "landing" is registered more than once.');

        $container->get(PageTemplateProviderInterface::class)->templates();
    }
}

/**
 * Provides page templates supported by the test host
 */
final readonly class HostPageTemplateCatalog implements PageTemplateCatalogInterface
{
    /**
     * Returns the test host page templates
     */
    public function templates(): array
    {
        return [
            new PageTemplateDefinition('landing', 'page_template.landing'),
            new PageTemplateDefinition('contact', 'page_template.contact'),
        ];
    }
}

/**
 * Attempts to replace the package-owned general template
 */
final readonly class DuplicateGeneralPageTemplateCatalog implements PageTemplateCatalogInterface
{
    /**
     * Returns an invalid host override of the general template
     */
    public function templates(): array
    {
        return [new PageTemplateDefinition('default', 'page_template.default')];
    }
}
