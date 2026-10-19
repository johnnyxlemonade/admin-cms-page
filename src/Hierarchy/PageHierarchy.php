<?php

declare(strict_types=1);

namespace Lemonade\Cms\Page\Hierarchy;

use RuntimeException;

/**
 * Vyhodnocuje stromove invarianty nad jednim snapshotem stranek
 */
final class PageHierarchy
{
    /**
     * @var array<int,int|null>
     */
    private array $parents = [];

    /**
     * @var array<int,list<int>>
     */
    private array $children = [];

    /**
     * @var array<int,int>
     */
    private array $sortOrders = [];

    /**
     * Sestavi index rodicu a deti z aktivnich stranek
     *
     * @param list<array{id:int,parent_id:int|null,sort_order?:int}> $pages
     */
    public function __construct(array $pages)
    {
        foreach ($pages as $page) {
            $id = (int) $page['id'];
            $parentId = $page['parent_id'] === null ? null : (int) $page['parent_id'];
            $this->parents[$id] = $parentId;
            $this->sortOrders[$id] = (int) ($page['sort_order'] ?? 0);
            $this->children[$parentId ?? 0][] = $id;
        }
        foreach ($this->children as &$children) {
            usort(
                $children,
                fn(int $left, int $right): int => [
                    $this->sortOrders[$left],
                    $left,
                ] <=> [
                    $this->sortOrders[$right],
                    $right,
                ],
            );
        }
        unset($children);
    }

    /**
     * Odmitne neexistujiciho rodice, sebe sama a cyklus
     */
    public function assertMove(int $pageId, ?int $parentId): void
    {
        if (!array_key_exists($pageId, $this->parents)) {
            throw new RuntimeException('page.validation.not_found');
        }
        if ($parentId === null) {
            return;
        }
        if (!array_key_exists($parentId, $this->parents)) {
            throw new RuntimeException('page.validation.parent_invalid');
        }
        $this->ancestorIds($parentId);
        if ($parentId === $pageId || in_array($parentId, $this->descendantIds($pageId), true)) {
            throw new RuntimeException('page.validation.parent_cycle');
        }
    }

    /**
     * Vrati stranku a vsechny potomky v preorder poradi
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
                throw new RuntimeException('page.validation.parent_cycle');
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
     * Vrati predky stranky od primeho rodice ke koreni
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
                throw new RuntimeException('page.validation.parent_cycle');
            }
            $visited[$parentId] = true;
            $ancestors[] = $parentId;
            $parentId = $this->parents[$parentId] ?? null;
        }

        return $ancestors;
    }

    /**
     * Vrati uroven zanoreni stranky v lese
     */
    public function depth(int $pageId): int
    {
        return count($this->ancestorIds($pageId));
    }

    /**
     * Vrati cely les v preorder poradi a doplni pripadne osirele uzly
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
