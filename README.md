# Lemonade CMS Page

[![Tests](https://img.shields.io/github/actions/workflow/status/johnnyxlemonade/admin-cms-page/phpunit.yml?branch=main&label=Tests&logo=githubactions&logoColor=white&labelColor=0f766e)](https://github.com/johnnyxlemonade/admin-cms-page/actions/workflows/phpunit.yml)
[![PHP 8.3–8.6](https://img.shields.io/badge/PHP-8.3%20%7C%208.4%20%7C%208.5%20%7C%208.6-777BB4?logo=php&logoColor=white)](#requirements)
[![PHPStan](https://img.shields.io/github/actions/workflow/status/johnnyxlemonade/admin-cms-page/phpstan.yml?branch=main&label=PHPStan&logo=php&logoColor=white&labelColor=5b4baf)](https://github.com/johnnyxlemonade/admin-cms-page/actions/workflows/phpstan.yml)
[![License](https://img.shields.io/badge/license-Apache--2.0-green.svg)](LICENSE)

`johnnyxlemonade/admin-cms-page` is the `cms.page` module for
hierarchical, localized website pages. It contributes the Pages DataGrid and
full-page editor whenever its Composer package is present.

The page root owns stable identity, global hierarchy, sibling order, and a host
template key. Translations own content, SEO fields, and draft/publish state.
Canonical localized paths are reserved only through the shared `cms_route`
contract; this package has no slug column or public route resolver of its own.

## Host contract

The package registers `PageTemplateProviderInterface` and always offers the
`default` template (General page). New drafts use this template. A host can
add templates by tagging `PageTemplateDefinition` services in its service
provider; it does not need to bind the interface or register `default` again:

```php
use Lemonade\Cms\Page\Template\PageTemplateDefinition;
use Lemonade\Framework\Container\ContainerBuilderInterface;
use Lemonade\Framework\Core\ServiceProviderInterface;

/**
 * Registruje vlastni sablony stranek host aplikace
 */
final readonly class PageTemplatesServiceProvider implements ServiceProviderInterface
{
    /**
     * Prida sablonu uvodni stranky do nabidky balicku
     */
    public function register(ContainerBuilderInterface $container): void
    {
        $container->singletonTagged(
            'app.page.template.landing',
            new PageTemplateDefinition('landing', 'page_template.landing'),
            PageTemplateDefinition::class,
        );
    }
}
```

Add this provider to the host's configured providers. An existing host provider
should remove its `PageTemplateProviderInterface` binding and register each
custom definition as a tagged service; its public renderer can stay in place.
The host supplies the `page_template.landing` translation and owns public
rendering for the template keys it uses. Duplicate template codes
are rejected. Stored keys are never rewritten when a host removes a template:
the Admin editor and grid show the unavailable key, and the existing save
validation continues to apply. Template definitions expose `getKey()` and
`getLabelKey()`. The package does not render public pages or own the homepage.

## Public page reading

`PublishedPageReadModel` is registered by the package. It returns only pages in
an enabled locale with a published translation, an active canonical route, and
an entirely visible ancestor chain. Soft-deleted pages, future publication
times and invalid hierarchies are excluded. Template codes remain available to
the host even when its public renderer does not support them.

```php
use Lemonade\Cms\Page\Public\PublishedPageReadModel;
use Lemonade\Cms\Routing\Cms\CmsRoute;
use Lemonade\Cms\Routing\Locale\PublicLocaleResolution;

function pageData(
    PublishedPageReadModel $pages,
    CmsRoute $route,
    PublicLocaleResolution $locale,
): ?array {
    $page = $pages->record($route->getEntityId(), $locale);
    if ($page === null) {
        return null;
    }

    return [
        'page' => $page,
        'parent' => $pages->parent($page, $locale),
        'children' => $pages->children($page->getId(), $locale),
        'ancestors' => $pages->ancestors($page, $locale),
        'languageAlternatives' => $pages->languageAlternatives($page->getId(), $locale),
        'thumbnail' => $page->getThumbnail(),
        'gallery' => $page->getGallery(),
        'attachments' => $page->getAttachments(),
        'matchingPages' => $pages->search($locale, 'archive', limit: 20),
    ];
}
```

`children(null, $locale)` returns root pages. `PublishedPage` also exposes the
system `summary` and `content`, SEO fields, template code, publication and
update times, and its localized canonical path through typed getters. The host
can turn this path into an absolute URL and render its own HTML and media URLs.
Files use the shared `FileMetadata` contract; downloads still require the
host's public attachment access check.
`search()` matches a published page's title, summary or content in the database,
then applies the same locale, canonical route and complete ancestor visibility
rules as `record()`. It returns at most the requested number of typed pages with
media loaded in one batch.

The host selects locales and templates in its framework sitemap provider. The
framework resolves the base URL and writes the XML:

```php
use Lemonade\Admin\Localization\LanguageRegistry;
use Lemonade\Cms\Page\Public\PublishedPageReadModel;
use Lemonade\Cms\Routing\Locale\PublicLocaleResolution;
use Lemonade\Framework\Discovery\Sitemap\SitemapProviderInterface;
use Lemonade\Framework\Discovery\Sitemap\SitemapUrl;

/**
 * Supplies host-rendered CMS pages to the framework sitemap
 */
final readonly class PublicPageSitemapProvider implements SitemapProviderInterface
{
    /**
     * Receives the public page reader and configured languages
     */
    public function __construct(
        private PublishedPageReadModel $pages,
        private LanguageRegistry $languages,
    ) {}

    /**
     * Yields sitemap URLs for templates rendered by this host
     *
     * @return iterable<SitemapUrl>
     */
    public function urls(): iterable
    {
        $defaultLocale = $this->languages->publicLocaleSnapshot()->getDefaultLocale();
        foreach ($this->languages->enabledLocales() as $language) {
            $locale = PublicLocaleResolution::route($defaultLocale, $language['code'], '');
            foreach ($this->pages->sitemapEntries($locale) as $entry) {
                if (!in_array($entry->getTemplateKey(), ['default', 'article'], true)) {
                    continue;
                }

                yield SitemapUrl::create($entry->getPublicUrl(), $entry->getUpdatedAt());
            }
        }
    }
}
```

Register this provider under `config.sitemap.providers` in the host's
`Discovery.yaml`.
`getUpdatedAt()` is the latest change to the page, its translation or canonical
route. The candidate query uses one narrow projection for the locale and checks
every ancestor; it does not load page bodies or media.

`isPublicInAnyLocale($pageId)` supports public attachment owner checks; hosts
may also require a template their renderer supports. Sitemap candidates and
language alternatives never expose an unpublished ancestor.

## Requirements

- PHP 8.3 or newer, tested on PHP 8.3, 8.4, 8.5 and 8.6.
- `johnnyxlemonade/framework:dev-main`.
- `johnnyxlemonade/admin-platform:dev-main`.
- `johnnyxlemonade/cms-core:dev-main`.

## Installation

```bash
composer require johnnyxlemonade/admin-cms-page:dev-main
```

Composer metadata discovers and activates the module. Run the host application's
controlled migration and permission-sync deployment flow before serving requests.

## Development and QA

From the package root, run:

```bash
composer cs:check
composer stan
composer test
composer qa
```
