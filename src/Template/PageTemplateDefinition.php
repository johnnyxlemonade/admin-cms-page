<?php

declare(strict_types=1);

namespace Lemonade\Cms\Page\Template;

use InvalidArgumentException;

/**
 * Popisuje jednu hostem nabizenou sablonu stranky
 */
final readonly class PageTemplateDefinition
{
    /**
     * Nastavuje stabilni klic a prekladovy klic host sablony
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
     * Vrati stabilni klic ukladany u stranky
     */
    public function key(): string
    {
        return $this->key;
    }

    /**
     * Vrati prekladovy klic nazvu pro editor
     */
    public function labelKey(): string
    {
        return $this->labelKey;
    }
}
