<?php

declare(strict_types=1);

namespace Lemonade\Cms\Page\Services;

use Lemonade\Admin\Audit\AuditActor;
use Lemonade\Admin\Audit\AuditOperation;
use Lemonade\Admin\Authorization\AuthorizationService;
use Lemonade\Admin\Event\DomainEvent;
use Lemonade\Admin\Event\TransactionalEventCollector;
use Lemonade\Admin\Event\TransactionalEventProcessor;
use Lemonade\Admin\Localization\LanguageRegistry;
use Lemonade\Cms\CmsPublicationState;
use Lemonade\Cms\Page\Hierarchy\PageHierarchy;
use Lemonade\Cms\Page\Models\PageModel;
use Lemonade\Cms\Page\Publication\PagePublicationPlanResolver;
use Lemonade\Cms\Page\Routing\PageSubtreeRouteMapper;
use Lemonade\Cms\Page\Template\PageTemplateRendererInterface;
use Lemonade\Cms\Routing\Cms\CmsRouteReservationService;
use Lemonade\Cms\Routing\Cms\CmsRouteValidationException;
use Lemonade\Framework\Support\Slug\Slugger;
use RuntimeException;

/**
 * Orchestruje strom, lokalizace, routy a audit CMS stranek
 */
final readonly class PageService
{
    private const int MAXIMUM_PATH_LENGTH = 700;

    /**
     * Nastavuje persistence, authorization, routy, templates a transakcni audit
     */
    public function __construct(
        private PageModel $pages,
        private LanguageRegistry $languages,
        private CmsRouteReservationService $routes,
        private Slugger $slugger,
        private AuthorizationService $authorization,
        private TransactionalEventProcessor $events,
        private PageTemplateRendererInterface $templates,
        private PageSubtreeRouteMapper $subtreeRoutes,
        private PagePublicationPlanResolver $publicationPlans,
    ) {}

    /**
     * Vytvori minimalni draft ve vychozim locale a prvni host sablone
     */
    public function createDraft(AuditActor $actor, string $locale): int
    {
        $this->authorization->requirePermission('cms.page.create');
        if (!$this->enabledLocale($locale)) {
            throw new RuntimeException('page.validation.locale_invalid');
        }
        $templates = $this->templates->templates();
        $template = $templates[0] ?? throw new RuntimeException('page.validation.template_invalid');

        return $this->events->execute(
            new AuditOperation('cms.page', 'page.create', $actor),
            function (TransactionalEventCollector $events) use ($locale, $template): int {
                $pageId = $this->pages->createDraft(
                    locale: $locale,
                    templateKey: $template->key(),
                    now: date('Y-m-d H:i:s'),
                );
                if ($pageId < 1) {
                    throw new RuntimeException('page.validation.save_failed');
                }
                $events->record(new DomainEvent('cms.page.created', 'cms.page', 'cms_page', (string) $pageId));

                return $pageId;
            },
        );
    }

    /**
     * Ulozi globalni nastaveni, jednu lokalizaci a publication stav v jedne transakci
     *
     * @param array<string,mixed> $data
     */
    public function save(AuditActor $actor, int $pageId, array $data): void
    {
        $this->authorization->requirePermission('cms.page.edit');
        $currentPage = $this->pages->page($pageId);
        if ($currentPage === null) {
            throw new RuntimeException('page.validation.not_found');
        }
        $locale = strtolower(trim((string) ($data['locale'] ?? '')));
        $title = trim((string) ($data['title'] ?? ''));
        $content = $this->nullableText($data['content'] ?? null);
        $pageTitle = $this->nullableText($data['page_title'] ?? null);
        $metaDescription = $this->nullableText($data['meta_description'] ?? null);
        $parentId = $this->nullablePositiveInt($data['parent_id'] ?? null);
        $templateKey = trim((string) ($data['template_key'] ?? ''));
        $urlEdited = (string) ($data['url_segment_edited'] ?? '') === '1';
        $urlSegment = $urlEdited ? trim((string) ($data['url_segment'] ?? '')) : '';
        $publishedAtInput = (string) ($data['published_at'] ?? '');
        if ($title === '') {
            throw new RuntimeException('page.validation.title_required');
        }
        if (mb_strlen($title) > 180) {
            throw new RuntimeException('page.validation.title_max_length');
        }

        $existingTranslation = $this->pages->translation($pageId, $locale);
        if ($existingTranslation === null && !$this->enabledLocale($locale)) {
            throw new RuntimeException('page.validation.locale_invalid');
        }
        $requestedState = CmsPublicationState::tryFrom(
            (int) ($data['publication_state'] ?? CmsPublicationState::Draft->value),
        );
        if ($requestedState === null) {
            throw new RuntimeException('page.validation.publication_invalid');
        }
        $publication = $this->publicationPlans->resolve(
            $requestedState,
            $publishedAtInput,
            $existingTranslation,
            date('Y-m-d H:i:s'),
        );
        $this->assertTemplateCanBeSaved(
            requestedTemplateKey: $templateKey,
            currentTemplateKey: (string) $currentPage['template_key'],
            publicationState: $publication->state,
        );
        if ($publication->requiresPermission) {
            $this->authorization->requirePermission('cms.page.publish');
        }
        if ($this->isSaveNoOp(
            pageId: $pageId,
            currentPage: $currentPage,
            existingTranslation: $existingTranslation,
            parentId: $parentId,
            templateKey: $templateKey,
            locale: $locale,
            title: $title,
            content: $content,
            pageTitle: $pageTitle,
            metaDescription: $metaDescription,
            urlSegment: $urlSegment,
            urlEdited: $urlEdited,
            publicationState: $publication->state,
            publishedAt: $publication->publishedAt,
        )) {
            return;
        }

        $this->events->execute(
            new AuditOperation('cms.page', 'page.save', $actor),
            function (TransactionalEventCollector $events) use (
                $pageId,
                $parentId,
                $templateKey,
                $locale,
                $title,
                $urlSegment,
                $urlEdited,
                $content,
                $pageTitle,
                $metaDescription,
                $requestedState,
                $publishedAtInput,
            ): void {
                $now = date('Y-m-d H:i:s');
                $treeRows = $this->pages->activeTreeForUpdate();
                $tree = new PageHierarchy($treeRows);
                $tree->assertMove($pageId, $parentId);
                $currentPage = $this->treeRow($treeRows, $pageId);
                $existingTranslation = $this->pages->translationForUpdate($pageId, $locale);
                if ($existingTranslation === null && !$this->enabledLocale($locale)) {
                    throw new RuntimeException('page.validation.locale_invalid');
                }
                $publication = $this->publicationPlans->resolve(
                    $requestedState,
                    $publishedAtInput,
                    $existingTranslation,
                    $now,
                );
                $this->assertTemplateCanBeSaved(
                    requestedTemplateKey: $templateKey,
                    currentTemplateKey: (string) $currentPage['template_key'],
                    publicationState: $publication->state,
                );
                if ($publication->requiresPermission) {
                    $this->authorization->requirePermission('cms.page.publish');
                }
                $currentParentId = $currentPage['parent_id'] === null ? null : (int) $currentPage['parent_id'];
                $subtreeIds = $tree->descendantIds($pageId);
                $this->updateSubtreeRoutes(
                    pageId: $pageId,
                    oldParentId: $currentParentId,
                    newParentId: $parentId,
                    locale: $locale,
                    requestedSegment: $urlSegment,
                    urlEdited: $urlEdited,
                    title: $title,
                    subtreeIds: $subtreeIds,
                );
                $sortOrder = $currentParentId === $parentId
                    ? (int) $currentPage['sort_order']
                    : $this->moveToSiblingEnd($pageId, $currentParentId, $parentId, $treeRows, $now);
                $this->pages->saveSettings(
                    pageId: $pageId,
                    parentId: $parentId,
                    sortOrder: $sortOrder,
                    templateKey: $templateKey,
                    now: $now,
                );
                $this->pages->saveTranslation(
                    pageId: $pageId,
                    locale: $locale,
                    title: $title,
                    content: $content,
                    pageTitle: $pageTitle,
                    metaDescription: $metaDescription,
                    now: $now,
                    translationId: isset($existingTranslation['id']) ? (int) $existingTranslation['id'] : null,
                );
                if ($publication->changed) {
                    $this->savePublication(
                        pageId: $pageId,
                        locale: $locale,
                        parentId: $parentId,
                        state: $publication->state,
                        publishedAt: $publication->publishedAt,
                        now: $now,
                        subtreeIds: $subtreeIds,
                    );
                }
                $events->record(new DomainEvent(
                    $existingTranslation === null ? 'cms.page.translation_created' : 'cms.page.updated',
                    'cms.page',
                    'cms_page',
                    (string) $pageId,
                    ['locale' => $locale],
                ));
                if ($publication->requiresPermission) {
                    $events->record(new DomainEvent(
                        $publication->state === CmsPublicationState::Published
                            ? 'cms.page.published'
                            : 'cms.page.unpublished',
                        'cms.page',
                        'cms_page',
                        (string) $pageId,
                        ['locale' => $locale],
                    ));
                }
            },
        );
    }

    /**
     * Soft-deleteuje pouze list bez aktivnich potomku
     */
    public function delete(AuditActor $actor, int $pageId): void
    {
        $this->authorization->requirePermission('cms.page.delete');
        $page = $this->pages->lifecycle($pageId);
        if ($page === null) {
            throw new RuntimeException('page.validation.not_found');
        }
        if ($page['deleted_at'] !== null) {
            return;
        }
        $this->events->execute(
            new AuditOperation('cms.page', 'page.delete', $actor),
            function (TransactionalEventCollector $events) use ($pageId): void {
                $rows = $this->pages->activeTreeForUpdate();
                $tree = new PageHierarchy($rows);
                if (count($tree->descendantIds($pageId)) > 1) {
                    throw new RuntimeException('page.validation.delete_has_children');
                }
                $now = date('Y-m-d H:i:s');
                $page = $this->treeRow($rows, $pageId);
                $parentId = $page['parent_id'] === null ? null : (int) $page['parent_id'];
                $this->pages->softDelete($pageId, $now);
                $remainingSiblings = array_values(array_filter(
                    $this->siblingIds($rows, $parentId),
                    static fn(int $siblingId): bool => $siblingId !== $pageId,
                ));
                $this->pages->saveSiblingOrder($remainingSiblings, $now);
                $this->routes->softDeleteForTarget('cms.page', $pageId, $now);
                $events->record(new DomainEvent('cms.page.deleted', 'cms.page', 'cms_page', (string) $pageId));
            },
        );
    }

    /**
     * Obnovi stranku jako draft pokud je jeji rodic stale aktivni
     */
    public function restore(AuditActor $actor, int $pageId): void
    {
        $this->authorization->requirePermission('cms.page.restore');
        $this->events->execute(
            new AuditOperation('cms.page', 'page.restore', $actor),
            function (TransactionalEventCollector $events) use ($pageId): void {
                $page = $this->pages->lifecycleForUpdate($pageId);
                if ($page === null) {
                    throw new RuntimeException('page.validation.not_found');
                }
                if ($page['deleted_at'] === null) {
                    return;
                }
                $rows = $this->pages->activeTreeForUpdate();
                $parentId = $page['parent_id'] === null ? null : (int) $page['parent_id'];
                $parentExists = $parentId === null;
                foreach ($rows as $row) {
                    if ((int) $row['id'] === $parentId) {
                        $parentExists = true;
                        break;
                    }
                }
                if (!$parentExists) {
                    throw new RuntimeException('page.validation.parent_invalid');
                }
                $now = date('Y-m-d H:i:s');
                $this->pages->restoreDraft($pageId, $now);
                $siblings = $this->siblingIds($rows, $parentId);
                $siblings[] = $pageId;
                $this->pages->saveSiblingOrder($siblings, $now);
                $this->routes->restoreForTarget('cms.page', $pageId, $now);
                $events->record(new DomainEvent('cms.page.restored', 'cms.page', 'cms_page', (string) $pageId));
            },
        );
    }

    /**
     * Posune stranku o jednu pozici mezi sourozenci
     */
    public function reorder(AuditActor $actor, int $pageId, int $direction): void
    {
        $this->authorization->requirePermission('cms.page.edit');
        if (!in_array($direction, [-1, 1], true)) {
            throw new RuntimeException('page.validation.reorder_invalid');
        }
        $this->events->execute(
            new AuditOperation('cms.page', 'page.reorder', $actor),
            function (TransactionalEventCollector $events) use ($pageId, $direction): void {
                $rows = $this->pages->activeTreeForUpdate();
                $page = $this->treeRow($rows, $pageId);
                $parentId = $page['parent_id'] === null ? null : (int) $page['parent_id'];
                $siblings = $this->siblingIds($rows, $parentId);
                $position = array_search($pageId, $siblings, true);
                if ($position === false) {
                    throw new RuntimeException('page.validation.not_found');
                }
                $target = $position + $direction;
                if (!isset($siblings[$target])) {
                    return;
                }
                [$siblings[$position], $siblings[$target]] = [$siblings[$target], $siblings[$position]];
                $this->pages->saveSiblingOrder(array_values($siblings), date('Y-m-d H:i:s'));
                $events->record(new DomainEvent('cms.page.reordered', 'cms.page', 'cms_page', (string) $pageId));
            },
        );
    }

    /**
     * Prepocita cely podstrom pri presunu nebo explicitni zmene segmentu
     *
     * @param list<int> $subtreeIds
     */
    private function updateSubtreeRoutes(
        int $pageId,
        ?int $oldParentId,
        ?int $newParentId,
        string $locale,
        string $requestedSegment,
        bool $urlEdited,
        string $title,
        array $subtreeIds,
    ): void {
        $routeRows = $this->pages->routesForPages($subtreeIds);
        $rootRoutes = [];
        foreach ($routeRows as $route) {
            if ((int) $route['entity_id'] === $pageId) {
                $rootRoutes[$route['locale']] = $route['path'];
            }
        }

        $currentPath = $rootRoutes[$locale] ?? null;
        $segment = $urlEdited
            ? $this->explicitSegment($requestedSegment, $locale)
            : ($currentPath === null ? $this->slugger->slug($title, 180, $locale) : basename($currentPath));
        if ($segment === '') {
            throw new RuntimeException('page.validation.url_invalid');
        }

        $parentChanged = $oldParentId !== $newParentId;
        $segmentChanged = $currentPath !== null && basename($currentPath) !== $segment;
        $prefixes = [];
        foreach ($rootRoutes as $routeLocale => $oldPrefix) {
            $leaf = $routeLocale === $locale ? $segment : basename($oldPrefix);
            $prefixes[$routeLocale] = [
                'old' => $oldPrefix,
                'new' => $this->pathUnderParent($newParentId, $routeLocale, $leaf),
            ];
        }
        if (($parentChanged || $segmentChanged) && $routeRows !== []) {
            try {
                $this->routes->replaceExplicitBatch(
                    'cms.page',
                    $this->subtreeRoutes->replacements($routeRows, $prefixes),
                );
            } catch (CmsRouteValidationException $exception) {
                throw $this->pageRouteValidation($exception);
            }
        }

        if ($currentPath !== null) {
            return;
        }
        $path = $this->pathUnderParent($newParentId, $locale, $segment);
        try {
            if (!$urlEdited) {
                $this->routes->reserveAutomatic('cms.page', $pageId, $locale, $path, self::MAXIMUM_PATH_LENGTH);

                return;
            }
            $this->routes->reserveExplicit('cms.page', $pageId, $locale, $path);
        } catch (CmsRouteValidationException $exception) {
            throw $this->pageRouteValidation($exception);
        }
    }

    /**
     * Normalizuje zdrojove sourozence a prida presouvanou stranku na konec cile
     *
     * @param list<array{id:int,parent_id:int|null,sort_order:int,template_key:string,updated_at:string,deleted_at:mixed}> $rows
     */
    private function moveToSiblingEnd(
        int $pageId,
        ?int $oldParentId,
        ?int $newParentId,
        array $rows,
        string $now,
    ): int {
        $sourceSiblings = array_values(array_filter(
            $this->siblingIds($rows, $oldParentId),
            static fn(int $siblingId): bool => $siblingId !== $pageId,
        ));
        $targetSiblings = array_values(array_filter(
            $this->siblingIds($rows, $newParentId),
            static fn(int $siblingId): bool => $siblingId !== $pageId,
        ));
        $this->pages->saveSiblingOrder($sourceSiblings, $now);
        $this->pages->saveSiblingOrder($targetSiblings, $now);

        return count($targetSiblings);
    }

    /**
     * Vrati sourozence ve stabilnim poradi
     *
     * @param list<array{id:int,parent_id:int|null,sort_order:int,template_key:string,updated_at:string,deleted_at:mixed}> $rows
     * @return list<int>
     */
    private function siblingIds(array $rows, ?int $parentId): array
    {
        $siblings = array_values(array_filter(
            $rows,
            static fn(array $row): bool => ($row['parent_id'] === null ? null : (int) $row['parent_id']) === $parentId,
        ));
        usort($siblings, static fn(array $left, array $right): int => [(int) $left['sort_order'], (int) $left['id']] <=> [(int) $right['sort_order'], (int) $right['id']]);

        return array_map(static fn(array $row): int => (int) $row['id'], $siblings);
    }

    /**
     * Vrati zamceny radek stranky nebo ohlasi chybejici cil
     *
     * @param list<array{id:int,parent_id:int|null,sort_order:int,template_key:string,updated_at:string,deleted_at:mixed}> $rows
     * @return array{id:int,parent_id:int|null,sort_order:int,template_key:string,updated_at:string,deleted_at:mixed}
     */
    private function treeRow(array $rows, int $pageId): array
    {
        foreach ($rows as $row) {
            if ((int) $row['id'] === $pageId) {
                return $row;
            }
        }

        throw new RuntimeException('page.validation.not_found');
    }

    /**
     * Sestavi lokalizovanou cestu pod rodicem a vyzada jeho existujici route
     */
    private function pathUnderParent(?int $parentId, string $locale, string $segment): string
    {
        if ($parentId === null) {
            return $segment;
        }
        $parentPath = $this->pages->routePath($parentId, $locale);
        if ($parentPath === null) {
            throw new RuntimeException('page.validation.parent_locale_route_missing');
        }

        return $parentPath . '/' . $segment;
    }

    /**
     * Overi a normalizuje explicitne zadany jediny URL segment
     */
    private function explicitSegment(string $value, string $locale): string
    {
        $value = trim($value, '/');
        if ($value === '' || str_contains($value, '/') || $this->slugger->slug($value, 180, $locale) !== $value) {
            throw new RuntimeException('page.validation.url_invalid');
        }

        return $value;
    }

    /**
     * Ulozi publication stav se zachovanim hierarchicke dostupnosti
     *
     * @param list<int> $subtreeIds
     */
    private function savePublication(
        int $pageId,
        string $locale,
        ?int $parentId,
        CmsPublicationState $state,
        ?string $publishedAt,
        string $now,
        array $subtreeIds,
    ): void {
        $descendantIds = array_values(array_filter(
            $subtreeIds,
            static fn(int $descendantId): bool => $descendantId !== $pageId,
        ));
        if ($state !== CmsPublicationState::Published
            && $this->pages->hasPublishedDescendants($descendantIds, $locale)
        ) {
            throw new RuntimeException('page.validation.unpublish_has_published_children');
        }
        if ($state === CmsPublicationState::Published) {
            if (!$this->routes->hasReservation('cms.page', $pageId, $locale)) {
                throw new RuntimeException('page.validation.url_required');
            }
            if ($parentId !== null) {
                $parent = $this->pages->translation($parentId, $locale);
                if ($parent === null
                    || (int) $parent['publication_state'] !== CmsPublicationState::Published->value
                    || $parent['published_at'] === null
                    || $publishedAt === null
                    || (string) $parent['published_at'] > $publishedAt
                ) {
                    throw new RuntimeException('page.validation.parent_not_published');
                }
            }
        }
        $this->pages->savePublication($pageId, $locale, $state, $publishedAt, $now);
    }

    /**
     * Prelozi shared route chybu na validacni klic Pages editoru
     */
    private function pageRouteValidation(CmsRouteValidationException $exception): RuntimeException
    {
        return new RuntimeException(match ($exception->getMessage()) {
            'cms.route.path_reserved' => 'page.validation.url_reserved',
            'cms.route.path_occupied' => 'page.validation.url_occupied',
            'cms.route.path_invalid' => 'page.validation.url_invalid',
            default => 'page.validation.route_inconsistent',
        }, previous: $exception);
    }

    /**
     * Prevede typed parent option na volitelne ID
     */
    private function nullablePositiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || preg_match('/^page-([1-9][0-9]*)$/D', $value, $matches) !== 1) {
            throw new RuntimeException('page.validation.parent_invalid');
        }

        return (int) $matches[1];
    }

    /**
     * Normalizuje prazdny lokalizovany text na null
     */
    private function nullableText(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    /**
     * Overi content locale proti canonical language registry
     */
    private function enabledLocale(string $locale): bool
    {
        foreach ($this->languages->enabledLocales() as $language) {
            if ($language['code'] === $locale) {
                return true;
            }
        }

        return false;
    }

    /**
     * Overi template klic proti aktualni host nabidce
     */
    private function assertTemplateCanBeSaved(
        string $requestedTemplateKey,
        string $currentTemplateKey,
        CmsPublicationState $publicationState,
    ): void {
        foreach ($this->templates->templates() as $template) {
            if ($template->key() === $requestedTemplateKey) {
                return;
            }
        }

        if ($requestedTemplateKey === $currentTemplateKey
            && $publicationState !== CmsPublicationState::Published
        ) {
            return;
        }

        throw new RuntimeException('page.validation.template_invalid');
    }

    /**
     * Rozpozna skutecne nezmeneny save pred otevrenim auditovane write transakce
     *
     * @param array<string,mixed> $currentPage
     * @param array<string,mixed>|null $existingTranslation
     */
    private function isSaveNoOp(
        int $pageId,
        array $currentPage,
        ?array $existingTranslation,
        ?int $parentId,
        string $templateKey,
        string $locale,
        string $title,
        ?string $content,
        ?string $pageTitle,
        ?string $metaDescription,
        string $urlSegment,
        bool $urlEdited,
        CmsPublicationState $publicationState,
        ?string $publishedAt,
    ): bool {
        if ($existingTranslation === null) {
            return false;
        }
        $currentParentId = $currentPage['parent_id'] === null
            ? null
            : (int) $currentPage['parent_id'];
        if ($currentParentId !== $parentId
            || (string) $currentPage['template_key'] !== $templateKey
            || (string) ($existingTranslation['title'] ?? '') !== $title
            || $this->nullableText($existingTranslation['content'] ?? null) !== $content
            || $this->nullableText($existingTranslation['page_title'] ?? null) !== $pageTitle
            || $this->nullableText($existingTranslation['meta_description'] ?? null) !== $metaDescription
            || (int) $existingTranslation['publication_state'] !== $publicationState->value
            || $this->nullableText($existingTranslation['published_at'] ?? null) !== $publishedAt
        ) {
            return false;
        }

        $currentPath = $this->pages->routePath($pageId, $locale);
        if ($currentPath === null) {
            return false;
        }

        return !$urlEdited || basename($currentPath) === $this->explicitSegment($urlSegment, $locale);
    }
}
