<?php

declare(strict_types=1);

namespace Lemonade\Cms\Page\Publication;

use InvalidArgumentException;
use Lemonade\Cms\CmsPublicationChange;
use Lemonade\Cms\CmsPublicationState;
use Lemonade\Cms\Content\CmsContentValidationException;

/**
 * Zachovava publication metadata a rozpoznava zmenu vyzadujici publish opravneni
 */
final readonly class PagePublicationPlanResolver
{
    /**
     * Vyhodnoti pozadovany stav proti existujici lokalizovane mutation
     *
     * @param array<string,mixed>|null $existing
     */
    public function resolve(
        ?CmsPublicationState $requestedState,
        string $publishedAt,
        ?array $existing,
        string $now,
    ): PagePublicationPlan {
        $currentState = (int) ($existing['publication_state'] ?? CmsPublicationState::New->value);
        try {
            [$state, $resolvedPublishedAt, $publicationChanged] = CmsPublicationChange::resolve(
                $requestedState !== null,
                $requestedState?->value,
                $publishedAt,
                $currentState,
                $existing['published_at'] ?? null,
                $now,
            );
        } catch (InvalidArgumentException $exception) {
            throw new CmsContentValidationException(str_contains($exception->getMessage(), 'date')
                ? ['published_at' => 'page.validation.published_at_invalid']
                : ['publication_state' => 'page.validation.publication_invalid']);
        }

        return new PagePublicationPlan(
            state: $state,
            publishedAt: $resolvedPublishedAt,
            changed: $publicationChanged,
            requiresPublicationEvent: $publicationChanged
                && ($state === CmsPublicationState::Published || $currentState === CmsPublicationState::Published->value),
        );
    }
}
