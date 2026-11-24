<?php

declare(strict_types=1);

namespace Lemonade\Cms\Page\Public;

use DateTimeImmutable;
use InvalidArgumentException;
use Lemonade\Admin\File\FileMetadata;
use Lemonade\Admin\File\FileReadModel;
use Lemonade\Cms\CmsPublicationState;
use Lemonade\Cms\Page\ModuleManifest;
use Lemonade\Cms\Routing\Cms\PublicCmsUrlBuilder;
use Lemonade\Cms\Routing\Locale\PublicLocaleResolution;
use Lemonade\Cms\Routing\Module\PublicModuleStateResolverInterface;
use Lemonade\Framework\Database\Database;

/**
 * Reads published pages with their currently available ancestor hierarchy
 */
final readonly class PublishedPageReadModel
{
    /**
     * Creates the public page reader with canonical CMS and file dependencies
     */
    public function __construct(
        private Database $database,
        private FileReadModel $files,
        private PublicCmsUrlBuilder $urls,
        private PublicModuleStateResolverInterface $modules,
    ) {}

    /**
     * Returns one visible page translation or null when it cannot be published publicly
     */
    public function record(int $pageId, PublicLocaleResolution $locale): ?PublishedPage
    {
        $language = $locale->getLocale();
        if ($language === null) {
            return null;
        }

        $rows = $this->visibleRows($language, date('Y-m-d H:i:s'));
        if (!isset($rows[$pageId])) {
            return null;
        }

        return $this->hydrate([$rows[$pageId]], $locale)[0];
    }

    /**
     * Searches visible pages in their requested locale and ancestor hierarchy
     *
     * @return list<PublishedPage>
     */
    public function search(PublicLocaleResolution $locale, string $term, int $limit = 50): array
    {
        if ($limit < 1) {
            throw new InvalidArgumentException('Page search limit must be positive.');
        }
        $language = $locale->getLocale();
        $term = trim($term);
        if ($language === null || $term === '') {
            return [];
        }

        $visible = $this->visibleRows($language, date('Y-m-d H:i:s'));
        if ($visible === []) {
            return [];
        }
        $pattern = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term) . '%';
        $matches = $this->database->table('cms_page_translation')
            ->select(['page_id'])
            ->where('locale', $language)
            ->whereIn('page_id', array_keys($visible))
            ->whereRaw(
                "(title LIKE ? ESCAPE '!' OR summary LIKE ? ESCAPE '!' OR content LIKE ? ESCAPE '!')",
                [$pattern, $pattern, $pattern],
            )
            ->orderBy('page_id', 'ASC')
            ->limit($limit)
            ->getArray();

        $rows = [];
        foreach ($matches as $match) {
            $rows[] = $visible[(int) $match['page_id']];
        }

        return $this->hydrate($rows, $locale);
    }

    /**
     * Returns the visible parent in the page's content locale
     */
    public function parent(PublishedPage $page, PublicLocaleResolution $locale): ?PublishedPage
    {
        if ($page->getLocale() !== $locale->getLocale()) {
            return null;
        }

        $parentId = $page->getParentId();

        return $parentId === null ? null : $this->record($parentId, $locale);
    }

    /**
     * Returns visible children in sibling order or root pages for a null parent
     *
     * @return list<PublishedPage>
     */
    public function children(?int $parentId, PublicLocaleResolution $locale): array
    {
        $language = $locale->getLocale();
        if ($language === null) {
            return [];
        }

        $rows = $this->visibleRows($language, date('Y-m-d H:i:s'));
        if ($parentId !== null && !isset($rows[$parentId])) {
            return [];
        }

        $children = array_values(array_filter(
            $rows,
            static fn(array $row): bool => ($row['parent_id'] === null ? null : (int) $row['parent_id']) === $parentId,
        ));
        usort($children, static fn(array $left, array $right): int => [
            (int) $left['sort_order'],
            (int) $left['id'],
        ] <=> [
            (int) $right['sort_order'],
            (int) $right['id'],
        ]);

        return $this->hydrate($children, $locale);
    }

    /**
     * Returns visible ancestors from the root to the direct parent
     *
     * @return list<PublishedPage>
     */
    public function ancestors(PublishedPage $page, PublicLocaleResolution $locale): array
    {
        if ($page->getLocale() !== $locale->getLocale()) {
            return [];
        }

        $rows = $this->visibleRows($page->getLocale(), date('Y-m-d H:i:s'));
        if (!isset($rows[$page->getId()])) {
            return [];
        }

        $ancestors = [];
        $parentId = $page->getParentId();
        while ($parentId !== null) {
            $parent = $rows[$parentId] ?? null;
            if ($parent === null) {
                return [];
            }
            $ancestors[] = $parent;
            $parentId = $parent['parent_id'] === null ? null : (int) $parent['parent_id'];
        }

        return $this->hydrate(array_reverse($ancestors), $locale);
    }

    /**
     * Returns enabled language alternatives with a visible canonical route
     *
     * @return list<PublishedPageLanguageAlternative>
     */
    public function languageAlternatives(int $pageId, PublicLocaleResolution $locale): array
    {
        if ($locale->getLocale() === null) {
            return [];
        }

        $alternatives = [];
        $now = date('Y-m-d H:i:s');
        foreach ($this->enabledLocales() as $language) {
            $row = $this->visibleRows($language, $now)[$pageId] ?? null;
            if ($row === null) {
                continue;
            }
            $alternatives[] = new PublishedPageLanguageAlternative(
                $language,
                $this->urls->build($locale, $language, (string) $row['canonical_path']),
            );
        }

        return $alternatives;
    }

    /**
     * Yields visible page candidates with host template and last update time
     *
     * @return iterable<PublishedPageSitemapEntry>
     */
    public function sitemapEntries(PublicLocaleResolution $locale): iterable
    {
        $language = $locale->getLocale();
        if ($language === null) {
            return;
        }

        foreach ($this->visibleRows($language, date('Y-m-d H:i:s'), sitemap: true) as $row) {
            yield new PublishedPageSitemapEntry(
                $this->urls->build($locale, $language, (string) $row['canonical_path']),
                (string) $row['template_key'],
                new DateTimeImmutable((string) $row['last_modified_at']),
            );
        }
    }

    /**
     * Reports whether any enabled locale makes a page publicly visible
     */
    public function isPublicInAnyLocale(int $pageId): bool
    {
        $now = date('Y-m-d H:i:s');
        foreach ($this->enabledLocales() as $language) {
            if (isset($this->visibleRows($language, $now)[$pageId])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns active and hierarchy-visible rows keyed by their page identifier
     *
     * @return array<int,array<string,mixed>>
     */
    private function visibleRows(string $locale, string $now, bool $sitemap = false): array
    {
        if (!$this->modules->isDiscoveredInstalledAndEnabled(ModuleManifest::CODE)) {
            return [];
        }

        $columns = ['page.id', 'page.parent_id', 'page.template_key'];
        if (!$sitemap) {
            $columns = [
                ...$columns, 'page.sort_order',
                'translation.locale', 'translation.title', 'translation.summary', 'translation.content',
                'translation.page_title', 'translation.meta_description',
                'translation.published_at', 'translation.updated_at',
            ];
        }

        $rows = $this->database->table('cms_page page')
            ->select($columns)
            ->selectRaw('route.path AS canonical_path, GREATEST(page.updated_at, translation.updated_at, route.updated_at) AS last_modified_at')
            ->joinOn('cms_page_translation translation', 'translation.page_id', '=', 'page.id')
            ->joinOn('system_language language', 'language.code', '=', 'translation.locale')
            ->joinRaw(
                'cms_route route',
                'route.module_code = ? AND route.entity_id = page.id AND route.locale = translation.locale AND route.deleted_at IS NULL AND route.path <> ?',
                [ModuleManifest::CODE, ''],
            )
            ->where('page.deleted_at', null)
            ->where('translation.locale', $locale)
            ->where('language.enabled', 1)
            ->where('translation.publication_state', CmsPublicationState::Published->value)
            ->where('translation.published_at !=', null)
            ->where('translation.published_at <=', $now)
            ->whereRaw("TRIM(translation.title) <> ''")
            ->orderBy('page.id', 'ASC')
            ->getArray();

        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['id']] = $row;
        }

        $visible = [];
        $visiting = [];
        $isVisible = static function (int $pageId) use (&$isVisible, &$visible, &$visiting, $byId): bool {
            if (isset($visible[$pageId])) {
                return $visible[$pageId];
            }
            if (isset($visiting[$pageId])) {
                return false;
            }

            $visiting[$pageId] = true;
            $parentId = $byId[$pageId]['parent_id'];
            $result = $parentId === null || (
                isset($byId[(int) $parentId]) && $isVisible((int) $parentId)
            );
            unset($visiting[$pageId]);
            $visible[$pageId] = $result;

            return $result;
        };

        foreach (array_keys($byId) as $pageId) {
            $isVisible($pageId);
        }

        return array_filter($byId, static fn(array $row): bool => $visible[(int) $row['id']]);
    }

    /**
     * Converts visible rows into complete page objects with batched system media
     *
     * @param list<array<string,mixed>> $rows
     * @return list<PublishedPage>
     */
    private function hydrate(array $rows, PublicLocaleResolution $locale): array
    {
        if ($rows === []) {
            return [];
        }

        $ids = array_map(static fn(array $row): int => (int) $row['id'], $rows);
        $media = [];
        foreach ($this->files->listForEntitiesUsages(ModuleManifest::CODE, $ids, ['thumbnail', 'gallery', 'attachment']) as $file) {
            if (!$this->isReadableFile($file)) {
                continue;
            }
            $media[$file->entityId()][$file->usage()][] = $file;
        }

        $pages = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $pageMedia = $media[$id] ?? [];
            $pages[] = new PublishedPage(
                id: $id,
                parentId: $row['parent_id'] === null ? null : (int) $row['parent_id'],
                templateKey: (string) $row['template_key'],
                locale: (string) $row['locale'],
                title: (string) $row['title'],
                summary: $row['summary'] === null ? null : (string) $row['summary'],
                content: $row['content'] === null ? null : (string) $row['content'],
                pageTitle: $row['page_title'] === null ? null : (string) $row['page_title'],
                metaDescription: $row['meta_description'] === null ? null : (string) $row['meta_description'],
                publishedAt: new DateTimeImmutable((string) $row['published_at']),
                updatedAt: new DateTimeImmutable((string) $row['updated_at']),
                publicUrl: $this->urls->build($locale, (string) $row['locale'], (string) $row['canonical_path']),
                thumbnail: $pageMedia['thumbnail'][0] ?? null,
                gallery: $pageMedia['gallery'] ?? [],
                attachments: $pageMedia['attachment'] ?? [],
            );
        }

        return $pages;
    }

    /**
     * Checks whether a stored file has the backing data needed for public use
     */
    private function isReadableFile(FileMetadata $file): bool
    {
        if (in_array($file->usage(), ['thumbnail', 'gallery'], true) && $file->kind() !== 'image') {
            return false;
        }
        if ($file->kind() === 'image') {
            return $file->assetId() !== null && $file->sourceVersion() !== null;
        }

        return $file->kind() === 'file' && $file->storagePath() !== null;
    }

    /**
     * Returns enabled content locales in their configured order
     *
     * @return list<string>
     */
    private function enabledLocales(): array
    {
        $rows = $this->database->table('system_language')
            ->select(['code'])
            ->where('enabled', 1)
            ->orderBy('sort_order', 'ASC')
            ->orderBy('code', 'ASC')
            ->getArray();

        return array_map(static fn(array $row): string => (string) $row['code'], $rows);
    }
}
