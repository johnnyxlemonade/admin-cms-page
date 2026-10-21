<?php

declare(strict_types=1);

namespace Lemonade\Cms\Page;

use Lemonade\Admin\Action\Presentation\AdminActionFormDefinition;
use Lemonade\Admin\Authorization\AuthorizationService;
use Lemonade\Admin\DataGrid\DataGridIndexViewModel;
use Lemonade\Admin\DataGrid\DataGridPrimaryAction;
use Lemonade\Admin\Editor\AdminEditor\Header\AdminEditorHeaderContextDefinition;
use Lemonade\Admin\Editor\AdminEditor\Header\AdminEditorHeaderContextItem;
use Lemonade\Admin\Editor\AdminEditor\Rendering\AdminEditorRenderContext;
use Lemonade\Admin\Editor\EditorLoaded;
use Lemonade\Admin\Editor\Exception\EditorEntityNotFoundException;
use Lemonade\Admin\Icon\AdminIcon;
use Lemonade\Admin\Localization\LanguageRegistry;
use Lemonade\Admin\Module\AdminModuleRouteResolver;
use Lemonade\Admin\Page\Contract\ModuleEditorPageProviderInterface;
use Lemonade\Admin\Page\Contract\ModuleIndexPageProviderInterface;
use Lemonade\Admin\Page\ModulePage;
use Lemonade\Admin\Presentation\AdminFileUploadComponent;
use Lemonade\Cms\Page\DataGrid\PageDataGrid;
use Lemonade\Cms\Page\Editor\PageAdminEditorDefinitionFactory;
use Lemonade\Cms\Page\Hierarchy\PageHierarchy;
use Lemonade\Cms\Page\Models\PageModel;
use Lemonade\Cms\Page\Template\PageTemplateRendererInterface;
use Lemonade\Framework\Localization\TranslatorInterface;
use Lemonade\Framework\Routing\UrlGenerator;

/**
 * Sklada index a full-page editor CMS stranek
 */
final readonly class PageModulePageProvider implements ModuleIndexPageProviderInterface, ModuleEditorPageProviderInterface
{
    /**
     * Nastavuje Pages grid, editor presentation, preklady a host template contract
     */
    public function __construct(
        private PageDataGrid $grid,
        private AuthorizationService $authorization,
        private TranslatorInterface $translator,
        private UrlGenerator $urls,
        private AdminModuleRouteResolver $routes,
        private PageAdminEditorDefinitionFactory $adminEditorDefinitions,
        private AdminFileUploadComponent $files,
        private PageModel $pages,
        private PageTemplateRendererInterface $templates,
        private LanguageRegistry $languages,
    ) {}

    /**
     * Vrati pravo potrebne pro otevreni Pages indexu
     */
    public function indexPermission(): string
    {
        return 'cms.page.view';
    }

    /**
     * Vytvori standardni Pages index s autorizovanou create akci
     */
    public function index(string $locale): ModulePage
    {
        $primaryAction = $this->authorization->hasPermission('cms.page.create')
            ? DataGridPrimaryAction::action(
                translationKey: 'page.actions.create',
                icon: AdminIcon::PlusLg,
                form: new AdminActionFormDefinition(
                    id: 'page-create-draft-form',
                    action: $this->urls->route(
                        name: $this->routes->managementRouteName('pages', 'store'),
                        params: ['module' => 'pages'],
                    ),
                    actionUrl: $this->urls->route(
                        name: $this->routes->managementRouteName('pages', 'ajax.create'),
                        params: ['module' => 'pages'],
                    ),
                    actionKey: 'create',
                    navigateToEditAfterCreate: true,
                ),
            )
            : null;
        $viewModel = new DataGridIndexViewModel(
            moduleCode: 'page',
            title: $this->translator->get('page.list.title'),
            description: $this->translator->get('page.list.description'),
            endpoint: $this->urls->route(
                name: 'admin.api.datagrid.index',
                params: ['module' => 'pages'],
            ),
            definition: $this->grid->dataGridDefinition(),
            primaryAction: $primaryAction,
            loadingText: $this->translator->get('page.list.loading'),
            emptyText: $this->translator->get('page.list.empty'),
            errorText: $this->translator->get('admin.datagrid.errorDescription'),
            gridClass: 'lm-datagrid--hierarchical',
        );

        return new ModulePage(
            view: 'admin::datagrid.index',
            title: $viewModel->title(),
            data: ['dataGrid' => $viewModel, 'locale' => $locale],
        );
    }

    /**
     * Draft se zaklada primarni akci bez samostatne create stranky
     *
     * @param array<string,string> $errors
     * @param array<string,mixed> $input
     * @param array<string,mixed> $query
     */
    public function create(EditorLoaded $editor, array $errors, array $input, string $locale, array $query = []): ModulePage
    {
        throw new EditorEntityNotFoundException('CMS Page does not provide a full-page create editor.');
    }

    /**
     * Vytvori editor vybrane lokalizace stranky
     *
     * @param array<string,string> $errors
     * @param array<string,mixed> $input
     * @param array<string,mixed> $query
     */
    public function editor(EditorLoaded $editor, array $errors, array $input, string $locale, array $query = []): ModulePage
    {
        $locales = $editor->data()['locales'];
        $selected = $this->resolveContentLocale($locales, $query);
        $page = $editor->data()['page'];
        $page['translations'] = array_values(array_filter(
            $page['translations'],
            static fn(array $translation): bool => $translation['locale'] === $selected['code'],
        ));
        $translation = $page['translations'][0] ?? [];
        $canonicalPath = trim((string) ($translation['canonical_path'] ?? ''), '/');
        $context = count($locales) > 1
            ? $this->localeContext($locales, $selected['code'], (int) $page['id'])
            : null;

        return new ModulePage(
            view: 'admin::editor',
            title: $this->translator->get('page.editor.title'),
            data: [
                'adminEditor' => $this->adminEditorDefinitions->create(
                    page: $page,
                    contentLocale: $selected['code'],
                    canPublish: $this->authorization->hasPermission('cms.page.publish'),
                    parentOptions: $this->parentOptions((int) $page['id']),
                    templateOptions: $this->templateOptions((string) ($page['template_key'] ?? '')),
                    context: $context,
                    featuredImageUpload: $this->files->collection(
                        module: 'cms.page',
                        entityId: (int) $page['id'],
                        usage: 'thumbnail',
                        labelKey: 'page.fields.thumbnail',
                        helpKey: 'page.editor.thumbnail.help',
                        alt: (string) ($translation['title'] ?? ''),
                    ),
                    galleryUpload: $this->files->collection(
                        module: 'cms.page',
                        entityId: (int) $page['id'],
                        usage: 'gallery',
                        labelKey: 'admin.file_upload.gallery',
                        helpKey: 'admin.file_upload.gallery_help',
                    ),
                    attachmentUpload: $this->files->collection(
                        module: 'cms.page',
                        entityId: (int) $page['id'],
                        usage: 'attachment',
                        labelKey: 'admin.file_upload.attachments',
                        helpKey: 'admin.file_upload.attachments_help',
                    ),
                ),
                'adminEditorContext' => new AdminEditorRenderContext(
                    values: [
                        'title' => (string) ($translation['title'] ?? ''),
                        'content' => (string) ($translation['content'] ?? ''),
                        'page_title' => (string) ($translation['page_title'] ?? ''),
                        'meta_description' => (string) ($translation['meta_description'] ?? ''),
                        'url_segment' => $canonicalPath === '' ? '' : basename($canonicalPath),
                        'parent_id' => $page['parent_id'] === null ? '' : 'page-' . (int) $page['parent_id'],
                        'template_key' => (string) ($page['template_key'] ?? ''),
                        'publication_state' => (string) ($translation['publication_state'] ?? 0),
                        'published_at' => (string) ($translation['published_at'] ?? ''),
                    ],
                    oldInput: $input,
                    errors: $errors,
                    mode: 'edit',
                    moduleCode: 'cms.page',
                    entityId: (int) $page['id'],
                ),
                'locale' => $locale,
            ],
        );
    }

    /**
     * Vybere povoleny content locale podle explicitniho query contextu
     *
     * @param list<array<string,mixed>> $locales
     * @param array<string,mixed> $query
     * @return array<string,mixed>
     */
    private function resolveContentLocale(array $locales, array $query): array
    {
        $requested = strtolower(trim((string) ($query['locale'] ?? '')));
        if ($requested !== '') {
            foreach ($locales as $locale) {
                if ($locale['code'] === $requested) {
                    return $locale;
                }
            }

            throw new EditorEntityNotFoundException('Page content locale is not available.');
        }

        foreach ($locales as $locale) {
            if ((int) ($locale['is_default'] ?? 0) === 1) {
                return $locale;
            }
        }

        return $locales[0] ?? throw new EditorEntityNotFoundException('Page content locale is not available.');
    }

    /**
     * Vytvori linkove prepinani dostupnych content locales editoru
     *
     * @param list<array<string,mixed>> $locales
     */
    private function localeContext(array $locales, string $selectedLocale, int $pageId): AdminEditorHeaderContextDefinition
    {
        $items = [];
        foreach ($locales as $locale) {
            $code = (string) $locale['code'];
            $enabled = (int) ($locale['enabled'] ?? 1) === 1;
            $hasTranslation = (int) ($locale['has_translation'] ?? 0) === 1;
            $items[] = new AdminEditorHeaderContextItem(
                label: (string) $locale['name'],
                href: $this->urls->route(
                    name: $this->routes->managementRouteName('pages', 'edit'),
                    params: ['module' => 'pages', 'id' => $pageId, 'locale' => $code],
                ),
                active: $code === $selectedLocale,
                secondary: !$enabled
                    ? $this->translator->get('page.editor.locale.disabled')
                    : ($hasTranslation
                        ? $this->translator->get('page.editor.locale.existing_translation')
                        : $this->translator->get('page.editor.locale.new_translation')),
                code: $code,
                translationExists: $hasTranslation,
            );
        }

        return new AdminEditorHeaderContextDefinition(
            label: $this->translator->get('page.editor.locale.label'),
            items: $items,
        );
    }

    /**
     * Vytvori stromove moznosti rodice bez aktualniho podstromu
     *
     * @return array<string,string>
     */
    private function parentOptions(int $pageId): array
    {
        $locale = $this->languages->defaultLocale();
        $rows = $this->pages->activeTree();
        $hierarchy = new PageHierarchy($rows);
        $excluded = $hierarchy->descendantIds($pageId);
        $translations = $this->pages->translationsForLocale($locale);
        $options = ['' => $this->translator->get('page.editor.root')];
        foreach ($hierarchy->preorder() as $id) {
            if (in_array($id, $excluded, true)) {
                continue;
            }
            $title = trim((string) ($translations[$id]['title'] ?? ''));
            $label = $title === ''
                ? $this->translator->get('page.list.untitled_locale', ['locale' => $locale]) . ' #' . $id
                : $title;
            $options['page-' . $id] = str_repeat('— ', $hierarchy->depth($id)) . $label;
        }

        return $options;
    }

    /**
     * Vytvori host template options a zachova nedostupny ulozeny klic
     *
     * @return array<string,string>
     */
    private function templateOptions(string $currentTemplateKey): array
    {
        $options = [];
        foreach ($this->templates->templates() as $template) {
            $options[$template->key()] = $this->translator->get($template->labelKey());
        }
        if ($currentTemplateKey !== '' && !isset($options[$currentTemplateKey])) {
            $options[$currentTemplateKey] = $currentTemplateKey . ' — ' . $this->translator->get('page.editor.template_unavailable');
        }

        return $options;
    }
}
