<?php

declare(strict_types=1);

namespace Lemonade\Cms\Page\DataGrid;

use Lemonade\Admin\Action\Presentation\ModuleActionPresentationFactory;
use Lemonade\Admin\Authorization\AuthorizationService;
use Lemonade\Admin\DataGrid\Action\DataGridRowActionDefinition;
use Lemonade\Admin\DataGrid\Cell\ScalarCell;
use Lemonade\Admin\DataGrid\Cell\StatusCell;
use Lemonade\Admin\DataGrid\Cell\StatusVariant;
use Lemonade\Admin\DataGrid\Cell\ThumbnailCell;
use Lemonade\Admin\DataGrid\Contract\DataGridProviderInterface;
use Lemonade\Admin\DataGrid\DataGridColumnDefinition;
use Lemonade\Admin\DataGrid\DataGridDefinition;
use Lemonade\Admin\DataGrid\DataGridFilterDefinition;
use Lemonade\Admin\DataGrid\DataGridResult;
use Lemonade\Admin\DataGrid\DataGridRowDefinition;
use Lemonade\Admin\DataGrid\DataGridTreeRowDefinition;
use Lemonade\Admin\DataGrid\Query\DataGridQuery;
use Lemonade\Admin\Icon\AdminIcon;
use Lemonade\Admin\Localization\LanguageRegistry;
use Lemonade\Admin\Module\AdminModuleRouteResolver;
use Lemonade\Admin\Presentation\AdminThumbnailComponent;
use Lemonade\Admin\Presentation\Models\AdminFileModel;
use Lemonade\Admin\Select\SelectOptionDefinition;
use Lemonade\Admin\Select\StaticSelectOptionSource;
use Lemonade\Cms\CmsPublicationState;
use Lemonade\Cms\Page\Hierarchy\PageHierarchy;
use Lemonade\Cms\Page\Models\PageModel;
use Lemonade\Framework\Localization\TranslatorInterface;
use Lemonade\Framework\Routing\UrlGenerator;

/**
 * Promita CMS strom do shared hierarchickeho DataGridu
 */
final readonly class PageDataGrid implements DataGridProviderInterface
{
    /**
     * Nastavuje stromovou persistenci, content locales a Admin presentation zavislosti
     */
    public function __construct(
        private PageModel $pages,
        private LanguageRegistry $languages,
        private AuthorizationService $authorization,
        private TranslatorInterface $translator,
        private ModuleActionPresentationFactory $actionPresentation,
        private AdminModuleRouteResolver $routes,
        private UrlGenerator $urls,
        private AdminFileModel $files,
        private AdminThumbnailComponent $thumbnails,
    ) {}

    /**
     * Vrati pravo potrebne pro cteni Pages gridu
     */
    public function permission(): string
    {
        return 'cms.page.view';
    }

    /**
     * Deklaruje hierarchicke sloupce, views a filtry bez strankovani
     */
    public function dataGridDefinition(): DataGridDefinition
    {
        return new DataGridDefinition(
            id: 'cms-page',
            columns: [
                new DataGridColumnDefinition('title', 'page.fields.title', 'title'),
                new DataGridColumnDefinition('state', 'page.fields.publication_state', 'state'),
                new DataGridColumnDefinition('template', 'page.fields.template'),
                new DataGridColumnDefinition('sortOrder', 'page.fields.sort_order', 'sortOrder'),
                new DataGridColumnDefinition('updatedAt', 'page.fields.updated_at', 'updatedAt'),
                new DataGridColumnDefinition('actions', 'admin.common.actions'),
            ],
            searchEnabled: true,
            filters: [
                new DataGridFilterDefinition('status', new StaticSelectOptionSource([
                    new SelectOptionDefinition('active', $this->translator->get('page.list.active')),
                    new SelectOptionDefinition('deleted', $this->translator->get('page.list.deleted')),
                ])),
                new DataGridFilterDefinition('state', new StaticSelectOptionSource([
                    new SelectOptionDefinition('new', $this->translator->get('admin.status.new')),
                    new SelectOptionDefinition('draft', $this->translator->get('admin.status.draft')),
                    new SelectOptionDefinition('published', $this->translator->get('admin.status.published')),
                ])),
                new DataGridFilterDefinition('locale', new StaticSelectOptionSource(array_map(
                    fn(array $language): SelectOptionDefinition => new SelectOptionDefinition($language['code'], $language['name']),
                    $this->pages->dataGridLocales(),
                ))),
                new DataGridFilterDefinition('parent', new StaticSelectOptionSource($this->parentFilterOptions())),
            ],
            defaultSortKey: 'sortOrder',
            defaultSortDirection: 'asc',
            defaultPageSize: 5000,
            maximumPageSize: 5000,
            defaultView: 'active',
            showAllView: false,
            treeColumnKey: 'title',
            paginationEnabled: false,
        );
    }

    /**
     * Vrati jeden strukturalni preorder vcetne ancestors filtrovanych vysledku
     */
    public function execute(DataGridQuery $query): DataGridResult
    {
        $locale = $query->filter('locale') ?? $this->languages->defaultLocale();
        $translations = $this->pages->translationsForLocale($locale);
        $allRows = $this->pages->treeWithDeleted();
        $rows = $this->filteredRows($allRows, $translations, $query);
        usort($rows, fn(array $left, array $right): int => $this->compare($left, $right, $translations, $query));
        $hierarchyRows = array_map(
            static fn(array $row): array => [
                'id' => (int) $row['id'],
                'parent_id' => $row['parent_id'] === null ? null : (int) $row['parent_id'],
                'sort_order' => (int) $row['sort_order'],
            ],
            $rows,
        );
        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['id']] = $row;
        }
        $featuredImages = $this->files->findForEntities(
            module: 'cms.page',
            entityIds: array_map(static fn(array $page): int => (int) $page['id'], $rows),
            usage: 'thumbnail',
        );
        $siblingMoves = $this->siblingMoves($allRows);
        $orderedRows = [];
        foreach ((new PageHierarchy($hierarchyRows))->preorder() as $id) {
            if (isset($byId[$id])) {
                $orderedRows[] = $this->row(
                    $byId[$id],
                    $translations[$id] ?? null,
                    $locale,
                    $siblingMoves[$id] ?? ['up' => false, 'down' => false],
                    $featuredImages[$id] ?? null,
                );
            }
        }
        $total = count($orderedRows);

        return new DataGridResult($orderedRows, 1, $query->pageSize(), $total);
    }

    /**
     * Vybere odpovidajici radky a doplni jejich predky pro stromovy kontext
     *
     * @param list<array<string,mixed>> $rows
     * @param array<int,array<string,mixed>> $translations
     * @return list<array<string,mixed>>
     */
    private function filteredRows(array $rows, array $translations, DataGridQuery $query): array
    {
        $matchingIds = [];
        foreach ($rows as $row) {
            $deleted = $row['deleted_at'] !== null;
            if (($query->filter('status') === 'deleted') !== $deleted) {
                continue;
            }
            $translation = $translations[(int) $row['id']] ?? null;
            if ($translation === null) {
                continue;
            }
            $state = (int) $translation['publication_state'];
            $requiredState = match ($query->filter('state')) {
                'new' => CmsPublicationState::New,
                'draft' => CmsPublicationState::Draft,
                'published' => CmsPublicationState::Published,
                default => null,
            };
            if ($requiredState !== null && $state !== $requiredState->value) {
                continue;
            }
            $parentFilter = $query->filter('parent');
            $parentId = $row['parent_id'] === null ? null : (int) $row['parent_id'];
            if ($parentFilter === 'root' && $parentId !== null) {
                continue;
            }
            if (is_string($parentFilter) && str_starts_with($parentFilter, 'page-')) {
                if ($parentId !== (int) substr($parentFilter, 5)) {
                    continue;
                }
            }
            $search = mb_strtolower(trim($query->search()));
            if ($search !== '' && !str_contains(mb_strtolower(
                (string) ($translation['title'] ?? '') . ' ' . (string) ($translation['canonical_path'] ?? ''),
            ), $search)) {
                continue;
            }
            $matchingIds[(int) $row['id']] = true;
        }
        $hierarchyRows = array_map(
            static fn(array $row): array => [
                'id' => (int) $row['id'],
                'parent_id' => $row['parent_id'] === null ? null : (int) $row['parent_id'],
                'sort_order' => (int) $row['sort_order'],
            ],
            $rows,
        );
        $hierarchy = new PageHierarchy($hierarchyRows);
        foreach (array_keys($matchingIds) as $pageId) {
            foreach ($hierarchy->ancestorIds($pageId) as $ancestorId) {
                $matchingIds[$ancestorId] = true;
            }
        }

        return array_values(array_filter(
            $rows,
            static fn(array $row): bool => isset($matchingIds[(int) $row['id']]),
        ));
    }

    /**
     * Porovna dva sourozence podle povoleneho sort klice
     *
     * @param array<string,mixed> $left
     * @param array<string,mixed> $right
     * @param array<int,array<string,mixed>> $translations
     */
    private function compare(array $left, array $right, array $translations, DataGridQuery $query): int
    {
        $leftTranslation = $translations[(int) $left['id']] ?? [];
        $rightTranslation = $translations[(int) $right['id']] ?? [];
        $comparison = match ($query->sortKey()) {
            'title' => strnatcasecmp((string) ($leftTranslation['title'] ?? ''), (string) ($rightTranslation['title'] ?? '')),
            'state' => (int) ($leftTranslation['publication_state'] ?? 0) <=> (int) ($rightTranslation['publication_state'] ?? 0),
            'updatedAt' => strcmp((string) $left['updated_at'], (string) $right['updated_at']),
            default => (int) $left['sort_order'] <=> (int) $right['sort_order'],
        };
        if ($comparison === 0) {
            $comparison = (int) $left['id'] <=> (int) $right['id'];
        }

        return $query->sortDirection() === 'desc' ? -$comparison : $comparison;
    }

    /**
     * Vytvori typed row vcetne tree parent metadata
     *
     * @param array<string,mixed> $page
     * @param array<string,mixed>|null $translation
     * @param array{up:bool,down:bool} $siblingMoves
     * @param array{
     *     id:int,
     *     module_code:string,
     *     entity_id:int,
     *     usage:string,
     *     kind:string,
     *     original_filename:string,
     *     display_name:string|null,
     *     caption:string|null,
     *     extension:string,
     *     mime_type:string,
     *     file_size:int,
     *     width:int|null,
     *     height:int|null,
     * }|null $featuredImage
     */
    private function row(
        array $page,
        ?array $translation,
        string $locale,
        array $siblingMoves,
        ?array $featuredImage,
    ): DataGridRowDefinition {
        $pageId = (int) $page['id'];
        $deleted = $page['deleted_at'] !== null;
        $editable = !$deleted && $this->authorization->hasPermission('cms.page.edit');
        $hasTranslation = $translation !== null;
        $title = trim((string) ($translation['title'] ?? ''));
        $title = $hasTranslation
            ? ($title === '' ? $this->translator->get('admin.datagrid.untitled') : $title)
            : $this->translator->get('page.list.structural_context', ['locale' => $locale]);
        $state = $hasTranslation
            ? CmsPublicationState::tryFrom((int) $translation['publication_state']) ?? CmsPublicationState::New
            : null;

        return new DataGridRowDefinition(
            id: $pageId,
            cells: [
                'title' => new ThumbnailCell(
                    value: $title,
                    thumbnail: $featuredImage === null
                        ? $this->thumbnails->fallback(
                            fallback: null,
                            size: 'compact',
                            shape: 'rounded',
                        )
                        : $this->thumbnails->image(
                            module: 'cms.page',
                            imageId: (string) $featuredImage['id'],
                            alt: $title,
                            fallback: null,
                            size: 'compact',
                            shape: 'rounded',
                            presentation: 'datagrid',
                        ),
                    secondary: isset($translation['canonical_path']) && trim((string) $translation['canonical_path']) !== ''
                        ? '/' . ltrim((string) $translation['canonical_path'], '/')
                        : '',
                    url: $editable ? $this->editUrl($pageId, $locale) : null,
                ),
                'state' => $state === null
                    ? new StatusCell(
                        $this->translator->get('page.list.structural_context_state'),
                        StatusVariant::Muted,
                    )
                    : new StatusCell(
                        $this->translator->get('admin.status.' . match ($state) {
                            CmsPublicationState::New => 'new',
                            CmsPublicationState::Draft => 'draft',
                            CmsPublicationState::Published => 'published',
                        }),
                        $state === CmsPublicationState::Published ? StatusVariant::Success : StatusVariant::Muted,
                    ),
                'template' => new ScalarCell((string) $page['template_key']),
                'sortOrder' => new ScalarCell((int) $page['sort_order']),
                'updatedAt' => new ScalarCell((string) $page['updated_at']),
            ],
            actions: $this->rowActions(
                $pageId,
                $deleted,
                $locale,
                $siblingMoves['up'],
                $siblingMoves['down'],
            ),
            tree: new DataGridTreeRowDefinition($page['parent_id'] === null ? null : (int) $page['parent_id']),
        );
    }

    /**
     * Vytvori povolene editorove, poradi a lifecycle akce radku
     *
     * @return list<DataGridRowActionDefinition>
     */
    private function rowActions(
        int $pageId,
        bool $deleted,
        string $locale,
        bool $canMoveUp,
        bool $canMoveDown,
    ): array {
        $actions = [];
        if (!$deleted && $this->authorization->hasPermission('cms.page.edit')) {
            $actions[] = (new DataGridRowActionDefinition(
                key: 'edit',
                label: $this->translator->get('admin.common.edit'),
                url: $this->editUrl($pageId, $locale),
                method: 'GET',
            ))->withIcon(AdminIcon::PencilSquare);
            foreach (['move-up' => $canMoveUp, 'move-down' => $canMoveDown] as $actionKey => $visible) {
                if (!$visible) {
                    continue;
                }
                $move = $this->actionPresentation->rowAction('cms.page', $actionKey, $pageId);
                if ($move !== null) {
                    $actions[] = $move;
                }
            }
        }
        $lifecycle = $this->actionPresentation->rowAction(
            'cms.page',
            $deleted ? 'restore' : 'delete',
            $pageId,
        );
        if ($lifecycle !== null) {
            $actions[] = $lifecycle;
        }

        return $actions;
    }

    /**
     * Vytvori canonical edit URL se zachovanym content locale
     */
    private function editUrl(int $pageId, string $locale): string
    {
        return $this->urls->route(
            $this->routes->managementRouteName('pages', 'edit'),
            ['module' => 'pages', 'id' => $pageId],
        ) . '?locale=' . rawurlencode($locale);
    }

    /**
     * Vytvori stromove moznosti filtru rodice ve vychozim content locale
     *
     * @return list<SelectOptionDefinition>
     */
    private function parentFilterOptions(): array
    {
        $locale = $this->languages->defaultLocale();
        $rows = $this->pages->activeTree();
        $translations = $this->pages->translationsForLocale($locale);
        $hierarchy = new PageHierarchy($rows);
        $options = [new SelectOptionDefinition('root', $this->translator->get('page.editor.root'))];
        foreach ($hierarchy->preorder() as $pageId) {
            $title = trim((string) ($translations[$pageId]['title'] ?? ''));
            $label = $title === ''
                ? $this->translator->get('page.list.untitled_locale', ['locale' => $locale]) . ' #' . $pageId
                : $title;
            $options[] = new SelectOptionDefinition(
                'page-' . $pageId,
                str_repeat('— ', $hierarchy->depth($pageId)) . $label,
            );
        }

        return $options;
    }

    /**
     * Urci dostupnost posunu podle cele aktivni skupiny sourozencu
     *
     * @param list<array<string,mixed>> $rows
     * @return array<int,array{up:bool,down:bool}>
     */
    private function siblingMoves(array $rows): array
    {
        $siblings = [];
        foreach ($rows as $row) {
            if ($row['deleted_at'] !== null) {
                continue;
            }
            $parentKey = $row['parent_id'] === null ? 'root' : 'page-' . (int) $row['parent_id'];
            $siblings[$parentKey][] = $row;
        }
        $moves = [];
        foreach ($siblings as $group) {
            usort($group, static fn(array $left, array $right): int => [(int) $left['sort_order'], (int) $left['id']] <=> [(int) $right['sort_order'], (int) $right['id']]);
            $last = count($group) - 1;
            foreach ($group as $position => $row) {
                $moves[(int) $row['id']] = ['up' => $position > 0, 'down' => $position < $last];
            }
        }

        return $moves;
    }
}
