<?php

declare(strict_types=1);

namespace Lemonade\Cms\Page\Template;

use Lemonade\Cms\Page\PublicPage;
use Lemonade\Cms\Routing\Cms\CmsRoute;
use Lemonade\Cms\Routing\Locale\PublicLocaleResolution;
use Lemonade\Framework\View\ViewRendererInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Predava publikovanou stranku jedinemu host adapteru sablon
 */
interface PageTemplateRendererInterface
{
    /**
     * Vrati hostem podporovane sablony v poradi pro novy draft
     *
     * @return non-empty-list<PageTemplateDefinition>
     */
    public function templates(): array;

    /**
     * Vyrenderuje podporovanou sablonu nebo vrati null pro nedostupny klic
     */
    public function render(
        PublicPage $page,
        PublicLocaleResolution $locale,
        CmsRoute $route,
        ViewRendererInterface $views,
    ): ?ResponseInterface;
}
