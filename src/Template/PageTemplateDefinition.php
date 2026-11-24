<?php

declare(strict_types=1);

namespace Lemonade\Cms\Page\Template;

use InvalidArgumentException;

/**
 * Describes one page template offered to editors
 */
final readonly class PageTemplateDefinition
{
    /**
     * Creates a template with its stable code and translation key
     */
    public function __construct(
        private string $key,
        private string $labelKey,
    ) {
        if (preg_match('/^[a-z][a-z0-9._-]*$/D', $this->key) !== 1 || trim($this->labelKey) === '') {
            throw new InvalidArgumentException('Page template definition is invalid.');
        }
    }

    /**
     * Returns the stable code stored with a page
     */
    public function getKey(): string
    {
        return $this->key;
    }

    /**
     * Returns the template label translation key
     */
    public function getLabelKey(): string
    {
        return $this->labelKey;
    }
}
