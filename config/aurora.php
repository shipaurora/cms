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
    | Empty: Borealis, Aurora's own (shipaurora/borealis).
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
    | Layouts
    |--------------------------------------------------------------------------
    |
    | Where the site's page layouts defined in code live (Aurora\Layouts\
    | PageLayout). Every layout class in this folder is offered to editors on
    | its own, with the layouts added in the admin. php artisan
    | make:aurora-layout creates new ones here. Set the path to null to
    | register layouts by hand (Aurora\Support\Layouts::register()).
    |
    */

    'layout_path' => app_path('Layouts'),

    'layout_namespace' => null,

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

    /*
    |--------------------------------------------------------------------------
    | Media library
    |--------------------------------------------------------------------------
    |
    | The largest file the media library takes, in megabytes: images, and
    | everything else (videos, PDFs). Images are resized into several sizes,
    | so keep theirs modest. PHP's upload_max_filesize and post_max_size, and
    | the web server's own limit (client_max_body_size on nginx), must allow
    | as much, or larger uploads fail before they reach Aurora.
    |
    */

    'media' => [
        'max_image_mb' => env('AURORA_MAX_IMAGE_MB', 10),
        'max_file_mb' => env('AURORA_MAX_FILE_MB', 50),
    ],

    /*
    |--------------------------------------------------------------------------
    | AI apps (MCP)
    |--------------------------------------------------------------------------
    |
    | The account the local MCP server acts as (php artisan mcp:start cms),
    | and the public address Claude's custom connectors reach the CMS on:
    | they connect from Anthropic's cloud, so a .test domain won't do (use a
    | tunnel such as `herd share` in development). An app's own
    | services.mcp settings win over these.
    |
    */

    'mcp' => [
        'local_user' => env('MCP_USER'),
        'public_url' => env('MCP_PUBLIC_URL'),
    ],

];
