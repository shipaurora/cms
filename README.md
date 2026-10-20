# Aurora

[![Latest version](https://img.shields.io/packagist/v/shipaurora/cms)](https://packagist.org/packages/shipaurora/cms)
[![Downloads](https://img.shields.io/packagist/dt/shipaurora/cms)](https://packagist.org/packages/shipaurora/cms)
[![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-777BB4?logo=php&logoColor=white)](https://www.php.net)
[![Laravel 13](https://img.shields.io/badge/Laravel-13-FF2D20?logo=laravel&logoColor=white)](https://laravel.com)
[![Filament 5](https://img.shields.io/badge/Filament-5-FDAE4B)](https://filamentphp.com)
[![License: MIT](https://img.shields.io/packagist/l/shipaurora/cms)](LICENSE)

**A content management system for Laravel, built on [Filament](https://filamentphp.com).** Editors
compose pages visually on the real design of the website. Developers keep plain Laravel: Eloquent
models, Blade components, service providers and tests. AI assistants work on the same content with
exactly the permissions of the person using them.

<picture>
    <source media="(prefers-color-scheme: light)" srcset="https://raw.githubusercontent.com/shipaurora/borealis-theme/main/resources/preview-light.webp">
    <img src="https://raw.githubusercontent.com/shipaurora/borealis-theme/main/resources/preview-dark.webp" alt="Aurora's dashboard" width="960">
</picture>

## Features

- **A visual editor on the real design.** Pages are built from blocks on a canvas that renders the
  website's own Blade components and stylesheet. Editors type straight onto the page, drag blocks
  into columns, and switch between desktop, tablet and mobile.
- **Structured content.** Every block is a PHP class with a Filament form, defaults and validation.
  Pages store blocks as validated JSON, so the same content serves the website, the REST and
  GraphQL APIs and AI assistants.
- **A safe publishing workflow.** Drafts, review, scheduled publishing, and revisions with
  one-click restore. Changes to a live page wait until someone publishes them, and edit locks stop
  two people from overwriting each other.
- **AI that respects permissions.** An MCP server lets Claude and other AI apps read and edit the
  CMS as the signed-in person, over OAuth. The same tools power an assistant inside the admin, and
  every AI action is logged.
- **Everything else a site needs.** A media library, a form builder, menus, redirects and a trash.
  Users with passkeys, two-factor sign-in and roles. Several languages and several websites from
  one admin. Search, SEO checks, a page cache and webhooks.
- **Just Laravel.** Your website is a Laravel app, and the CMS is a package in it that updates with
  `composer update`.

## Requirements

- PHP 8.3 or newer, with the GD or Imagick extension
- Laravel 13
- A database: SQLite, MySQL/MariaDB or PostgreSQL

## Installation

The quickest start is the starter app, a Laravel application with Aurora already set up:

```bash
composer create-project shipaurora/aurora my-site   # ends by running aurora:install
cd my-site && npm install && npm run build
```

To add Aurora to an existing Laravel application:

```bash
composer require shipaurora/cms
php artisan aurora:install
```

`aurora:install` sets up the database and what the CMS needs, publishes the admin's assets and the
page layouts, and creates your admin account. The starter app runs it for you at the end of
`create-project`. Then sign in at `/admin`.

Aurora comes with its default admin theme, [Borealis](https://github.com/shipaurora/borealis-theme).
[Minimal](https://github.com/shipaurora/minimal-theme) is a second one to choose from.

## Usage

```bash
php artisan aurora:make-block "Pricing table"   # a block of the website's own
php artisan make:aurora-user                     # a person, with a role
```

Blocks live in the app (`app/Blocks`), with their views in `resources/views/components/blocks`.
The page layouts are published to `resources/views/vendor/aurora/layouts`, to make your own.

## Documentation

The documentation is at [shipaurora.com/docs](https://shipaurora.com/docs). It covers the visual
editor, blocks, layouts, users and roles, languages, AI, the APIs and configuration.

## License

Aurora is open-source software licensed under the [MIT license](LICENSE).
