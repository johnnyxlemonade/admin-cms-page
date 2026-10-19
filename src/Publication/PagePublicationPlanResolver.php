<?php

declare(strict_types=1);

namespace Lemonade\Cms\Page\Publication;

use DateTimeImmutable;
use Lemonade\Cms\CmsPublicationState;
use RuntimeException;

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
        CmsPublicationState $requestedState,
        string $publishedAt,
        ?array $existing,
        string $now,
    ): PagePublicationPlan {
        $state = $requestedState === CmsPublicationState::New ? CmsPublicationState::Draft : $requestedState;
        $currentState = CmsPublicationState::tryFrom((int) ($existing['publication_state'] ?? CmsPublicationState::Draft->value))
            ?? CmsPublicationState::Draft;
        $currentPublishedAt = $existing['published_at'] ?? null;
        $currentPublishedAt = is_string($currentPublishedAt) && $currentPublishedAt !== '' ? $currentPublishedAt : null;
        $publishedAt = trim($publishedAt);
        $resolvedPublishedAt = null;
        if ($state === CmsPublicationState::Published) {
            $resolvedPublishedAt = $publishedAt === ''
                ? ($currentState === CmsPublicationState::Published ? $currentPublishedAt ?? $now : $now)
                : ($publishedAt === $currentPublishedAt ? $currentPublishedAt : $this->publicationDateTime($publishedAt));
        }
        $publicationChanged = $state !== $currentState || $resolvedPublishedAt !== $currentPublishedAt;

        return new PagePublicationPlan(
            state: $state,
            publishedAt: $resolvedPublishedAt,
            changed: $publicationChanged,
            requiresPermission: $publicationChanged
                && ($state === CmsPublicationState::Published || $currentState === CmsPublicationState::Published),
        );
    }

    /**
     * Overi a prevede browser datetime-local hodnotu
     */
    private function publicationDateTime(string $value): string
    {
        $datetime = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', trim($value));
        if ($datetime === false || $datetime->format('Y-m-d\TH:i') !== trim($value)) {
            throw new RuntimeException('page.validation.published_at_invalid');
        }

        return $datetime->format('Y-m-d H:i:s');
    }
}
