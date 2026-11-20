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

The host application binds `PageTemplateProviderInterface` and advertises at
least one `PageTemplateDefinition` with a host-owned translation key. The
package uses those definitions for the initial draft template, editor options,
and save validation. The host owns public read models, conversion into its
presentation DTO, and all public rendering. The package deliberately contains
no public query, presentation DTO, News query, page builder, or homepage
ownership.

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
