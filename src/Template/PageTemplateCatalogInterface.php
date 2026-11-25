<?php

declare(strict_types=1);

namespace Lemonade\Cms\Page\Template;

/**
 * Contributes page templates supported by one host application
 */
interface PageTemplateCatalogInterface
{
    /**
     * Returns host-supported page templates in display order
     *
     * @return list<PageTemplateDefinition>
     */
    public function templates(): array;
}
