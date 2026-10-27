<?php

declare(strict_types=1);

namespace Lemonade\Cms\Page\Tests\Unit\Editor;

use Lemonade\Admin\Editor\AdminEditor\AdminEditorBlock;
use Lemonade\Admin\Editor\AdminEditor\Field\ActionSelectField;
use Lemonade\Admin\Editor\AdminEditor\Field\AdminEditorFieldDefinition;
use Lemonade\Admin\Editor\AdminEditor\Field\SelectField;
use Lemonade\Admin\Editor\AdminEditor\Field\TextareaField;
use Lemonade\Admin\Editor\AdminEditor\Field\UrlSegmentField;
use Lemonade\Admin\Editor\AdminEditor\Header\AdminEditorHeaderContextDefinition;
use Lemonade\Admin\Editor\AdminEditor\Header\AdminEditorHeaderContextItem;
use Lemonade\Admin\Editor\AdminEditor\Layout\CustomViewBlock;
use Lemonade\Admin\Editor\AdminEditor\Layout\FieldGroupBlock;
use Lemonade\Admin\Editor\AdminEditor\Layout\SectionBlock;
use Lemonade\Admin\Module\AdminModuleRegistry;
use Lemonade\Admin\Module\AdminModuleRouteResolver;
use Lemonade\Admin\Navigation\AdminNavigationGroupRegistry;
use Lemonade\Admin\Presentation\AdminFilePresentation;
use Lemonade\Admin\Presentation\AdminFileUploadCollection;
use Lemonade\Admin\Presentation\AdminFileUploadProfilePresentation;
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
        $summaryField = $fields->columns()[2]->field();
        self::assertInstanceOf(TextareaField::class, $summaryField);
        self::assertSame('summary', $summaryField->name());
        self::assertFalse($summaryField->isRichText());
        $contentField = $fields->columns()[3]->field();
        self::assertInstanceOf(TextareaField::class, $contentField);
        self::assertTrue($contentField->isRichText());
        self::assertTrue($contentField->hasImageUpload());
        self::assertTrue($contentField->hasFileUpload());

        $seoSection = $editor->tabs()[1]->blocks()[0];
        self::assertInstanceOf(SectionBlock::class, $seoSection);
        self::assertSame('admin.seo.tab', $editor->tabs()[1]->labelKey());
        self::assertSame('admin.seo.section', $seoSection->titleKey());
        self::assertSame('admin.seo.section_help', $seoSection->descriptionKey());
        $seoFields = $seoSection->blocks()[0];
        self::assertInstanceOf(FieldGroupBlock::class, $seoFields);
        self::assertSame('admin.seo.page_title_help', $seoFields->columns()[0]->field()->helpTranslationKey());
        self::assertSame('admin.seo.meta_description_help', $seoFields->columns()[1]->field()->helpTranslationKey());

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

    public function testSharedLayoutAndDomainFieldsRemainIntact(): void
    {
        $router = new Router();
        $router->getNamed('admin.content.module.index', '/admin/content/{module}', ControllerAction::for('EditorController', 'index'));
        $router->postNamed('admin.content.module.update', '/admin/content/{module}/edit/{id}', ControllerAction::for('EditorController', 'update'));
        $router->postNamed('admin.content.module.ajax.entity', '/admin/content/{module}/ajax/{id}', ControllerAction::for('EditorController', 'save'));
        $factory = new PageAdminEditorDefinitionFactory(new UrlGenerator($router), $this->routes());
        $upload = new AdminFileUploadCollection(
            moduleCode: 'pages',
            entityId: 23,
            usage: 'gallery',
            files: [],
            startUrl: '/start',
            appendUrlTemplate: '/append',
            completeUrlTemplate: '/complete',
            abortUrlTemplate: '/abort',
            removeUrlTemplate: '/remove',
            reorderUrl: '/reorder',
            renameModalUrlTemplate: '/rename',
            labelKey: 'gallery',
            helpKey: 'gallery_help',
            kind: 'image',
            multiple: true,
            sortable: true,
            editable: true,
            profile: new AdminFileUploadProfilePresentation('image/*', ['jpg'], 1000, '1 KB'),
            presentation: AdminFilePresentation::Standard,
            fallback: null,
            alt: '',
        );
        $context = new AdminEditorHeaderContextDefinition('Content language', [
            new AdminEditorHeaderContextItem('English', '/en', active: true),
        ]);
        $content = [
            'id' => 23,
            'created_by_name' => 'Creator', 'created_at' => '2026-01-01',
            'updated_by_name' => 'Editor', 'updated_at' => '2026-01-02',
            'owner_name' => 'Owner', 'deleted_by_name' => 'Remover', 'deleted_at' => '2026-01-03',
            'translations' => [['locale' => 'en', 'title' => 'Title', 'canonical_path' => null, 'tags' => ['Existing']]],
        ];
        $editor = $factory->create(
            page: $content,
            contentLocale: 'en',
            canPublish: true,
            parentOptions: ['' => 'Root'],
            templateOptions: ['content' => 'Content'],
            canAssignOwner: true,
            ownerOptions: [7 => 'New owner'],
            context: $context,
            featuredImageUpload: $upload,
            galleryUpload: $upload,
            attachmentUpload: $upload,
        );
        self::assertSame(['content', 'seo', 'settings', 'gallery', 'attachments'], array_map(static fn($tab): string => $tab->id(), $editor->tabs()));
        self::assertTrue($editor->tabs()[0]->default());
        $header = $editor->header();
        self::assertNotNull($header);
        self::assertSame($context, $header->context());
        self::assertTrue($header->context()->items()[0]->active());
        self::assertSame('page.editor.title', $header->titleKey());
        self::assertSame('page.module.name', $header->breadcrumbs()[0]['labelKey'] ?? null);
        self::assertSame('/admin/content/pages', $header->actions()[0]->href());
        self::assertTrue($header->actions()[1]->submit());
        $saveBar = $editor->saveBar();
        self::assertNotNull($saveBar);
        self::assertSame('admin.editor.unsaved_changes', $saveBar->titleKey());
        self::assertSame('admin.editor.unsaved_changes_help', $saveBar->descriptionKey());
        self::assertSame('admin.common.save_changes', $saveBar->primaryAction()->labelKey());
        self::assertSame('admin.common.discard', $saveBar->secondaryAction()?->labelKey());
        self::assertSame('save', $editor->form()->actionKey());
        self::assertSame(['content'], array_map(static fn($section): string => $section instanceof SectionBlock ? $section->id() : '', $editor->tabs()[0]->blocks()));
        self::assertSame(['settings'], array_map(static fn($section): string => $section instanceof SectionBlock ? $section->id() : '', $editor->tabs()[2]->blocks()));

        /** @param list<AdminEditorBlock> $sections
         *  @return list<string>
         */
        $fieldNames = static function (array $sections): array {
            $names = [];
            foreach ($sections as $section) {
                self::assertInstanceOf(SectionBlock::class, $section);
                foreach ($section->blocks() as $block) {
                    if ($block instanceof AdminEditorFieldDefinition) {
                        $names[] = $block->name();
                    } elseif ($block instanceof FieldGroupBlock) {
                        foreach ($block->columns() as $column) {
                            $names[] = $column->field()->name();
                        }
                    }
                }
            }
            return $names;
        };
        self::assertSame(['locale', 'title', 'summary', 'content'], $fieldNames($editor->tabs()[0]->blocks()));
        self::assertSame(['parent_id', 'template_key'], $fieldNames($editor->tabs()[2]->blocks()));
        self::assertSame(['page_title', 'meta_description', 'url_segment'], $fieldNames($editor->tabs()[1]->blocks()));
        $contentSection = $editor->tabs()[0]->blocks()[0];
        self::assertInstanceOf(SectionBlock::class, $contentSection);
        $contentFields = $contentSection->blocks()[0];
        self::assertInstanceOf(FieldGroupBlock::class, $contentFields);
        self::assertSame('en', $contentFields->columns()[0]->field()->structuralDefaultValue());
        foreach ([3 => 'gallery', 4 => 'attachments'] as $index => $id) {
            $section = $editor->tabs()[$index]->blocks()[0];
            self::assertInstanceOf(SectionBlock::class, $section);
            self::assertSame($id, $section->id());
            self::assertSame('page.editor.sections.' . $id . '_help', $section->descriptionKey());
            $uploadView = $section->blocks()[0];
            self::assertInstanceOf(CustomViewBlock::class, $uploadView);
            self::assertSame('admin::components.file-upload-collection', $uploadView->view());
            self::assertSame($upload, $uploadView->context()['uploadCollection']);
        }
        $panels = $editor->sidebar()?->panels();
        self::assertNotNull($panels);
        self::assertSame(['publication', 'ownership', 'thumbnail'], array_map(static fn($panel): string => $panel->id(), $panels));
        $publicationFields = $panels[0]->blocks()[0];
        self::assertInstanceOf(FieldGroupBlock::class, $publicationFields);
        self::assertSame(['publication_state', 'published_at'], array_map(static fn($column): string => $column->field()->name(), $publicationFields->columns()));
        self::assertInstanceOf(SelectField::class, $publicationFields->columns()[0]->field());
        self::assertSame('page.editor.sections.publication_help', $panels[0]->descriptionKey());
        $auditView = $panels[1]->blocks()[0];
        self::assertInstanceOf(CustomViewBlock::class, $auditView);
        self::assertSame('admin::components.editor-audit-metadata', $auditView->view());
        $metadata = $auditView->context()['metadata'];
        self::assertSame(['Creator', 'Editor', 'Owner', 'Remover'], array_column($metadata, 'value'));
        self::assertSame('2026-01-03', $metadata[3]['detail']);
        $ownerGroup = $panels[1]->blocks()[1];
        self::assertInstanceOf(FieldGroupBlock::class, $ownerGroup);
        $ownerField = $ownerGroup->columns()[0]->field();
        self::assertInstanceOf(ActionSelectField::class, $ownerField);
        self::assertSame('assign-owner', $ownerField->actionKey());
        self::assertSame([7 => 'New owner'], $ownerField->selectOptions());
        self::assertSame('page.actions.assign_owner_submit', $ownerField->actionTranslationKey());
        $thumbnailView = $panels[2]->blocks()[0];
        self::assertInstanceOf(CustomViewBlock::class, $thumbnailView);
        self::assertSame($upload, $thumbnailView->context()['uploadCollection']);

        $restricted = $factory->create(
            page: $content,
            contentLocale: 'en',
            canPublish: false,
            parentOptions: ['' => 'Root'],
            templateOptions: ['content' => 'Content'],
        );
        self::assertSame(['ownership'], array_map(static fn($panel): string => $panel->id(), $restricted->sidebar()?->panels() ?? []));
        self::assertCount(1, $restricted->sidebar()?->panels()[0]->blocks() ?? []);
        $emptyGallery = $restricted->tabs()[3]->blocks()[0];
        $emptyAttachments = $restricted->tabs()[4]->blocks()[0];
        self::assertInstanceOf(SectionBlock::class, $emptyGallery);
        self::assertInstanceOf(SectionBlock::class, $emptyAttachments);
        self::assertSame([], $emptyGallery->blocks());
        self::assertSame([], $emptyAttachments->blocks());
        self::assertSame('admin.file_upload.gallery', $emptyGallery->titleKey());
        self::assertSame('admin.file_upload.attachments', $emptyAttachments->titleKey());
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
