<?php

declare(strict_types=1);

namespace Lemonade\Cms\Page;

use Lemonade\Admin\Localization\LanguageRegistry;
use Lemonade\Cms\Page\Models\PageModel;
use Lemonade\Cms\Page\Template\PageTemplateRendererInterface;
use Lemonade\Cms\Routing\Cms\CmsRoute;
use Lemonade\Cms\Routing\Cms\PublicCmsAttachmentAccessInterface;
use Lemonade\Cms\Routing\Cms\PublicCmsRouteHandlerInterface;
use Lemonade\Cms\Routing\Locale\PublicLocaleResolution;
use Lemonade\Framework\View\ViewRendererInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Predava jiz vyhodnocenou CMS route publikovane stranky host rendereru
 */
final readonly class PublicPageRouteHandler implements PublicCmsRouteHandlerInterface, PublicCmsAttachmentAccessInterface
{
    /**
     * Nastavuje publikovanou read projection a host template adapter
     */
    public function __construct(
        private PageModel $pages,
        private PageTemplateRendererInterface $templates,
        private LanguageRegistry $languages,
    ) {}

    /**
     * Vyrenderuje publikovanou stranku pro jiz vyhodnocenou canonical route
     */
    public function handle(
        int $entityId,
        PublicLocaleResolution $locale,
        CmsRoute $route,
        ViewRendererInterface $views,
    ): ?ResponseInterface {
        $resolvedLocale = $locale->locale();
        if ($resolvedLocale === null || $resolvedLocale !== $route->locale()) {
            return null;
        }
        $page = $this->pages->publishedPage($entityId, $resolvedLocale, date('Y-m-d H:i:s'));
        if ($page === null) {
            return null;
        }

        return $this->templates->render($page, $locale, $route, $views);
    }

    /**
     * Povoli verejny soubor jen publikovane strance s dostupnou host sablonou
     */
    public function isPublicAttachmentOwner(int $entityId): bool
    {
        $now = date('Y-m-d H:i:s');
        foreach ($this->languages->enabledLocales() as $language) {
            $page = $this->pages->publishedPage($entityId, $language['code'], $now);
            if ($page !== null && $this->templateIsAvailable($page->templateKey())) {
                return true;
            }
        }

        return false;
    }

    /**
     * Overi ulozeny template klic proti aktualni nabidce host adapteru
     */
    private function templateIsAvailable(string $templateKey): bool
    {
        foreach ($this->templates->templates() as $template) {
            if ($template->key() === $templateKey) {
                return true;
            }
        }

        return false;
    }
}
