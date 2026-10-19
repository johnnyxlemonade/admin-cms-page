<?php

declare(strict_types=1);

namespace Lemonade\Cms\Page\Models;

use Lemonade\Cms\CmsPublicationState;
use Lemonade\Cms\Page\PublicPage;
use Lemonade\Framework\Database\Model;
use Lemonade\Framework\Database\QueryBuilder;

/**
 * Zprostredkuje persistenci stromu stranek a jejich lokalizaci
 */
final class PageModel extends Model
{
    protected string $table = 'cms_page';

    protected bool $useTimestamps = true;

    protected bool $useSoftDeletes = true;

    /**
     * @var list<string>
     */
    protected array $allowedFields = ['parent_id', 'sort_order', 'template_key'];

    /**
     * Vytvori minimalni root draft a jeho vychozi lokalizaci
     */
    public function createDraft(string $locale, string $templateKey, string $now): int
    {
        $maximum = $this->query()
            ->selectRaw('COALESCE(MAX(sort_order), -1) AS maximum_order')
            ->where('parent_id', null)
            ->first();
        $pageId = (int) $this->insert([
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
     * Vrati root metadata aktivni stranky
     *
     * @return array<string,mixed>|null
     */
    public function page(int $pageId): ?array
    {
        return $this->query()
            ->select(['id', 'parent_id', 'sort_order', 'template_key', 'deleted_at'])
            ->where('id', $pageId)
            ->first();
    }

    /**
     * Vrati root vcetne soft-delete stavu
     *
     * @return array<string,mixed>|null
     */
    public function lifecycle(int $pageId): ?array
    {
        return $this->withDeleted()
            ->query()
            ->select(['id', 'parent_id', 'deleted_at'])
            ->where('id', $pageId)
            ->first();
    }

    /**
     * Zamkne root vcetne soft-delete stavu pro lifecycle mutaci
     *
     * @return array<string,mixed>|null
     */
    public function lifecycleForUpdate(int $pageId): ?array
    {
        return $this->withDeleted()
            ->query()
            ->select(['id', 'parent_id', 'deleted_at'])
            ->where('id', $pageId)
            ->lockForUpdate()
            ->first();
    }

    /**
     * Ulozi globalni umisteni, poradi a host sablonu stranky
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
     * Normalizuje poradi zadane skupiny sourozencu
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
     * Vrati jednu lokalizovanou mutaci
     *
     * @return array<string,mixed>|null
     */
    public function translation(int $pageId, string $locale): ?array
    {
        return $this->translations()
            ->select([
                'id', 'page_id', 'locale', 'title', 'content', 'page_title',
                'meta_description', 'publication_state', 'published_at',
            ])
            ->where('page_id', $pageId)
            ->where('locale', $locale)
            ->first();
    }

    /**
     * Zamkne existujici lokalizovanou mutaci pro zapis v aggregate transakci
     *
     * @return array<string,mixed>|null
     */
    public function translationForUpdate(int $pageId, string $locale): ?array
    {
        return $this->translations()
            ->select([
                'id', 'page_id', 'locale', 'title', 'content', 'page_title',
                'meta_description', 'publication_state', 'published_at',
            ])
            ->where('page_id', $pageId)
            ->where('locale', $locale)
            ->lockForUpdate()
            ->first();
    }

    /**
     * Vlozi nebo upravi jednu lokalizovanou mutaci
     */
    public function saveTranslation(
        int $pageId,
        string $locale,
        string $title,
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
     * Ulozi publication lifecycle lokalizace
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
     * Soft-deleteuje jednu stranku
     */
    public function softDelete(int $pageId, string $now): void
    {
        $this->query()->where('id', $pageId)->set(['deleted_at' => $now, 'updated_at' => $now])->update();
    }

    /**
     * Obnovi stranku a vsechny lokalizace jako draft
     */
    public function restoreDraft(int $pageId, string $now): void
    {
        $this->withDeleted()->query()
            ->where('id', $pageId)
            ->set(['deleted_at' => null, 'updated_at' => $now])
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
     * Vrati vsechny aktivni rooty pro stromove invarianty
     *
     * @return list<array{id:int,parent_id:int|null,sort_order:int,template_key:string,updated_at:string,deleted_at:mixed}>
     */
    public function activeTree(): array
    {
        /**
         * @var list<array{id:int,parent_id:int|null,sort_order:int,template_key:string,updated_at:string,deleted_at:mixed}> $rows
         */
        $rows = $this->query()
            ->select(['id', 'parent_id', 'sort_order', 'template_key', 'updated_at', 'deleted_at'])
            ->orderBy('sort_order', 'ASC')
            ->orderBy('id', 'ASC')
            ->getArray();

        return $rows;
    }

    /**
     * Zamkne aktivni strom pro atomickou zmenu hierarchie nebo lifecycle
     *
     * @return list<array{id:int,parent_id:int|null,sort_order:int,template_key:string,updated_at:string,deleted_at:mixed}>
     */
    public function activeTreeForUpdate(): array
    {
        /**
         * @var list<array{id:int,parent_id:int|null,sort_order:int,template_key:string,updated_at:string,deleted_at:mixed}> $rows
         */
        $rows = $this->query()
            ->select(['id', 'parent_id', 'sort_order', 'template_key', 'updated_at', 'deleted_at'])
            ->orderBy('sort_order', 'ASC')
            ->orderBy('id', 'ASC')
            ->lockForUpdate()
            ->getArray();

        return $rows;
    }

    /**
     * Vrati vsechny rooty vcetne smazanych pro DataGrid
     *
     * @return list<array<string,mixed>>
     */
    public function treeWithDeleted(): array
    {
        return $this->withDeleted()->query()
            ->select(['id', 'parent_id', 'sort_order', 'template_key', 'updated_at', 'deleted_at'])
            ->getArray();
    }

    /**
     * Vrati preklady a canonical cesty pro DataGrid ve zvolenem locale
     *
     * @return array<int,array<string,mixed>>
     */
    public function translationsForLocale(string $locale): array
    {
        $result = [];
        foreach ($this->translations()
            ->from('cms_page_translation translation')
            ->select([
                'translation.page_id', 'translation.title', 'translation.publication_state',
                'translation.published_at',
            ])
            ->selectRaw('route.path AS canonical_path')
            ->join(
                'cms_route route',
                "route.module_code = 'cms.page' AND route.entity_id = translation.page_id AND route.locale = translation.locale",
                'LEFT',
            )
            ->where('translation.locale', $locale)
            ->getArray() as $row) {
            $result[(int) $row['page_id']] = $row;
        }

        return $result;
    }

    /**
     * Vrati vsechny canonical routy podstromu
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
     * Vrati canonical cestu jednoho rodice v locale
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
     * Nacte aggregate se vsemi mutacemi pro full-page editor
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
                'translation.locale', 'translation.title', 'translation.content',
                'translation.page_title', 'translation.meta_description',
                'translation.publication_state', 'translation.published_at',
            ])
            ->selectRaw('route.path AS canonical_path')
            ->join(
                'cms_route route',
                "route.module_code = 'cms.page' AND route.entity_id = translation.page_id AND route.locale = translation.locale",
                'LEFT',
            )
            ->where('translation.page_id', $pageId)
            ->orderBy('translation.locale', 'ASC')
            ->getArray();
        $page['translations'] = $translations;

        return $page;
    }

    /**
     * Vrati locale dostupne pro editor vcetne ulozenych neaktivnich mutaci
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
            ->join(
                'cms_page_translation translation',
                'translation.locale = language.code AND translation.page_id = ' . $pageId,
                'LEFT',
            )
            ->whereRaw('language.enabled = 1 OR translation.id IS NOT NULL')
            ->orderBy('language.sort_order', 'ASC')
            ->orderBy('language.code', 'ASC')
            ->getArray();

        return $rows;
    }

    /**
     * Rozhodne, zda ma stranka publikovane potomky v locale
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
     * Nacte publikovanou lokalizaci a overi publikovane predky
     */
    public function publishedPage(int $pageId, string $locale, string $now): ?PublicPage
    {
        $row = $this->query()
            ->from('cms_page page')
            ->select([
                'page.id', 'page.parent_id', 'page.template_key',
                'translation.locale', 'translation.title', 'translation.content',
                'translation.page_title', 'translation.meta_description', 'translation.published_at',
            ])
            ->join('cms_page_translation translation', 'translation.page_id = page.id')
            ->where('page.id', $pageId)
            ->where('translation.locale', $locale)
            ->where('translation.publication_state', CmsPublicationState::Published->value)
            ->where('translation.published_at <=', $now)
            ->first();
        if ($row === null || trim((string) $row['title']) === '') {
            return null;
        }
        $parentId = $row['parent_id'] === null ? null : (int) $row['parent_id'];
        if (!$this->ancestorsPublished($parentId, $locale, $now)) {
            return null;
        }

        return new PublicPage(
            id: (int) $row['id'],
            parentId: $parentId,
            templateKey: (string) $row['template_key'],
            locale: (string) $row['locale'],
            title: (string) $row['title'],
            content: $row['content'] === null ? null : (string) $row['content'],
            pageTitle: $row['page_title'] === null ? null : (string) $row['page_title'],
            metaDescription: $row['meta_description'] === null ? null : (string) $row['meta_description'],
            publishedAt: (string) $row['published_at'],
        );
    }

    /**
     * Overi publikovany retezec predku bez rekurzivniho SQL
     */
    private function ancestorsPublished(?int $parentId, string $locale, string $now): bool
    {
        $visited = [];
        while ($parentId !== null) {
            if (isset($visited[$parentId])) {
                return false;
            }
            $visited[$parentId] = true;
            $row = $this->query()
                ->from('cms_page page')
                ->select(['page.parent_id'])
                ->join('cms_page_translation translation', 'translation.page_id = page.id')
                ->where('page.id', $parentId)
                ->where('translation.locale', $locale)
                ->where('translation.publication_state', CmsPublicationState::Published->value)
                ->where('translation.published_at <=', $now)
                ->first();
            if ($row === null) {
                return false;
            }
            $parentId = $row['parent_id'] === null ? null : (int) $row['parent_id'];
        }

        return true;
    }

    /**
     * Vrati query builder lokalizovanych mutaci
     */
    private function translations(): QueryBuilder
    {
        return QueryBuilder::make($this->db)->table('cms_page_translation');
    }
}
