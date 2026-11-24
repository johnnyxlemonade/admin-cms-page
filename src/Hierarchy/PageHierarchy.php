<?php

declare(strict_types=1);

namespace Lemonade\Cms\Page\Hierarchy;

use Lemonade\Cms\Content\CmsContentValidationException;

/**
 * Validates hierarchy invariants over one page snapshot
 */
final readonly class PageHierarchy
{
    /**
     * @var array<int,int|null>
     */
    private array $parents;

    /**
     * @var array<int,list<int>>
     */
    private array $children;

    /**
     * Indexes parents and children from active pages
     *
     * @param list<array{id:int,parent_id:int|null,sort_order?:int}> $pages
     */
    public function __construct(array $pages)
    {
        $parents = [];
        $children = [];
        $sortOrders = [];
        foreach ($pages as $page) {
            $id = (int) $page['id'];
            $parentId = $page['parent_id'] === null ? null : (int) $page['parent_id'];
            $parents[$id] = $parentId;
            $sortOrders[$id] = (int) ($page['sort_order'] ?? 0);
            $children[$parentId ?? 0][] = $id;
        }
        foreach ($children as &$siblings) {
            usort(
                $siblings,
                static fn(int $left, int $right): int => [
                    $sortOrders[$left],
                    $left,
                ] <=> [
                    $sortOrders[$right],
                    $right,
                ],
            );
        }
        unset($siblings);

        $this->parents = $parents;
        $this->children = $children;
    }

    /**
     * Rejects a missing parent, self-parenting and cycles
     */
    public function assertMove(int $pageId, ?int $parentId): void
    {
        if (!array_key_exists($pageId, $this->parents)) {
            throw new \DomainException('page.validation.not_found');
        }
        if ($parentId === null) {
            return;
        }
        if (!array_key_exists($parentId, $this->parents)) {
            throw new CmsContentValidationException(['parent_id' => 'page.validation.parent_invalid']);
        }
        $this->ancestorIds($parentId);
        if ($parentId === $pageId || in_array($parentId, $this->descendantIds($pageId), true)) {
            throw new CmsContentValidationException(['parent_id' => 'page.validation.parent_cycle']);
        }
    }

    /**
     * Returns a page and its descendants in preorder
     *
     * @return list<int>
     */
    public function descendantIds(int $pageId): array
    {
        if (!array_key_exists($pageId, $this->parents)) {
            return [];
        }
        $result = [];
        $visited = [];
        $visit = function (int $id) use (&$visit, &$result, &$visited): void {
            if (isset($visited[$id])) {
                throw new CmsContentValidationException(['parent_id' => 'page.validation.parent_cycle']);
            }
            $visited[$id] = true;
            $result[] = $id;
            foreach ($this->children[$id] ?? [] as $childId) {
                $visit($childId);
            }
        };
        $visit($pageId);

        return $result;
    }

    /**
     * Returns ancestors from the direct parent to the root
     *
     * @return list<int>
     */
    public function ancestorIds(int $pageId): array
    {
        if (!array_key_exists($pageId, $this->parents)) {
            return [];
        }
        $ancestors = [];
        $visited = [$pageId => true];
        $parentId = $this->parents[$pageId];
        while ($parentId !== null) {
            if (isset($visited[$parentId])) {
                throw new CmsContentValidationException(['parent_id' => 'page.validation.parent_cycle']);
            }
            $visited[$parentId] = true;
            $ancestors[] = $parentId;
            $parentId = $this->parents[$parentId] ?? null;
        }

        return $ancestors;
    }

    /**
     * Returns the page depth in the hierarchy
     */
    public function depth(int $pageId): int
    {
        return count($this->ancestorIds($pageId));
    }

    /**
     * Returns the forest in preorder including orphaned nodes
     *
     * @return list<int>
     */
    public function preorder(): array
    {
        $result = [];
        foreach ($this->children[0] ?? [] as $rootId) {
            $result = [...$result, ...$this->descendantIds($rootId)];
        }
        foreach (array_keys($this->parents) as $id) {
            if (!in_array($id, $result, true)) {
                $result[] = $id;
            }
        }

        return $result;
    }
}
