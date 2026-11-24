<?php

declare(strict_types=1);

namespace Lemonade\Cms\Page\Services;

use DomainException;
use Lemonade\Admin\Audit\AuditActor;
use Lemonade\Admin\Event\DomainEvent;
use Lemonade\Admin\Event\TransactionalEventCollector;
use Lemonade\Admin\Event\TransactionalEventProcessor;
use Lemonade\Admin\Identity\LocalUserDirectory;
use Lemonade\Admin\Localization\LanguageRegistry;
use Lemonade\Cms\CmsPublicationState;
use Lemonade\Cms\Content\CmsContentInput;
use Lemonade\Cms\Content\CmsContentValidation;
use Lemonade\Cms\Content\CmsContentValidationException;
use Lemonade\Cms\Page\Hierarchy\PageHierarchy;
use Lemonade\Cms\Page\Models\PageModel;
use Lemonade\Cms\Page\Publication\PagePublicationPlanResolver;
use Lemonade\Cms\Page\Routing\PageSubtreeRouteMapper;
use Lemonade\Cms\Page\Template\PageTemplateProvider;
use Lemonade\Cms\Page\Template\PageTemplateProviderInterface;
use Lemonade\Cms\Routing\Cms\CmsRouteReservationService;
use Lemonade\Cms\Routing\Cms\CmsRouteValidationException;
use Lemonade\Framework\Support\Slug\Slugger;
use RuntimeException;

/**
 * Coordinates page hierarchy, translations, routes and audit
 */
final readonly class PageService
{
    private const int MAXIMUM_PATH_LENGTH = 700;

    /**
     * Creates the page service with persistence, routing and audit dependencies
     */
    public function __construct(
        private PageModel $pages,
        private LanguageRegistry $languages,
        private CmsRouteReservationService $routes,
        private Slugger $slugger,
        private TransactionalEventProcessor $events,
        private PageTemplateProviderInterface $templates,
        private PageSubtreeRouteMapper $subtreeRoutes,
        private PagePublicationPlanResolver $publicationPlans,
        private LocalUserDirectory $users,
    ) {}

    /**
     * Creates a minimal draft with the general page template
     */
    public function createDraft(AuditActor $actor, string $locale): int
    {
        if (!$this->enabledLocale($locale)) {
            throw new CmsContentValidationException(['locale' => 'page.validation.locale_invalid']);
        }

        return $this->events->execute(
            $actor,
            function (TransactionalEventCollector $events) use ($actor, $locale): int {
                $pageId = $this->pages->createDraft(
                    userId: $this->actorUserId($actor),
                    locale: $locale,
                    templateKey: PageTemplateProvider::DEFAULT_KEY,
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
     * Saves global settings, one translation and publication state in one transaction
     *
     * @param array<string,mixed> $data
     */
    public function save(AuditActor $actor, int $pageId, array $data): void
    {
        $input = CmsContentInput::fromEditor($data);
        $locale = $input->getLocale();
        $title = $input->getTitle();
        $summary = $input->getSummary();
        $content = $input->getContent();
        $pageTitle = $input->getPageTitle();
        $metaDescription = $input->getMetaDescription();
        $parentId = $this->nullablePositiveInt($data['parent_id'] ?? null);
        $templateKey = CmsContentValidation::texts($data, ['template_key'])['template_key'] ?? '';
        $requestedState = $input->hasPublication() ? CmsPublicationState::from((int) $input->getPublicationState()) : null;
        $publishedAtInput = (string) $input->getPublishedAt();

        $this->events->execute(
            $actor,
            function (TransactionalEventCollector $events) use (
                $actor,
                $input,
                $pageId,
                $parentId,
                $templateKey,
                $locale,
                $title,
                $summary,
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
                    throw new CmsContentValidationException(['locale' => 'page.validation.locale_invalid']);
                }
                $publication = $this->publicationPlans->resolve(
                    $requestedState,
                    $publishedAtInput,
                    $existingTranslation,
                    $now,
                );
                $errors = CmsContentValidation::content($input, $publication->getState(), 'page');
                try {
                    $this->assertTemplateCanBeSaved($templateKey, (string) $currentPage['template_key'], $publication->getState());
                } catch (CmsContentValidationException $exception) {
                    $errors += $exception->getErrors();
                }
                CmsContentValidation::assert($errors);
                foreach ($this->pages->publishedTranslations($pageId) as $published) {
                    if ($published['locale'] === $locale) {
                        continue;
                    }
                    $this->assertTemplateCanBeSaved($templateKey, (string) $currentPage['template_key'], CmsPublicationState::Published);
                    $this->assertPublication(
                        $pageId,
                        (string) $published['locale'],
                        $parentId,
                        CmsPublicationState::Published,
                        $published['published_at'] === null ? null : (string) $published['published_at'],
                        $tree->descendantIds($pageId),
                    );
                }
                $currentParentId = $currentPage['parent_id'] === null ? null : (int) $currentPage['parent_id'];
                $subtreeIds = $tree->descendantIds($pageId);
                $routesChanged = $this->updateSubtreeRoutes(
                    pageId: $pageId,
                    oldParentId: $currentParentId,
                    newParentId: $parentId,
                    locale: $locale,
                    explicitSegment: null,
                    title: $title,
                    subtreeIds: $subtreeIds,
                );
                $this->assertPublication($pageId, $locale, $parentId, $publication->getState(), $publication->getPublishedAt(), $subtreeIds);
                if (!$routesChanged && $this->isSaveNoOp(
                    pageId: $pageId,
                    currentPage: $currentPage,
                    existingTranslation: $existingTranslation,
                    parentId: $parentId,
                    templateKey: $templateKey,
                    locale: $locale,
                    title: $title,
                    summary: $summary,
                    content: $content,
                    pageTitle: $pageTitle,
                    metaDescription: $metaDescription,
                    publicationState: $publication->getState(),
                    publishedAt: $publication->getPublishedAt(),
                )) {
                    $events->noOp();

                    return;
                }
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
                    summary: $summary,
                    content: $content,
                    pageTitle: $pageTitle,
                    metaDescription: $metaDescription,
                    now: $now,
                    translationId: isset($existingTranslation['id']) ? (int) $existingTranslation['id'] : null,
                );
                if ($publication->isChanged()) {
                    $this->pages->savePublication($pageId, $locale, $publication->getState(), $publication->getPublishedAt(), $now);
                }
                $this->pages->touchUpdatedBy($pageId, $this->actorUserId($actor), $now);
                $events->record(new DomainEvent(
                    $existingTranslation === null ? 'cms.page.translation_created' : 'cms.page.updated',
                    'cms.page',
                    'cms_page',
                    (string) $pageId,
                    ['locale' => $locale],
                ));
                if ($publication->requiresPublicationEvent()) {
                    $events->record(new DomainEvent(
                        $publication->getState() === CmsPublicationState::Published
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
     * Changes an explicit URL segment and updates its subtree
     */
    public function changeUrlSegment(
        AuditActor $actor,
        int $pageId,
        string $locale,
        string $segment,
    ): string {
        $locale = strtolower(trim($locale));
        $segment = $this->explicitSegment($segment, $locale);

        return $this->events->execute(
            $actor,
            function (TransactionalEventCollector $events) use ($actor, $pageId, $locale, $segment): string {
                $treeRows = $this->pages->activeTreeForUpdate();
                $tree = new PageHierarchy($treeRows);
                $page = $this->treeRow($treeRows, $pageId);
                $translation = $this->pages->translationForUpdate($pageId, $locale);
                if ($translation === null && !$this->enabledLocale($locale)) {
                    throw new CmsContentValidationException(['locale' => 'page.validation.locale_invalid']);
                }
                $parentId = $page['parent_id'] === null ? null : (int) $page['parent_id'];
                $changed = $this->updateSubtreeRoutes(
                    pageId: $pageId,
                    oldParentId: $parentId,
                    newParentId: $parentId,
                    locale: $locale,
                    explicitSegment: $segment,
                    title: '',
                    subtreeIds: $tree->descendantIds($pageId),
                );
                if (!$changed) {
                    $events->noOp();

                    return $segment;
                }
                $this->pages->touchUpdatedBy($pageId, $this->actorUserId($actor), date('Y-m-d H:i:s'));
                $events->record(new DomainEvent(
                    'cms.page.updated',
                    'cms.page',
                    'cms_page',
                    (string) $pageId,
                    ['locale' => $locale],
                ));

                return $segment;
            },
        );
    }

    /**
     * Soft-deletes a leaf page without active descendants
     */
    public function delete(AuditActor $actor, int $pageId): void
    {
        $page = $this->pages->lifecycle($pageId);
        if ($page === null) {
            throw new DomainException('page.validation.not_found');
        }
        if ($page['deleted_at'] !== null) {
            return;
        }
        $this->events->execute(
            $actor,
            function (TransactionalEventCollector $events) use ($actor, $pageId): void {
                $rows = $this->pages->activeTreeForUpdate();
                $tree = new PageHierarchy($rows);
                if (count($tree->descendantIds($pageId)) > 1) {
                    throw new DomainException('page.validation.delete_has_children');
                }
                $now = date('Y-m-d H:i:s');
                $page = $this->treeRow($rows, $pageId);
                $parentId = $page['parent_id'] === null ? null : (int) $page['parent_id'];
                $this->pages->softDelete($pageId, $this->actorUserId($actor), $now);
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
     * Restores a page as a draft when its parent remains active
     */
    public function restore(AuditActor $actor, int $pageId): void
    {
        $page = $this->pages->lifecycle($pageId);
        if ($page === null) {
            throw new DomainException('page.validation.not_found');
        }
        if ($page['deleted_at'] === null) {
            return;
        }
        $this->events->execute(
            $actor,
            function (TransactionalEventCollector $events) use ($actor, $pageId): void {
                $page = $this->pages->lifecycleForUpdate($pageId);
                if ($page === null) {
                    throw new DomainException('page.validation.not_found');
                }
                if ($page['deleted_at'] === null) {
                    $events->noOp();

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
                    throw new CmsContentValidationException(['parent_id' => 'page.validation.parent_invalid']);
                }
                $now = date('Y-m-d H:i:s');
                $this->pages->restoreDraft($pageId, $this->actorUserId($actor), $now);
                $siblings = $this->siblingIds($rows, $parentId);
                $siblings[] = $pageId;
                $this->pages->saveSiblingOrder($siblings, $now);
                $this->routes->restoreForTarget('cms.page', $pageId, $now);
                $events->record(new DomainEvent('cms.page.restored', 'cms.page', 'cms_page', (string) $pageId));
            },
        );
    }

    /**
     * Moves a page by one sibling position
     */
    public function reorder(AuditActor $actor, int $pageId, int $direction): void
    {
        if (!in_array($direction, [-1, 1], true)) {
            throw new DomainException('page.validation.reorder_invalid');
        }
        $this->events->execute(
            $actor,
            function (TransactionalEventCollector $events) use ($actor, $pageId, $direction): void {
                $rows = $this->pages->activeTreeForUpdate();
                $page = $this->treeRow($rows, $pageId);
                $parentId = $page['parent_id'] === null ? null : (int) $page['parent_id'];
                $siblings = $this->siblingIds($rows, $parentId);
                $position = array_search($pageId, $siblings, true);
                if ($position === false) {
                    throw new DomainException('page.validation.not_found');
                }
                $target = $position + $direction;
                if (!isset($siblings[$target])) {
                    $events->noOp();

                    return;
                }
                [$siblings[$position], $siblings[$target]] = [$siblings[$target], $siblings[$position]];
                $now = date('Y-m-d H:i:s');
                $this->pages->saveSiblingOrder(array_values($siblings), $now);
                $this->pages->touchUpdatedBy($pageId, $this->actorUserId($actor), $now);
                $events->record(new DomainEvent('cms.page.reordered', 'cms.page', 'cms_page', (string) $pageId));
            },
        );
    }

    /**
     * Transfers ownership to a local user and records the audit change
     */
    public function transferOwner(AuditActor $actor, int $pageId, int $newOwnerId): void
    {
        $this->events->execute(
            $actor,
            function (TransactionalEventCollector $events) use ($actor, $pageId, $newOwnerId): void {
                $page = $this->pages->ownerForUpdate($pageId);
                if ($page === null) {
                    throw new DomainException('page.validation.not_found');
                }
                if (!$this->users->exists($newOwnerId)) {
                    throw new DomainException('page.validation.owner_invalid');
                }
                $previousOwnerId = $page['owner_id'] === null ? null : (int) $page['owner_id'];
                if ($previousOwnerId === $newOwnerId) {
                    $events->noOp();

                    return;
                }
                $this->pages->transferOwner(
                    $pageId,
                    $newOwnerId,
                    $this->actorUserId($actor),
                    date('Y-m-d H:i:s'),
                );
                $events->record(new DomainEvent(
                    'cms.page.owner_transferred',
                    'cms.page',
                    'cms_page',
                    (string) $pageId,
                    [
                        'previous_owner_id' => $previousOwnerId,
                        'new_owner_id' => $newOwnerId,
                    ],
                ));
            },
        );
    }

    /**
     * Updates subtree routes after a move or explicit segment change
     *
     * @param list<int> $subtreeIds
     */
    private function updateSubtreeRoutes(
        int $pageId,
        ?int $oldParentId,
        ?int $newParentId,
        string $locale,
        ?string $explicitSegment,
        string $title,
        array $subtreeIds,
    ): bool {
        $routeRows = $this->pages->routesForPages($subtreeIds);
        $rootRoutes = [];
        foreach ($routeRows as $route) {
            if ((int) $route['entity_id'] === $pageId) {
                $rootRoutes[$route['locale']] = $route['path'];
            }
        }

        $currentPath = $rootRoutes[$locale] ?? null;
        $segment = $explicitSegment;
        if ($segment === null && $currentPath !== null) {
            $segment = basename($currentPath);
        }
        if ($segment === null && $title !== '') {
            $segment = $this->slugger->slug($title, 180, $locale);
        }
        if ($segment === '') {
            throw new CmsContentValidationException(['url_segment' => 'admin.url_segment.invalid']);
        }

        $parentChanged = $oldParentId !== $newParentId;
        $segmentChanged = $currentPath !== null && basename($currentPath) !== $segment;
        $prefixes = [];
        foreach ($rootRoutes as $routeLocale => $oldPrefix) {
            $leaf = $routeLocale === $locale && $segment !== null ? $segment : basename($oldPrefix);
            $prefixes[$routeLocale] = [
                'old' => $oldPrefix,
                'new' => $this->pathUnderParent($newParentId, $routeLocale, $leaf),
            ];
        }
        if (($parentChanged || $segmentChanged) && $routeRows !== []) {
            $routesToReplace = $parentChanged
                ? $routeRows
                : array_values(array_filter(
                    $routeRows,
                    static fn(array $route): bool => $route['locale'] === $locale,
                ));
            if ($routesToReplace === []) {
                throw new CmsContentValidationException(['url_segment' => 'page.validation.route_inconsistent']);
            }
            try {
                $this->routes->replaceExplicitBatch(
                    'cms.page',
                    $this->subtreeRoutes->replacements($routesToReplace, $prefixes),
                );
            } catch (CmsRouteValidationException $exception) {
                throw $this->pageRouteValidation($exception);
            }
        }

        if ($currentPath !== null || $segment === null) {
            return ($parentChanged && $routeRows !== []) || $segmentChanged;
        }
        $path = $this->pathUnderParent($newParentId, $locale, $segment);
        try {
            if ($explicitSegment === null) {
                $this->routes->reserveAutomatic('cms.page', $pageId, $locale, $path, self::MAXIMUM_PATH_LENGTH);

                return true;
            }
            $this->routes->reserveExplicit('cms.page', $pageId, $locale, $path);
        } catch (CmsRouteValidationException $exception) {
            throw $this->pageRouteValidation($exception);
        }

        return true;
    }

    /**
     * Reorders source siblings and appends the page to its new siblings
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
     * Returns siblings in stable order
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
        usort(
            $siblings,
            static fn(array $left, array $right): int => [
                (int) $left['sort_order'],
                (int) $left['id'],
            ] <=> [
                (int) $right['sort_order'],
                (int) $right['id'],
            ],
        );

        return array_map(static fn(array $row): int => (int) $row['id'], $siblings);
    }

    /**
     * Returns the locked page row or reports a missing target
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

        throw new DomainException('page.validation.not_found');
    }

    /**
     * Builds a localized child path from its parent's route
     */
    private function pathUnderParent(?int $parentId, string $locale, string $segment): string
    {
        if ($parentId === null) {
            return $segment;
        }
        $parentPath = $this->pages->routePath($parentId, $locale);
        if ($parentPath === null) {
            throw new CmsContentValidationException(['parent_id' => 'page.validation.parent_locale_route_missing']);
        }

        return $parentPath . '/' . $segment;
    }

    /**
     * Validates and normalizes one explicit URL segment
     */
    private function explicitSegment(string $value, string $locale): string
    {
        $value = trim($value, '/');
        if ($value === '' || str_contains($value, '/')) {
            throw new CmsContentValidationException(['url_segment' => 'admin.url_segment.invalid']);
        }

        $value = $this->slugger->slug($value, 180, $locale);
        if ($value === '') {
            throw new CmsContentValidationException(['url_segment' => 'admin.url_segment.invalid']);
        }

        return $value;
    }

    /**
     * Validates hierarchy availability even when publication is unchanged
     *
     * @param list<int> $subtreeIds
     */
    private function assertPublication(
        int $pageId,
        string $locale,
        ?int $parentId,
        CmsPublicationState $state,
        ?string $publishedAt,
        array $subtreeIds,
    ): void {
        $descendantIds = array_values(array_filter(
            $subtreeIds,
            static fn(int $descendantId): bool => $descendantId !== $pageId,
        ));
        if ($state !== CmsPublicationState::Published
            && $this->pages->hasPublishedDescendants($descendantIds, $locale)
        ) {
            throw new CmsContentValidationException(['publication_state' => 'page.validation.unpublish_has_published_children']);
        }
        if ($state === CmsPublicationState::Published) {
            if (!$this->routes->hasReservation('cms.page', $pageId, $locale)) {
                throw new CmsContentValidationException(['url_segment' => 'page.validation.url_required']);
            }
            if ($parentId !== null) {
                $parent = $this->pages->translation($parentId, $locale);
                if ($parent === null
                    || (int) $parent['publication_state'] !== CmsPublicationState::Published->value
                    || $parent['published_at'] === null
                    || $publishedAt === null
                    || (string) $parent['published_at'] > $publishedAt
                ) {
                    throw new CmsContentValidationException(['parent_id' => 'page.validation.parent_not_published']);
                }
            }
        }
    }

    /**
     * Maps shared route errors to page editor validation keys
     */
    private function pageRouteValidation(CmsRouteValidationException $exception): DomainException
    {
        return new CmsContentValidationException(['url_segment' => match ($exception->getMessage()) {
            'cms.route.path_reserved' => 'admin.url_segment.reserved',
            'cms.route.path_occupied' => 'admin.url_segment.occupied',
            'cms.route.path_invalid' => 'admin.url_segment.invalid',
            default => 'page.validation.route_inconsistent',
        }]);
    }

    /**
     * Converts a parent option to an optional ID
     */
    private function nullablePositiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || preg_match('/^page-([1-9][0-9]*)$/D', $value, $matches) !== 1) {
            throw new CmsContentValidationException(['parent_id' => 'page.validation.parent_invalid']);
        }

        return (int) $matches[1];
    }

    /**
     * Normalizes empty localized text to null
     */
    private function nullableText(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    /**
     * Checks a content locale against the language registry
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
     * Validates a template code against the current offer
     */
    private function assertTemplateCanBeSaved(
        string $requestedTemplateKey,
        string $currentTemplateKey,
        CmsPublicationState $publicationState,
    ): void {
        foreach ($this->templates->templates() as $template) {
            if ($template->getKey() === $requestedTemplateKey) {
                return;
            }
        }

        if (($requestedTemplateKey === '' || $requestedTemplateKey === $currentTemplateKey)
            && $publicationState !== CmsPublicationState::Published
        ) {
            return;
        }

        throw new CmsContentValidationException(['template_key' => 'page.validation.template_invalid']);
    }

    /**
     * Detects an unchanged save from the locked snapshot
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
        ?string $summary,
        ?string $content,
        ?string $pageTitle,
        ?string $metaDescription,
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
            || $this->nullableText($existingTranslation['summary'] ?? null) !== $summary
            || $this->nullableText($existingTranslation['content'] ?? null) !== $content
            || $this->nullableText($existingTranslation['page_title'] ?? null) !== $pageTitle
            || $this->nullableText($existingTranslation['meta_description'] ?? null) !== $metaDescription
            || (int) $existingTranslation['publication_state'] !== $publicationState->value
            || $this->nullableText($existingTranslation['published_at'] ?? null) !== $publishedAt
        ) {
            return false;
        }

        $currentPath = $this->pages->routePath($pageId, $locale);
        if ($currentPath === null && ($title !== '' || $publicationState === CmsPublicationState::Published)) {
            return false;
        }

        return true;
    }

    /**
     * Returns the required local user ID of the audit actor
     */
    private function actorUserId(AuditActor $actor): int
    {
        $userId = $actor->userId();
        if ($userId === null) {
            throw new RuntimeException('Page mutations require a local user actor.');
        }

        return $userId;
    }
}
