<?php

declare(strict_types=1);

namespace Lemonade\Cms\Page\Editor;

use Lemonade\Admin\Action\Presentation\AdminActionFormDefinition;
use Lemonade\Admin\Editor\AdminEditor\Action\AdminEditorActionDefinition;
use Lemonade\Admin\Editor\AdminEditor\Action\AdminEditorSaveBarDefinition;
use Lemonade\Admin\Editor\AdminEditor\AdminEditorBuilder;
use Lemonade\Admin\Editor\AdminEditor\AdminEditorDefinition;
use Lemonade\Admin\Editor\AdminEditor\Field\Field;
use Lemonade\Admin\Editor\AdminEditor\Header\AdminEditorHeaderContextDefinition;
use Lemonade\Admin\Editor\AdminEditor\Header\AdminEditorHeaderDefinition;
use Lemonade\Admin\Editor\AdminEditor\Layout\AdminEditorSidebarDefinition;
use Lemonade\Admin\Editor\AdminEditor\Layout\AdminEditorTab;
use Lemonade\Admin\Editor\AdminEditor\Layout\CustomViewBlock;
use Lemonade\Admin\Editor\AdminEditor\Layout\FieldColumn;
use Lemonade\Admin\Editor\AdminEditor\Layout\FieldGroupBlock;
use Lemonade\Admin\Editor\AdminEditor\Layout\SectionBlock;
use Lemonade\Admin\Module\AdminModuleRouteResolver;
use Lemonade\Admin\Presentation\AdminFileUploadCollection;
use Lemonade\Cms\CmsPublicationState;
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
            ->tab($this->contentTab($contentLocale))
            ->tab($this->seoTab($translation))
            ->tab($this->settingsTab($parentOptions, $templateOptions))
            ->tab($this->mediaTab('gallery', 'admin.file_upload.gallery', 'admin.file_upload.gallery', 'page.editor.sections.gallery_help', $galleryUpload))
            ->tab($this->mediaTab('attachments', 'admin.file_upload.attachments', 'admin.file_upload.attachments', 'page.editor.sections.attachments_help', $attachmentUpload));

        $sidebarSections = $this->sidebarSections(
            page: $page,
            canPublish: $canPublish,
            canAssignOwner: $canAssignOwner,
            ownerOptions: $ownerOptions,
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
                                Field::text('page_title', labelKey: 'page.fields.page_title')
                                    ->attributes(['maxlength' => '180'])
                                    ->help('', 'page.fields.page_title_help'),
                                md: 12,
                            ),
                            new FieldColumn(
                                Field::textarea('meta_description', labelKey: 'page.fields.meta_description')
                                    ->attributes(['maxlength' => '255'])
                                    ->help('', 'page.fields.meta_description_help'),
                                md: 12,
                            ),
                            new FieldColumn(
                                Field::urlSegment('url_segment')
                                    ->attributes(['maxlength' => '180'])
                                    ->prefix($this->urlPrefix($translation))
                                    ->source(
                                        field: 'title',
                                        autoGenerate: trim((string) ($translation['canonical_path'] ?? '')) === '',
                                    )
                                    ->action('change-url'),
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

    /**
     * Sestavi informativni tab pro chybejici file collection presentation
     */
    private function placeholderTab(string $id, string $labelKey, string $titleKey, string $descriptionKey): AdminEditorTab
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
                    descriptionKey: $descriptionKey,
                ),
            ],
            labelKey: $labelKey,
        );
    }

    /**
     * Sestavi globalni media tab nad shared Admin upload collection contractem
     */
    private function mediaTab(
        string $id,
        string $labelKey,
        string $titleKey,
        string $descriptionKey,
        ?AdminFileUploadCollection $upload,
    ): AdminEditorTab {
        if ($upload === null) {
            return $this->placeholderTab($id, $labelKey, $titleKey, $descriptionKey);
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
                    descriptionKey: $descriptionKey,
                ),
            ],
            labelKey: $labelKey,
        );
    }

    /**
     * Sestavi publikacni panel a shared upload nahledoveho obrazku
     *
     * @param array<string,mixed> $page
     * @param array<int,string> $ownerOptions
     * @return list<SectionBlock>
     */
    private function sidebarSections(
        array $page,
        bool $canPublish,
        bool $canAssignOwner,
        array $ownerOptions,
        ?AdminFileUploadCollection $featuredImageUpload,
    ): array {
        $sections = [];
        if ($canPublish) {
            $sections[] = new SectionBlock(
                id: 'publication',
                blocks: [
                    new FieldGroupBlock([
                        new FieldColumn(
                            Field::select('publication_state', labelKey: 'page.fields.publication_state')
                                ->options(array_fill_keys(array_keys(CmsPublicationState::editorOptionLabelKeys()), ''))
                                ->optionLabelKeys(CmsPublicationState::editorOptionLabelKeys()),
                            md: 12,
                        ),
                        new FieldColumn(
                            Field::datetimeLocal('published_at', labelKey: 'page.fields.published_at'),
                            md: 12,
                        ),
                    ]),
                ],
                title: '',
                titleKey: 'page.editor.publication',
                descriptionKey: 'page.editor.sections.publication_help',
            );
        }
        $metadata = [
            [
                'labelKey' => 'page.fields.created_by',
                'value' => trim((string) ($page['created_by_name'] ?? '')),
                'detail' => trim((string) ($page['created_at'] ?? '')),
            ],
            [
                'labelKey' => 'page.fields.updated_by',
                'value' => trim((string) ($page['updated_by_name'] ?? '')),
                'detail' => trim((string) ($page['updated_at'] ?? '')),
            ],
            [
                'labelKey' => 'page.fields.owner',
                'value' => trim((string) ($page['owner_name'] ?? '')),
                'detail' => null,
                'wide' => true,
                'current' => true,
            ],
        ];
        if (($page['deleted_at'] ?? null) !== null) {
            $metadata[] = [
                'labelKey' => 'page.fields.deleted_by',
                'value' => trim((string) ($page['deleted_by_name'] ?? '')),
                'detail' => trim((string) ($page['deleted_at'] ?? '')),
                'wide' => true,
            ];
        }
        $ownershipBlocks = [new CustomViewBlock('admin::components.editor-audit-metadata', ['metadata' => $metadata])];
        if ($canAssignOwner) {
            $ownershipBlocks[] = new FieldGroupBlock([new FieldColumn(
                Field::actionSelect('owner_id', 'assign-owner', labelKey: 'page.actions.assign_owner', actionLabelKey: 'page.actions.assign_owner_submit')
                    ->options($ownerOptions)
                    ->attributes(['data-lemonade-searchable' => true]),
                md: 12,
            )]);
        }
        $sections[] = new SectionBlock(
            id: 'ownership',
            blocks: $ownershipBlocks,
            title: '',
            titleKey: 'page.editor.ownership',
            descriptionKey: 'page.editor.sections.ownership_help',
        );
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
                descriptionKey: 'page.editor.sections.thumbnail_help',
            );
        }

        return $sections;
    }
}
