<?php

declare(strict_types=1);

namespace Lemonade\Cms\Page\Template;

/**
 * Provides the page templates available for editing and validation
 */
interface PageTemplateProviderInterface
{
    /**
     * Returns available page templates in display order
     *
     * @return non-empty-list<PageTemplateDefinition>
     */
    public function templates(): array;
}
