<?php

declare(strict_types=1);

namespace Lemonade\Cms\Page\Public;

/**
 * Provides one available language and its canonical page URL
 */
final readonly class PublishedPageLanguageAlternative
{
    /**
     * Creates a visible language alternative
     */
    public function __construct(
        private string $locale,
        private string $publicUrl,
    ) {}

    /**
     * Returns the content locale
     */
    public function getLocale(): string
    {
        return $this->locale;
    }

    /**
     * Returns the localized canonical URL path
     */
    public function getPublicUrl(): string
    {
        return $this->publicUrl;
    }
}
