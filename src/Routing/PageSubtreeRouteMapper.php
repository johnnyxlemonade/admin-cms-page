<?php

declare(strict_types=1);

namespace Lemonade\Cms\Page\Routing;

use DomainException;

/**
 * Prepocita existujici lokalizovane routy celeho podstromu pod novy prefix
 */
final readonly class PageSubtreeRouteMapper
{
    /**
     * @param non-empty-list<array{entity_id:int,locale:string,path:string}> $routes
     * @param array<string,array{old:string,new:string}> $prefixes
     * @return non-empty-list<array{entityId:int,locale:string,path:string}>
     */
    public function replacements(array $routes, array $prefixes): array
    {
        $replacements = [];
        foreach ($routes as $route) {
            $prefix = $prefixes[$route['locale']] ?? null;
            if ($prefix === null
                || ($route['path'] !== $prefix['old'] && !str_starts_with($route['path'], $prefix['old'] . '/'))
            ) {
                throw new DomainException('page.validation.route_inconsistent');
            }
            $replacements[] = [
                'entityId' => $route['entity_id'],
                'locale' => $route['locale'],
                'path' => $prefix['new'] . substr($route['path'], strlen($prefix['old'])),
            ];
        }

        return $replacements;
    }
}
