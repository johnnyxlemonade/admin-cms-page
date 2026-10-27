<?php

declare(strict_types=1);

namespace Lemonade\Cms\Page\Editor;

use Lemonade\Admin\Action\Presentation\AdminActionFormDefinition;
use Lemonade\Admin\Editor\AdminEditor\AdminEditorBuilder;
use Lemonade\Admin\Editor\AdminEditor\AdminEditorDefinition;
use Lemonade\Admin\Editor\AdminEditor\Field\Field;
use Lemonade\Admin\Editor\AdminEditor\Header\AdminEditorHeaderContextDefinition;
use Lemonade\Admin\Editor\AdminEditor\Layout\AdminEditorSidebarDefinition;
use Lemonade\Admin\Editor\AdminEditor\Layout\AdminEditorTab;
use Lemonade\Admin\Editor\AdminEditor\Layout\CmsEditorHeader;
use Lemonade\Admin\Editor\AdminEditor\Layout\CmsEditorSaveBar;
use Lemonade\Admin\Editor\AdminEditor\Layout\CmsMediaSection;
use Lemonade\Admin\Editor\AdminEditor\Layout\CmsOwnershipSection;
use Lemonade\Admin\Editor\AdminEditor\Layout\CmsPublicationSection;
use Lemonade\Admin\Editor\AdminEditor\Layout\FieldColumn;
use Lemonade\Admin\Editor\AdminEditor\Layout\FieldGroupBlock;
use Lemonade\Admin\Editor\AdminEditor\Layout\SectionBlock;
use Lemonade\Admin\Editor\AdminEditor\Layout\SeoSection;
use Lemonade\Admin\Module\AdminModuleRouteResolver;
use Lemonade\Admin\Presentation\AdminFileUploadCollection;
use Lemonade\Framework\Routing\UrlGenerator;

/**
 * Sklada full-page editor obsahu, SEO, nastaveni a publikace stranky
 */
final readonly class PageAdminEditorDefinitionFactory
{
    /**
     * Nastavuje named Admin routy pro formular a navigaci editoru
     */
    public function __construct(
        private UrlGenerator $urls,
        private AdminModuleRouteResolver $routes,
    ) {}

    /**
     * Vytvori editor jedne lokalizace s globalnim nastavenim stromu
     *
     * @param array<string,mixed> $page
     * @param array<string,string> $parentOptions
     * @param array<string,string> $templateOptions
     * @param array<int,string> $ownerOptions
     */
    public function create(
        array $page,
        string $contentLocale,
        bool $canPublish,
        array $parentOptions,
        array $templateOptions,
        bool $canAssignOwner = false,
        array $ownerOptions = [],
        ?AdminEditorHeaderContextDefinition $context = null,
        ?AdminFileUploadCollection $featuredImageUpload = null,
        ?AdminFileUploadCollection $galleryUpload = null,
        ?AdminFileUploadCollection $attachmentUpload = null,
    ): AdminEditorDefinition {
        $translation = $page['translations'][0] ?? [];
        $indexUrl = $this->urls->route($this->routes->managementRouteName('pages', 'index'), ['module' => 'pages']);
        $builder = AdminEditorBuilder::create('cms.page')
            ->form($this->form((int) $page['id'], $contentLocale))
            ->header(CmsEditorHeader::create('page', $indexUrl, $context))
            ->saveBar(CmsEditorSaveBar::create())
            ->tab($this->contentTab($contentLocale))
            ->tab(SeoSection::tab(pathPrefix: $this->urlPrefix($translation), autoGenerateUrl: trim((string) ($translation['canonical_path'] ?? '')) === ''))
            ->tab($this->settingsTab($parentOptions, $templateOptions))
            ->tab(CmsMediaSection::gallery('page', $galleryUpload))
            ->tab(CmsMediaSection::attachments('page', $attachmentUpload));

        $sidebarSections = [];
        $publication = CmsPublicationSection::create('page', $canPublish);
        if ($publication !== null) {
            $sidebarSections[] = $publication;
        }
        $sidebarSections[] = CmsOwnershipSection::create('page', $page, $canAssignOwner, $ownerOptions);
        $thumbnail = CmsMediaSection::thumbnail('page', $featuredImageUpload);
        if ($thumbnail !== null) {
            $sidebarSections[] = $thumbnail;
        }
        $builder->sidebar(new AdminEditorSidebarDefinition($sidebarSections));

        return $builder->build();
    }

    /**
     * Vytvori canonical save transport se zachovanym content locale
     */
    private function form(int $pageId, string $contentLocale): AdminActionFormDefinition
    {
        $params = ['module' => 'pages', 'id' => $pageId, 'locale' => $contentLocale];

        return new AdminActionFormDefinition(
            id: 'page-editor-form',
            action: $this->urls->route($this->routes->managementRouteName('pages', 'update'), $params),
            actionUrl: $this->urls->route($this->routes->managementRouteName('pages', 'ajax.entity'), $params),
            actionKey: 'save',
            novalidate: true,
        );
    }

    /**
     * Vytvori tab nadpisu a rich-text obsahu jedne mutation
     *
     */
    private function contentTab(string $locale): AdminEditorTab
    {
        $fields = [
            new FieldColumn(Field::hidden('locale')->defaultValue($locale), md: 12),
            new FieldColumn(
                Field::text('title', labelKey: 'page.fields.title')
                    ->required()
                    ->attributes(['maxlength' => '180']),
                md: 12,
            ),
            new FieldColumn(
                Field::textarea('summary', labelKey: 'page.fields.summary'),
                md: 12,
            ),
            new FieldColumn(
                Field::textarea('content', labelKey: 'page.fields.content')
                    ->richText(imageUpload: true, fileUpload: true),
                md: 12,
            ),
        ];
        return new AdminEditorTab(
            id: 'content',
            label: '',
            blocks: [new SectionBlock(
                id: 'content',
                blocks: [new FieldGroupBlock($fields)],
                title: '',
                titleKey: 'page.editor.sections.content',
                descriptionKey: 'page.editor.sections.content_help',
            )],
            labelKey: 'page.editor.tabs.content',
            default: true,
        );
    }

    /**
     * @param array<string,string> $parentOptions
     * @param array<string,string> $templateOptions
     */
    private function settingsTab(array $parentOptions, array $templateOptions): AdminEditorTab
    {
        return new AdminEditorTab(
            id: 'settings',
            label: '',
            blocks: [new SectionBlock(
                id: 'settings',
                blocks: [new FieldGroupBlock([
                    new FieldColumn(
                        Field::select('parent_id', labelKey: 'page.fields.parent')
                            ->options($parentOptions),
                        md: 6,
                    ),
                    new FieldColumn(
                        Field::select('template_key', labelKey: 'page.fields.template')
                            ->options($templateOptions),
                        md: 6,
                    ),
                ])],
                title: '',
                titleKey: 'page.editor.sections.settings',
                descriptionKey: 'page.editor.sections.settings_help',
            )],
            labelKey: 'page.editor.tabs.settings',
        );
    }

    /**
     * Vrati needitovatelnou rodicovskou cast canonical cesty
     *
     * @param array<string,mixed> $translation
     */
    private function urlPrefix(array $translation): string
    {
        $path = trim((string) ($translation['canonical_path'] ?? ''), '/');
        if ($path === '' || !str_contains($path, '/')) {
            return '/';
        }

        return '/' . substr($path, 0, (int) strrpos($path, '/') + 1);
    }
}
