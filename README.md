# Lemonade CMS Page

`johnnyxlemonade/admin-cms-page` is the `cms.page` module for
hierarchical, localized website pages. It contributes the Pages DataGrid and
full-page editor whenever its Composer package is present.

The page root owns stable identity, global hierarchy, sibling order, and a host
template key. Translations own content, SEO fields, and draft/publish state.
Canonical localized paths are reserved only through the shared `cms_route`
contract; this package has no slug column or public route resolver of its own.

## Host contract

The host application binds `PageTemplateProviderInterface` and advertises at
least one `PageTemplateDefinition` with a host-owned translation key. The
package uses those definitions for the initial draft template, editor options,
and save validation. The host owns public read models, conversion into its
presentation DTO, and all public rendering. The package deliberately contains
no public query, presentation DTO, News query, page builder, or homepage
ownership.

## Installation

The package requires PHP `>=8.3 <8.6`, `johnnyxlemonade/framework`,
`johnnyxlemonade/admin-platform`, and `johnnyxlemonade/cms-core`.

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
