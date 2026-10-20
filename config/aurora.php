<?php

use Aurora\Models\User;

return [

    /*
    |--------------------------------------------------------------------------
    | Product name
    |--------------------------------------------------------------------------
    |
    | The CMS's own name: in the admin panel's logo and browser title, on the
    | sign-in and invitation pages, and as the name AI apps connect to. Set
    | here rather than from APP_NAME, so it doesn't change between installs.
    | The website's own name is "Site name" under Settings.
    |
    */

    'name' => env('AURORA_NAME', 'Aurora CMS'),

    /*
    |--------------------------------------------------------------------------
    | User model
    |--------------------------------------------------------------------------
    |
    | Aurora owns the user model: the app's auth uses it. To add your own
    | behaviour, make a model that extends Aurora\Models\User and name it
    | here. User ids are UUIDs.
    |
    */

    'user_model' => User::class,

    /*
    |--------------------------------------------------------------------------
    | Demo mode
    |--------------------------------------------------------------------------
    |
    | One-click sign-in as the accounts the website registers with
    | Aurora\Support\Demo::register(), shown on the admin login page. For
    | presenting a site only: never enable this on a public site.
    |
    */

    'demo_login' => (bool) env('AURORA_DEMO_LOGIN', false),

    /*
    |--------------------------------------------------------------------------
    | Admin theme
    |--------------------------------------------------------------------------
    |
    | The admin's look until one is chosen under Settings › Appearance: the id
    | of a theme in Aurora's registry (Aurora\Support\Themes::register()).
    | Empty: Borealis, Aurora's own (shipaurora/borealis-theme).
    |
    */

    'theme' => env('AURORA_THEME'),

    /*
    |--------------------------------------------------------------------------
    | Website stylesheet
    |--------------------------------------------------------------------------
    |
    | The Vite entry of the website's CSS. The visual editor shows it on its
    | canvas, scoped there, so blocks look exactly as they do on the site
    | (style the page through :root, html and body, not classes on <body>).
    | Empty: the canvas shows blocks without the site's styles.
    |
    */

    'website_css' => env('AURORA_WEBSITE_CSS', 'resources/css/app.css'),

    /*
    |--------------------------------------------------------------------------
    | Blocks
    |--------------------------------------------------------------------------
    |
    | Where the site's page blocks live. Every block class in this folder is
    | registered on its own; list blocks with Aurora\Blocks\Blocks::register()
    | only to set their order in the palette. php artisan aurora:make-block
    | creates new ones here. The namespace is worked out from the folder
    | when it's inside app/. Set the path to null to register blocks by hand.
    |
    */

    'block_path' => app_path('Blocks'),

    'block_namespace' => null,

    /*
    |--------------------------------------------------------------------------
    | Website routes
    |--------------------------------------------------------------------------
    |
    | Aurora serves the website's pages (/, each page's address, previews,
    | the sitemap and robots.txt) after the app's own routes. Turn off to
    | route them yourself with Aurora\Http\Controllers\PageController.
    |
    */

    'routes' => env('AURORA_ROUTES', true),

    /*
    |--------------------------------------------------------------------------
    | Translations
    |--------------------------------------------------------------------------
    |
    | Where aurora:sync-translations adds new labels and
    | aurora:export-translations writes labels edited in the admin. Empty
    | means the app's own lang directory.
    |
    */

    'translations_path' => env('AURORA_TRANSLATIONS_PATH'),

];
