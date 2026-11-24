<?php

declare(strict_types=1);

namespace Lemonade\Cms\Page\Models;

use Lemonade\Cms\CmsPublicationState;
use Lemonade\Framework\Database\Model;
use Lemonade\Framework\Database\QueryBuilder;

/**
 * Persists the page hierarchy and its localized content
 */
final class PageModel extends Model
{
    protected string $table = 'cms_page';

    protected bool $useTimestamps = true;

    protected bool $useSoftDeletes = true;

    /**
     * @var list<string>
     */
    protected array $allowedFields = [
        'created_by',
        'updated_by',
        'deleted_by',
        'owner_id',
        'parent_id',
        'sort_order',
        'template_key',
    ];

    /**
     * Creates a root draft and its initial translation
     */
    public function createDraft(int $userId, string $locale, string $templateKey, string $now): int
    {
        $maximum = $this->query()
            ->selectRaw('COALESCE(MAX(sort_order), -1) AS maximum_order')
            ->where('parent_id', null)
            ->first();
        $pageId = (int) $this->insert([
            'created_by' => $userId,
            'owner_id' => $userId,
            'parent_id' => null,
            'sort_order' => ((int) ($maximum['maximum_order'] ?? -1)) + 1,
            'template_key' => $templateKey,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->translations()->insert([
            'page_id' => $pageId,
            'locale' => $locale,
            'title' => null,
            'summary' => null,
            'content' => null,
            'page_title' => null,
            'meta_description' => null,
            'publication_state' => CmsPublicationState::New->value,
            'published_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $pageId;
    }

    /**
     * Returns metadata for an active page root
     *
     * @return array<string,mixed>|null
     */
    public function page(int $pageId): ?array
    {
        return $this->pageRowsQuery()
            ->where('id', $pageId)
            ->where('deleted_at', null)
            ->first();
    }

    /**
     * Returns a page root including its soft-delete state
     *
     * @return array<string,mixed>|null
     */
    public function lifecycle(int $pageId): ?array
    {
        return $this->withDeleted()
            ->query()
            ->select(['id', 'parent_id', 'owner_id', 'deleted_at'])
            ->where('id', $pageId)
            ->first();
    }

    /**
     * Locks a page root for a lifecycle mutation
     *
     * @return array<string,mixed>|null
     */
    public function lifecycleForUpdate(int $pageId): ?array
    {
        return $this->withDeleted()
            ->query()
            ->select(['id', 'parent_id', 'owner_id', 'deleted_at'])
            ->where('id', $pageId)
            ->lockForUpdate()
            ->first();
    }

    /**
     * Returns the current owner including soft-deleted pages
     */
    public function ownerId(int $pageId): ?int
    {
        $page = $this->withDeleted()
            ->query()
            ->select(['owner_id'])
            ->where('id', $pageId)
            ->first();

        return $page === null || $page['owner_id'] === null
            ? null
            : (int) $page['owner_id'];
    }

    /**
     * Locks page ownership for an atomic transfer
     *
     * @return array{owner_id:int|null}|null
     */
    public function ownerForUpdate(int $pageId): ?array
    {
        $page = $this->withDeleted()
            ->query()
            ->select(['owner_id'])
            ->where('id', $pageId)
            ->lockForUpdate()
            ->first();

        return $page === null
            ? null
            : ['owner_id' => $page['owner_id'] === null ? null : (int) $page['owner_id']];
    }

    /**
     * Saves a page's position, sibling order and template code
     */
    public function saveSettings(int $pageId, ?int $parentId, int $sortOrder, string $templateKey, string $now): void
    {
        $this->query()
            ->where('id', $pageId)
            ->set([
                'parent_id' => $parentId,
                'sort_order' => $sortOrder,
                'template_key' => $templateKey,
                'updated_at' => $now,
            ])
            ->update();
    }

    /**
     * Normalizes the order of a sibling group
     *
     * @param list<int> $pageIds
     */
    public function saveSiblingOrder(array $pageIds, string $now): void
    {
        foreach ($pageIds as $sortOrder => $pageId) {
            $this->query()
                ->where('id', $pageId)
                ->set(['sort_order' => $sortOrder, 'updated_at' => $now])
                ->update();
        }
    }

    /**
     * Returns one page translation
     *
     * @return array<string,mixed>|null
     */
    public function translation(int $pageId, string $locale): ?array
    {
        return $this->translations()
            ->select([
                'id', 'page_id', 'locale', 'title', 'summary', 'content', 'page_title',
                'meta_description', 'publication_state', 'published_at',
            ])
            ->where('page_id', $pageId)
            ->where('locale', $locale)
            ->first();
    }

    /**
     * Locks a translation within the aggregate transaction
     *
     * @return array<string,mixed>|null
     */
    public function translationForUpdate(int $pageId, string $locale): ?array
    {
        return $this->translations()
            ->select([
                'id', 'page_id', 'locale', 'title', 'summary', 'content', 'page_title',
                'meta_description', 'publication_state', 'published_at',
            ])
            ->where('page_id', $pageId)
            ->where('locale', $locale)
            ->lockForUpdate()
            ->first();
    }

    /**
     * Inserts or updates one page translation
     */
    public function saveTranslation(
        int $pageId,
        string $locale,
        string $title,
        ?string $summary,
        ?string $content,
        ?string $pageTitle,
        ?string $metaDescription,
        string $now,
        ?int $translationId,
    ): void {
        if ($translationId !== null) {
            $this->translations()
                ->where('id', $translationId)
                ->where('page_id', $pageId)
                ->where('locale', $locale)
                ->set([
                    'title' => $title,
                    'summary' => $summary,
                    'content' => $content,
                    'page_title' => $pageTitle,
                    'meta_description' => $metaDescription,
                    'updated_at' => $now,
                ])
                ->update();

            return;
        }

        $this->translations()->insert([
            'page_id' => $pageId,
            'locale' => $locale,
            'title' => $title,
            'summary' => $summary,
            'content' => $content,
            'page_title' => $pageTitle,
            'meta_description' => $metaDescription,
            'publication_state' => CmsPublicationState::Draft->value,
            'published_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Saves a translation's publication state
     */
    public function savePublication(int $pageId, string $locale, CmsPublicationState $state, ?string $publishedAt, string $now): void
    {
        $this->translations()
            ->where('page_id', $pageId)
            ->where('locale', $locale)
            ->set([
                'publication_state' => $state->value,
                'published_at' => $publishedAt,
                'updated_at' => $now,
            ])
            ->update();
    }

    /**
     * Soft-deletes one page
     */
    public function softDelete(int $pageId, int $userId, string $now): void
    {
        $this->query()->where('id', $pageId)->set([
            'deleted_at' => $now,
            'deleted_by' => $userId,
            'updated_by' => $userId,
            'updated_at' => $now,
        ])->update();
    }

    /**
     * Restores a page and all translations as drafts
     */
    public function restoreDraft(int $pageId, int $userId, string $now): void
    {
        $this->withDeleted()->query()
            ->where('id', $pageId)
            ->set([
                'deleted_at' => null,
                'deleted_by' => null,
                'updated_by' => $userId,
                'updated_at' => $now,
            ])
            ->update();
        $this->translations()
            ->where('page_id', $pageId)
            ->set([
                'publication_state' => CmsPublicationState::Draft->value,
                'published_at' => null,
                'updated_at' => $now,
            ])
            ->update();
    }

    /**
     * Updates the last editor after an aggregate change
     */
    public function touchUpdatedBy(int $pageId, int $userId, string $now): void
    {
        $this->withDeleted()->query()
            ->where('id', $pageId)
            ->set(['updated_by' => $userId, 'updated_at' => $now])
            ->update();
    }

    /**
     * Transfers ownership without changing the original creator
     */
    public function transferOwner(int $pageId, int $ownerId, int $userId, string $now): void
    {
        $this->withDeleted()->query()
            ->where('id', $pageId)
            ->set([
                'owner_id' => $ownerId,
                'updated_by' => $userId,
                'updated_at' => $now,
            ])
            ->update();
    }

    /**
     * Returns active roots for hierarchy validation
     *
     * @return list<array{id:int,parent_id:int|null,sort_order:int,template_key:string,updated_at:string,deleted_at:mixed}>
     */
    public function activeTree(): array
    {
        /**
         * @var list<array{id:int,parent_id:int|null,sort_order:int,template_key:string,updated_at:string,deleted_at:mixed}> $rows
         */
        $rows = $this->query()
            ->select(['id', 'owner_id', 'parent_id', 'sort_order', 'template_key', 'updated_at', 'deleted_at'])
            ->orderBy('sort_order', 'ASC')
            ->orderBy('id', 'ASC')
            ->getArray();

        return $rows;
    }

    /**
     * Locks the active tree for an atomic hierarchy or lifecycle change
     *
     * @return list<array{id:int,parent_id:int|null,sort_order:int,template_key:string,updated_at:string,deleted_at:mixed}>
     */
    public function activeTreeForUpdate(): array
    {
        /**
         * @var list<array{id:int,parent_id:int|null,sort_order:int,template_key:string,updated_at:string,deleted_at:mixed}> $rows
         */
        $rows = $this->query()
            ->select(['id', 'owner_id', 'parent_id', 'sort_order', 'template_key', 'updated_at', 'deleted_at'])
            ->orderBy('sort_order', 'ASC')
            ->orderBy('id', 'ASC')
            ->lockForUpdate()
            ->getArray();

        return $rows;
    }

    /**
     * Returns page roots including deleted pages for the DataGrid
     *
     * @return list<array<string,mixed>>
     */
    public function treeWithDeleted(?int $ownerId = null): array
    {
        $query = $this->pageRowsQuery();
        if ($ownerId === null) {
            return $query->getArray();
        }

        $rows = $query->where('owner_id', $ownerId)->getArray();
        $rowsById = [];
        $pendingParentIds = [];
        foreach ($rows as $row) {
            $pageId = (int) $row['id'];
            $rowsById[$pageId] = $row;
            if ($row['parent_id'] !== null) {
                $pendingParentIds[(int) $row['parent_id']] = true;
            }
        }
        while ($pendingParentIds !== []) {
            $parentIds = array_values(array_filter(
                array_map('intval', array_keys($pendingParentIds)),
                static fn(int $pageId): bool => !isset($rowsById[$pageId]),
            ));
            if ($parentIds === []) {
                break;
            }
            $pendingParentIds = [];
            foreach ($this->pageRowsQuery()->whereIn('id', $parentIds)->getArray() as $row) {
                $pageId = (int) $row['id'];
                $rowsById[$pageId] = $row;
                if ($row['parent_id'] !== null && !isset($rowsById[(int) $row['parent_id']])) {
                    $pendingParentIds[(int) $row['parent_id']] = true;
                }
            }
        }

        return array_values($rowsById);
    }

    /**
     * Builds the page root query with audit identities including deleted pages
     */
    private function pageRowsQuery(): QueryBuilder
    {
        return $this->withDeleted()->query()
            ->select([
                'id', 'owner_id', 'created_by', 'updated_by', 'deleted_by',
                'parent_id', 'sort_order', 'template_key', 'created_at', 'updated_at', 'deleted_at',
            ])
            ->selectRaw("COALESCE(NULLIF(TRIM(CONCAT_WS(' ', (SELECT first_name FROM system_user WHERE id = cms_page.created_by), (SELECT last_name FROM system_user WHERE id = cms_page.created_by))), ''), (SELECT email FROM system_user WHERE id = cms_page.created_by), '—') AS created_by_name")
            ->selectRaw("COALESCE(NULLIF(TRIM(CONCAT_WS(' ', (SELECT first_name FROM system_user WHERE id = cms_page.updated_by), (SELECT last_name FROM system_user WHERE id = cms_page.updated_by))), ''), (SELECT email FROM system_user WHERE id = cms_page.updated_by), '—') AS updated_by_name")
            ->selectRaw("COALESCE(NULLIF(TRIM(CONCAT_WS(' ', (SELECT first_name FROM system_user WHERE id = cms_page.deleted_by), (SELECT last_name FROM system_user WHERE id = cms_page.deleted_by))), ''), (SELECT email FROM system_user WHERE id = cms_page.deleted_by), '—') AS deleted_by_name")
            ->selectRaw("COALESCE(NULLIF(TRIM(CONCAT_WS(' ', (SELECT first_name FROM system_user WHERE id = cms_page.owner_id), (SELECT last_name FROM system_user WHERE id = cms_page.owner_id))), ''), (SELECT email FROM system_user WHERE id = cms_page.owner_id), '—') AS owner_name");
    }

    /**
     * Returns translations and canonical paths for a DataGrid locale
     *
     * @param list<int>|null $pageIds
     * @return array<int,array<string,mixed>>
     */
    public function translationsForLocale(string $locale, ?array $pageIds = null): array
    {
        if ($pageIds === []) {
            return [];
        }
        $result = [];
        $query = $this->translations()
            ->from('cms_page_translation translation')
            ->select([
                'translation.page_id', 'translation.title', 'translation.publication_state',
                'translation.published_at',
            ])
            ->selectRaw('route.path AS canonical_path')
            ->joinRaw(
                table: 'cms_route route',
                condition: 'route.module_code = ? AND route.entity_id = translation.page_id AND route.locale = translation.locale',
                bindings: ['cms.page'],
                type: 'LEFT',
            )
            ->where('translation.locale', $locale);
        if ($pageIds !== null) {
            $query->whereIn('translation.page_id', array_values($pageIds));
        }
        foreach ($query->getArray() as $row) {
            $result[(int) $row['page_id']] = $row;
        }

        return $result;
    }

    /**
     * Returns all canonical routes in a page subtree
     *
     * @param list<int> $pageIds
     * @return list<array{entity_id:int,locale:string,path:string}>
     */
    public function routesForPages(array $pageIds): array
    {
        if ($pageIds === []) {
            return [];
        }
        /**
         * @var list<array{entity_id:int,locale:string,path:string}> $rows
         */
        $rows = QueryBuilder::make($this->db)
            ->table('cms_route')
            ->select(['entity_id', 'locale', 'path'])
            ->where('module_code', 'cms.page')
            ->whereIn('entity_id', $pageIds)
            ->getArray();

        return $rows;
    }

    /**
     * Returns the canonical path of one parent in a locale
     */
    public function routePath(int $pageId, string $locale): ?string
    {
        $row = QueryBuilder::make($this->db)
            ->table('cms_route')
            ->select(['path'])
            ->where('module_code', 'cms.page')
            ->where('entity_id', $pageId)
            ->where('locale', $locale)
            ->first();

        return $row === null ? null : (string) $row['path'];
    }

    /**
     * Loads a page aggregate with all translations for the editor
     *
     * @return array<string,mixed>|null
     */
    public function findForEditor(int $pageId): ?array
    {
        $page = $this->page($pageId);
        if ($page === null) {
            return null;
        }
        $translations = $this->translations()
            ->from('cms_page_translation translation')
            ->select([
                'translation.locale', 'translation.title', 'translation.summary', 'translation.content',
                'translation.page_title', 'translation.meta_description',
                'translation.publication_state', 'translation.published_at',
            ])
            ->selectRaw('route.path AS canonical_path')
            ->joinRaw(
                table: 'cms_route route',
                condition: 'route.module_code = ? AND route.entity_id = translation.page_id AND route.locale = translation.locale',
                bindings: ['cms.page'],
                type: 'LEFT',
            )
            ->where('translation.page_id', $pageId)
            ->orderBy('translation.locale', 'ASC')
            ->getArray();
        $page['translations'] = $translations;

        return $page;
    }

    /**
     * Returns editor locales including stored inactive translations
     *
     * @return list<array{code:string,name:string,enabled:int,is_default:int,has_translation:int}>
     */
    public function editorLocales(int $pageId): array
    {
        /**
         * @var list<array{code:string,name:string,enabled:int,is_default:int,has_translation:int}> $rows
         */
        $rows = QueryBuilder::make($this->db)
            ->table('system_language language')
            ->select(['language.code', 'language.name', 'language.enabled', 'language.is_default'])
            ->selectRaw('CASE WHEN translation.id IS NULL THEN 0 ELSE 1 END AS has_translation')
            ->joinRaw(
                table: 'cms_page_translation translation',
                condition: 'translation.locale = language.code AND translation.page_id = ?',
                bindings: [$pageId],
                type: 'LEFT',
            )
            ->whereRaw('language.enabled = 1 OR translation.id IS NOT NULL')
            ->orderBy('language.sort_order', 'ASC')
            ->orderBy('language.code', 'ASC')
            ->getArray();

        return $rows;
    }

    /**
     * Returns enabled locales and inactive locales with page translations
     *
     * @return list<array{code:string,name:string}>
     */
    public function dataGridLocales(): array
    {
        /** @var list<array{code:string,name:string}> $rows */
        $rows = QueryBuilder::make($this->db)
            ->table('system_language language')
            ->select(['language.code', 'language.name'])
            ->joinOn(
                'cms_page_translation translation',
                'translation.locale',
                '=',
                'language.code',
                'LEFT',
            )
            ->whereRaw('language.enabled = 1 OR translation.id IS NOT NULL')
            ->groupBy('language.code')
            ->groupBy('language.name')
            ->groupBy('language.sort_order')
            ->orderBy('language.sort_order', 'ASC')
            ->orderBy('language.code', 'ASC')
            ->getArray();

        return $rows;
    }

    /**
     * Returns published translations for shared parent and template checks
     *
     * @return list<array<string,mixed>>
     */
    public function publishedTranslations(int $pageId): array
    {
        return $this->translations()
            ->select(['locale', 'published_at'])
            ->where('page_id', $pageId)
            ->where('publication_state', CmsPublicationState::Published->value)
            ->getArray();
    }

    /**
     * Reports whether a page has published descendants in a locale
     *
     * @param list<int> $descendantIds
     */
    public function hasPublishedDescendants(array $descendantIds, string $locale): bool
    {
        if ($descendantIds === []) {
            return false;
        }

        return $this->translations()
            ->whereIn('page_id', $descendantIds)
            ->where('locale', $locale)
            ->where('publication_state', CmsPublicationState::Published->value)
            ->exists();
    }

    /**
     * Returns the query builder for page translations
     */
    private function translations(): QueryBuilder
    {
        return QueryBuilder::make($this->db)->table('cms_page_translation');
    }
}
