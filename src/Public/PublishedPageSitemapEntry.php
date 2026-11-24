<?php

declare(strict_types=1);

namespace Lemonade\Cms\Page\Public;

use DateTimeImmutable;

/**
 * Provides a visible canonical page candidate for a host sitemap
 */
final readonly class PublishedPageSitemapEntry
{
    /**
     * Creates a sitemap candidate with its host template and update time
     */
    public function __construct(
        private string $publicUrl,
        private string $templateKey,
        private DateTimeImmutable $updatedAt,
    ) {}

    /**
     * Returns the localized canonical URL path
     */
    public function getPublicUrl(): string
    {
        return $this->publicUrl;
    }

    /**
     * Returns the host-interpreted template code
     */
    public function getTemplateKey(): string
    {
        return $this->templateKey;
    }

    /**
     * Returns the latest change to the page, translation or canonical route
     */
    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
