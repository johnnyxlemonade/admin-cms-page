<?php

declare(strict_types=1);

namespace Lemonade\Cms\Page\Editor;

use Lemonade\Admin\Editor\AdminEditor\AdminEditorActionDefinition;
use Lemonade\Admin\Editor\AdminEditor\AdminEditorBuilder;
use Lemonade\Admin\Editor\AdminEditor\AdminEditorDefinition;
use Lemonade\Admin\Editor\AdminEditor\AdminEditorFieldDefinition;
use Lemonade\Admin\Editor\AdminEditor\AdminEditorFormDefinition;
use Lemonade\Admin\Editor\AdminEditor\AdminEditorHeaderContextDefinition;
use Lemonade\Admin\Editor\AdminEditor\AdminEditorHeaderDefinition;
use Lemonade\Admin\Editor\AdminEditor\AdminEditorSaveBarDefinition;
use Lemonade\Admin\Editor\AdminEditor\AdminEditorSidebarDefinition;
use Lemonade\Admin\Editor\AdminEditor\AdminEditorTab;
use Lemonade\Admin\Editor\AdminEditor\CustomViewBlock;
use Lemonade\Admin\Editor\AdminEditor\FieldColumn;
use Lemonade\Admin\Editor\AdminEditor\FieldGroupBlock;
use Lemonade\Admin\Editor\AdminEditor\SectionBlock;
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
     */
    public function create(
        array $page,
        string $contentLocale,
        bool $canPublish,
        array $parentOptions,
        array $templateOptions,
        ?AdminEditorHeaderContextDefinition $context = null,
        ?AdminFileUploadCollection $featuredImageUpload = null,
        ?AdminFileUploadCollection $galleryUpload = null,
        ?AdminFileUploadCollection $attachmentUpload = null,
    ): AdminEditorDefinition {
        $translation = $page['translations'][0] ?? [];
        $builder = AdminEditorBuilder::create('cms.page')
            ->form($this->form((int) $page['id'], $contentLocale))
            ->header($this->header($context))
            ->saveBar(
                new AdminEditorSaveBarDefinition(
                    primaryAction: new AdminEditorActionDefinition(
                        label: '',
                        labelKey: 'admin.common.save_changes',
                        submit: true,
                        primary: true,
                    ),
                    secondaryAction: new AdminEditorActionDefinition(
                        label: '',
                        labelKey: 'admin.common.discard',
                    ),
                    titleKey: 'admin.editor.unsaved_changes',
                    descriptionKey: 'admin.editor.unsaved_changes_help',
                ),
            )
            ->tab($this->contentTab($translation, $contentLocale, $canPublish))
            ->tab($this->seoTab($translation))
            ->tab($this->settingsTab($page, $translation, $parentOptions, $templateOptions))
            ->tab($this->mediaTab('gallery', 'admin.file_upload.gallery', 'admin.file_upload.gallery', $galleryUpload))
            ->tab($this->mediaTab('attachments', 'admin.file_upload.attachments', 'admin.file_upload.attachments', $attachmentUpload));

        $sidebarSections = $this->sidebarSections(
            translation: $translation,
            canPublish: $canPublish,
            featuredImageUpload: $featuredImageUpload,
        );
        if ($sidebarSections !== []) {
            $builder->sidebar(new AdminEditorSidebarDefinition($sidebarSections));
        }

        return $builder->build();
    }

    /**
     * Vytvori canonical save transport se zachovanym content locale
     */
    private function form(int $pageId, string $contentLocale): AdminEditorFormDefinition
    {
        $params = ['module' => 'pages', 'id' => $pageId, 'locale' => $contentLocale];

        return new AdminEditorFormDefinition(
            id: 'page-editor-form',
            action: $this->urls->route($this->routes->managementRouteName('pages', 'update'), $params),
            actionUrl: $this->urls->route($this->routes->managementRouteName('pages', 'ajax.entity'), $params),
            actionKey: 'save',
            novalidate: true,
        );
    }

    /**
     * Vytvori hlavicku s navigaci a volitelnym locale kontextem
     */
    private function header(?AdminEditorHeaderContextDefinition $context): AdminEditorHeaderDefinition
    {
        $indexUrl = $this->urls->route($this->routes->managementRouteName('pages', 'index'), ['module' => 'pages']);

        return new AdminEditorHeaderDefinition(
            title: '',
            titleKey: 'page.editor.title',
            breadcrumbs: [
                ['label' => '', 'labelKey' => 'page.module.name', 'href' => $indexUrl],
                ['label' => '', 'labelKey' => 'page.editor.title', 'current' => true],
            ],
            actions: [
                new AdminEditorActionDefinition('', labelKey: 'admin.common.back', href: $indexUrl),
                new AdminEditorActionDefinition('', labelKey: 'admin.common.save', submit: true, primary: true),
            ],
            context: $context,
        );
    }

    /**
     * Vytvori tab nadpisu a rich-text obsahu jedne mutation
     *
     * @param array<string,mixed> $translation
     */
    private function contentTab(array $translation, string $locale, bool $canPublish): AdminEditorTab
    {
        $fields = [
            new FieldColumn(AdminEditorFieldDefinition::hidden('locale')->defaultValue($locale), md: 12),
            new FieldColumn(
                AdminEditorFieldDefinition::text('title', labelKey: 'page.fields.title')
                    ->required()
                    ->attributes(['maxlength' => '180'])
                    ->value((string) ($translation['title'] ?? '')),
                md: 12,
            ),
            new FieldColumn(
                AdminEditorFieldDefinition::textarea('content', labelKey: 'page.fields.content')
                    ->richText()
                    ->imageUpload()
                    ->fileUpload()
                    ->value((string) ($translation['content'] ?? '')),
                md: 12,
            ),
        ];
        if (!$canPublish) {
            $fields[] = new FieldColumn(
                AdminEditorFieldDefinition::hidden('publication_state')->defaultValue((string) ($translation['publication_state'] ?? 0)),
                md: 12,
            );
            $fields[] = new FieldColumn(
                AdminEditorFieldDefinition::hidden('published_at')
                    ->defaultValue((string) ($translation['published_at'] ?? '')),
                md: 12,
            );
        }

        return new AdminEditorTab(
            id: 'content',
            label: '',
            blocks: [new SectionBlock(
                id: 'content',
                blocks: [new FieldGroupBlock($fields)],
                title: '',
                titleKey: 'page.editor.sections.content',
            )],
            labelKey: 'page.editor.tabs.content',
            default: true,
        );
    }

    /**
     * Vytvori tab lokalizovanych SEO metadat
     *
     * @param array<string,mixed> $translation
     */
    private function seoTab(array $translation): AdminEditorTab
    {
        return new AdminEditorTab(
            id: 'seo',
            label: '',
            blocks: [
                new SectionBlock(
                    id: 'seo',
                    blocks: [
                        new FieldGroupBlock([
                            new FieldColumn(
                                AdminEditorFieldDefinition::text('page_title', labelKey: 'page.fields.page_title')
                                    ->attributes(['maxlength' => '180'])
                                    ->help('', 'page.fields.page_title_help')
                                    ->value((string) ($translation['page_title'] ?? '')),
                                md: 12,
                            ),
                            new FieldColumn(
                                AdminEditorFieldDefinition::textarea('meta_description', labelKey: 'page.fields.meta_description')
                                    ->attributes(['maxlength' => '255'])
                                    ->help('', 'page.fields.meta_description_help')
                                    ->value((string) ($translation['meta_description'] ?? '')),
                                md: 12,
                            ),
                        ]),
                    ],
                    title: '',
                    titleKey: 'page.editor.sections.seo',
                    description: '',
                    descriptionKey: 'page.editor.sections.seo_help',
                ),
            ],
            labelKey: 'page.editor.tabs.seo',
        );
    }

    /**
     * @param array<string,mixed> $page
     * @param array<string,mixed> $translation
     * @param array<string,string> $parentOptions
     * @param array<string,string> $templateOptions
     */
    private function settingsTab(array $page, array $translation, array $parentOptions, array $templateOptions): AdminEditorTab
    {
        return new AdminEditorTab(
            id: 'settings',
            label: '',
            blocks: [new SectionBlock(
                id: 'settings',
                blocks: [new FieldGroupBlock([
                    new FieldColumn(
                        AdminEditorFieldDefinition::select('parent_id', labelKey: 'page.fields.parent')
                            ->options($parentOptions)
                            ->value($page['parent_id'] === null ? '' : 'page-' . (int) $page['parent_id']),
                        md: 6,
                    ),
                    new FieldColumn(
                        AdminEditorFieldDefinition::select('template_key', labelKey: 'page.fields.template')
                            ->options($templateOptions)
                            ->value((string) ($page['template_key'] ?? '')),
                        md: 6,
                    ),
                    new FieldColumn(
                        AdminEditorFieldDefinition::readonlyEditable('url_segment', labelKey: 'page.fields.url_segment')
                            ->attributes(['maxlength' => '180'])
                            ->help('', 'page.fields.url_segment_help')
                            ->unlock('', 'page.editor.edit_url')
                            ->marker(AdminEditorFieldDefinition::hidden('url_segment_edited')->defaultValue('0'))
                            ->value((string) ($translation['url_segment'] ?? '')),
                        md: 6,
                    ),
                ])],
                title: '',
                titleKey: 'page.editor.sections.settings',
            )],
            labelKey: 'page.editor.tabs.settings',
        );
    }

    /**
     * Sestavi informativni tab pro chybejici file collection presentation
     */
    private function placeholderTab(string $id, string $labelKey, string $titleKey): AdminEditorTab
    {
        return new AdminEditorTab(
            id: $id,
            label: '',
            blocks: [
                new SectionBlock(
                    id: $id,
                    blocks: [],
                    title: '',
                    titleKey: $titleKey,
                ),
            ],
            labelKey: $labelKey,
        );
    }

    /**
     * Sestavi globalni media tab nad shared Admin upload collection contractem
     */
    private function mediaTab(string $id, string $labelKey, string $titleKey, ?AdminFileUploadCollection $upload): AdminEditorTab
    {
        if ($upload === null) {
            return $this->placeholderTab($id, $labelKey, $titleKey);
        }

        return new AdminEditorTab(
            id: $id,
            label: '',
            blocks: [
                new SectionBlock(
                    id: $id,
                    blocks: [new CustomViewBlock('admin::components.file-upload-collection', ['uploadCollection' => $upload])],
                    title: '',
                    titleKey: $titleKey,
                ),
            ],
            labelKey: $labelKey,
        );
    }

    /**
     * Sestavi publikacni panel a shared upload nahledoveho obrazku
     *
     * @param array<string,mixed> $translation
     * @return list<SectionBlock>
     */
    private function sidebarSections(
        array $translation,
        bool $canPublish,
        ?AdminFileUploadCollection $featuredImageUpload,
    ): array {
        $sections = [];
        if ($canPublish) {
            $sections[] = new SectionBlock(
                id: 'publication',
                blocks: [
                    new FieldGroupBlock([
                        new FieldColumn(
                            AdminEditorFieldDefinition::select('publication_state', labelKey: 'page.fields.publication_state')
                                ->options(['0' => '', '1' => '', '2' => ''])
                                ->optionLabelKeys([
                                    '0' => 'admin.status.new',
                                    '1' => 'admin.status.draft',
                                    '2' => 'admin.status.published',
                                ])
                                ->value((string) ($translation['publication_state'] ?? 0)),
                            md: 12,
                        ),
                        new FieldColumn(
                            AdminEditorFieldDefinition::datetimeLocal('published_at', labelKey: 'page.fields.published_at')
                                ->value((string) ($translation['published_at'] ?? '')),
                            md: 12,
                        ),
                    ]),
                ],
                title: '',
                titleKey: 'page.editor.publication',
            );
        }
        if ($featuredImageUpload !== null) {
            $sections[] = new SectionBlock(
                id: 'thumbnail',
                blocks: [
                    new CustomViewBlock(
                        view: 'admin::components.file-upload-collection',
                        context: ['uploadCollection' => $featuredImageUpload],
                    ),
                ],
                title: '',
                titleKey: 'page.editor.thumbnail.title',
            );
        }

        return $sections;
    }
}
