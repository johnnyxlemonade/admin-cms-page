<?php

declare(strict_types=1);

namespace Lemonade\Cms\Page\Tests\Unit\Editor;

use Lemonade\Admin\Editor\AdminEditor\Field\TextareaField;
use Lemonade\Admin\Editor\AdminEditor\Field\UrlSegmentField;
use Lemonade\Admin\Editor\AdminEditor\Layout\FieldGroupBlock;
use Lemonade\Admin\Editor\AdminEditor\Layout\SectionBlock;
use Lemonade\Admin\Module\AdminModuleRegistry;
use Lemonade\Admin\Module\AdminModuleRouteResolver;
use Lemonade\Admin\Navigation\AdminNavigationGroupRegistry;
use Lemonade\Cms\Page\Editor\PageAdminEditorDefinitionFactory;
use Lemonade\Cms\Page\ModuleManifest;
use Lemonade\Framework\Routing\ControllerAction;
use Lemonade\Framework\Routing\Router;
use Lemonade\Framework\Routing\UrlGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Overuje skladbu full-page editoru CMS stranek
 */
final class PageAdminEditorDefinitionFactoryTest extends TestCase
{
    /**
     * Sklada content, SEO, settings, file media a publication casti se spravnymi kontrakty poli
     */
    public function testCreatesContentSeoSettingsAndPublicationComposition(): void
    {
        $router = new Router();
        $router->getNamed('admin.content.module.index', '/admin/content/{module}', ControllerAction::for('PageController', 'index'));
        $router->postNamed('admin.content.module.update', '/admin/content/{module}/edit/{id}', ControllerAction::for('PageController', 'update'));
        $router->postNamed('admin.content.module.ajax.entity', '/admin/content/{module}/ajax/{id}', ControllerAction::for('PageController', 'save'));
        $factory = new PageAdminEditorDefinitionFactory(new UrlGenerator($router), $this->routes());

        $editor = $factory->create(
            page: [
                'id' => 16,
                'parent_id' => 4,
                'sort_order' => 2,
                'template_key' => 'content',
                'translations' => [[
                    'locale' => 'en',
                    'publication_state' => 2,
                    'published_at' => '2026-10-19 10:30:00',
                    'title' => 'Contact',
                    'content' => '<p>Content</p>',
                    'page_title' => 'Contact us',
                    'meta_description' => null,
                    'canonical_path' => 'office/contact',
                ]],
            ],
            contentLocale: 'en',
            canPublish: true,
            parentOptions: ['' => 'Root', 'page-4' => 'Office'],
            templateOptions: ['content' => 'Content'],
            context: null,
        );

        self::assertSame(['content', 'seo', 'settings', 'gallery', 'attachments'], array_map(static fn($tab): string => $tab->id(), $editor->tabs()));
        self::assertNotNull($editor->sidebar());
        $contentSection = $editor->tabs()[0]->blocks()[0];
        self::assertInstanceOf(SectionBlock::class, $contentSection);
        $fields = $contentSection->blocks()[0];
        self::assertInstanceOf(FieldGroupBlock::class, $fields);
        $contentField = $fields->columns()[2]->field();
        self::assertInstanceOf(TextareaField::class, $contentField);
        self::assertTrue($contentField->isRichText());
        self::assertTrue($contentField->hasImageUpload());
        self::assertTrue($contentField->hasFileUpload());

        $seoSection = $editor->tabs()[1]->blocks()[0];
        self::assertInstanceOf(SectionBlock::class, $seoSection);
        self::assertSame('page.editor.sections.seo_help', $seoSection->descriptionKey());
        $seoFields = $seoSection->blocks()[0];
        self::assertInstanceOf(FieldGroupBlock::class, $seoFields);
        self::assertSame('page.fields.page_title_help', $seoFields->columns()[0]->field()->helpTranslationKey());
        self::assertSame('page.fields.meta_description_help', $seoFields->columns()[1]->field()->helpTranslationKey());

        $settingsSection = $editor->tabs()[2]->blocks()[0];
        self::assertInstanceOf(SectionBlock::class, $settingsSection);
        $settingsFields = $settingsSection->blocks()[0];
        self::assertInstanceOf(FieldGroupBlock::class, $settingsFields);
        self::assertSame(
            ['parent_id', 'template_key'],
            array_map(static fn($column): string => $column->field()->name(), $settingsFields->columns()),
        );
        $urlField = $seoFields->columns()[2]->field();
        self::assertInstanceOf(UrlSegmentField::class, $urlField);
        self::assertSame('/office/', $urlField->pathPrefix());
        self::assertSame('change-url', $urlField->actionKey());
        self::assertFalse($urlField->autoGenerates());

        $draftEditor = $factory->create(
            page: [
                'id' => 17,
                'parent_id' => null,
                'template_key' => 'content',
                'translations' => [['locale' => 'en', 'title' => 'New page', 'canonical_path' => null]],
            ],
            contentLocale: 'en',
            canPublish: false,
            parentOptions: ['' => 'Root'],
            templateOptions: ['content' => 'Content'],
        );
        $draftSeo = $draftEditor->tabs()[1]->blocks()[0];
        self::assertInstanceOf(SectionBlock::class, $draftSeo);
        $draftFields = $draftSeo->blocks()[0];
        self::assertInstanceOf(FieldGroupBlock::class, $draftFields);
        $draftUrlField = $draftFields->columns()[2]->field();
        self::assertInstanceOf(UrlSegmentField::class, $draftUrlField);
        self::assertTrue($draftUrlField->autoGenerates());
    }

    /**
     * Vytvori route resolver pro Pages modulove routy
     */
    private function routes(): AdminModuleRouteResolver
    {
        $definition = new ModuleManifest();
        $adminModules = new AdminModuleRegistry(new AdminNavigationGroupRegistry());
        $adminModules->register($definition);

        return new AdminModuleRouteResolver($adminModules);
    }
}
