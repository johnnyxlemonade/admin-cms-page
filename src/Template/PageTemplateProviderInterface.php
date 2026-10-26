<?php

declare(strict_types=1);

namespace Lemonade\Cms\Page\Template;

/**
 * Poskytuje hostem podporovane sablony pro editor a validaci stranek
 */
interface PageTemplateProviderInterface
{
    /**
     * Vrati hostem podporovane sablony v poradi pro novy draft
     *
     * @return non-empty-list<PageTemplateDefinition>
     */
    public function templates(): array;
}
