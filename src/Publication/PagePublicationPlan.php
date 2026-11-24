<?php

declare(strict_types=1);

namespace Lemonade\Cms\Page\Publication;

use Lemonade\Cms\CmsPublicationState;

/**
 * Describes the publication state resolved for one page translation
 */
final readonly class PagePublicationPlan
{
    /**
     * Creates the resolved publication state and change indicators
     */
    public function __construct(
        private CmsPublicationState $state,
        private ?string $publishedAt,
        private bool $changed,
        private bool $requiresPublicationEvent,
    ) {}

    /**
     * Returns the resulting publication state
     */
    public function getState(): CmsPublicationState
    {
        return $this->state;
    }

    /**
     * Returns the resolved publication time
     */
    public function getPublishedAt(): ?string
    {
        return $this->publishedAt;
    }

    /**
     * Reports whether publication metadata changed
     */
    public function isChanged(): bool
    {
        return $this->changed;
    }

    /**
     * Reports whether the change needs a publication audit event
     */
    public function requiresPublicationEvent(): bool
    {
        return $this->requiresPublicationEvent;
    }
}
