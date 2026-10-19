<?php

declare(strict_types=1);

namespace Lemonade\Cms\Page\Publication;

use Lemonade\Cms\CmsPublicationState;

/**
 * Popisuje vysledna publication metadata jedne lokalizace
 */
final readonly class PagePublicationPlan
{
    /**
     * Nastavuje vysledny stav, datum a pozadavek na publish opravneni
     */
    public function __construct(
        public CmsPublicationState $state,
        public ?string $publishedAt,
        public bool $changed,
        public bool $requiresPermission,
    ) {}
}
