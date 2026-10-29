# A shop: what to offer, and how each fits Aurora

Aurora has no shop. When the old site sells things (Netflex Commerce, Statamic Cargo or Simple Commerce, WooCommerce, a cart of its own), list what it uses, with counts: products and variants, stock, orders, payment methods (Vipps MobilePay and Klarna, in Norway), shipping, discounts, customer accounts, gift cards. Then ask the user which of the options below it becomes. Don't build any of it before they've chosen.

## The options

Checked in October 2026; versions move, so check what installs before promising one (next section).

| Option | Fits | Installs beside Aurora | In Aurora's admin |
| --- | --- | --- | --- |
| **Shopify**, the website on Aurora | A real shop, now. The default recommendation | Nothing to install: its APIs | A thin layer: a product cache to pick products in blocks, recent orders read-only, links into Shopify's admin for the rest |
| **Keep the old shop** on its own address | A shop that works and isn't part of the brief | — | Links in menus and blocks |
| **Vanilo** (`vanilo/framework` 6), a Laravel shop engine | A real shop the user wants in Aurora's admin, with the budget to build its screens | Yes | Resources of the site's own in a "Shop" cluster |
| **Lunar** (`lunarphp/core` + `lunarphp/filament`) | Where this is heading: a Filament-built engine whose forms and tables slot into Aurora's admin | Not yet: Lunar 2 (Filament 5) is in alpha and requires laravel-permission 6 | A "Shop" cluster built from its Filament schemas |
| **Shopper** (`shopper/core`) | Like Vanilo, lighter, once 3.0 is stable | 2.12's core does; 3.0's doesn't yet (laravel-permission 7, Scout 10); its admin doesn't at all | A "Shop" cluster of the site's own |
| **A small shop of the site's own** | A handful of products without variants or stock (tickets, a book, memberships) | — | A `Product` and an `Order` resource |

Don't offer: TomatoPHP's filament-ecommerce (bigint `users` keys where Aurora's are UUIDs; it adds plugins of its own for accounts and settings to the panel; eight packages of its own come with it; no cart or checkout), Aimeos (runs, but with an admin, storefront and users of its own: a second system, not part of Aurora), Bagisto (a whole application, not a package), AvoRed (unmaintained), and young Filament shop plugins without users.

## Check what installs

In the new site, before recommending an engine:

```bash
composer require lunarphp/core lunarphp/filament --dry-run -W
composer require shopper/core shopper/cart shopper/payment --dry-run -W
composer require vanilo/framework --dry-run -W
```

A dry run that resolves at a stable version (`composer show <package> -a`, no `alpha`, `beta` or `RC`) can be offered. For Lunar, also `gh pr view 2728 -R lunarphp/lunar --json state` (its laravel-permission 8 upgrade). Tell the user plainly when the one they hoped for doesn't install yet.

## Shopify, with the website on Aurora

Shopify keeps the products, stock, checkout, payments and orders; Aurora keeps the website, product pages included. Vipps MobilePay and Klarna are payment apps in Shopify, and Shopify Payments works in Norway.

- **Plan:** Basic at least (headless storefronts are included from Basic). A styled checkout beyond logo and colours needs Plus. Payment providers other than Shopify Payments cost a fee per sale on top (2 % on Basic).
- **Access:** an app made in Shopify's Dev Dashboard (custom apps can't be made in the store's admin since January 2026) with `read_products`, `read_orders`, `read_customers`. A store in our own Shopify organization gets tokens by client credentials, which expire after 24 hours; a client's store goes through OAuth for a lasting token. A Storefront API token from the Headless channel for the website. Keys in `.env`, `config/services.php`.
- **No SDK:** Laravel's `Http` client against the GraphQL endpoints (`https://{store}.myshopify.com/admin/api/{version}/graphql.json`, `…/api/{version}/graphql.json` for the Storefront API), the version pinned in config and moved every few quarters (each is supported for a year). `shopify/shopify-api` was archived in October 2026.
- **On the website:** product and collection blocks of the site's own, reading the Storefront API (cached), a product page per product on the site's own route (`/produkter/{handle}`, in the sitemap, with Product structured data), and a cart on the Cart API (`cartCreate`, `cartLinesAdd`, the cart id in the session) that sends people to Shopify's checkout (`cart.checkoutUrl`, fetched when they press the button). Put the checkout on a subdomain (`checkout.example.no`, set up in Shopify) and keep Shopify's own storefront closed, so products aren't found at two addresses. For a site with only a few products, Shopify's Storefront Web Components in a block are the low-code alternative.
- **In Aurora's admin (thin):** a `shopify_products` table kept current by Shopify's `products/create`, `products/update` and `products/delete` webhooks plus a nightly full sync (webhooks can arrive out of order or not at all), and a read-only resource of it under a "Shop" group whose products open in Shopify's admin to be edited. Blocks pick products from it (`Select` with search). Recent orders read-only, linking to Shopify. Never rebuild orders, refunds, stock or discounts: Shopify does them.
- **Webhooks:** verify `X-Shopify-Hmac-SHA256` (HMAC-SHA256 of the raw body with the app's secret), skip a `X-Shopify-Webhook-Id` already handled, answer within five seconds and do the work in a queued job.
- **Customers:** with a customer register of the site's own ([customers.md](customers.md)), `customers/create` and `customers/update` webhooks update it by email, with the newsletter consent Shopify recorded (`emailMarketingConsent`), never assumed.

## A shop engine inside Aurora (Vanilo now; Lunar or Shopper when they install)

The engine keeps the shop's data and rules; the admin screens are the site's own Filament resources in Aurora's panel.

- **A "Shop" cluster** (`class Shop extends Filament\Clusters\Cluster`, a Phosphor icon, `__('Shop')`), and a resource per model the shop needs (products, categories, orders, customers, discounts to start), each with `protected static ?string $cluster = Shop::class;`. Registered in a service provider's `register()` with `AdminPanel::discover(app_path('Filament/Shop'), 'App\Filament\Shop')`: the cluster is found as a page, and resources aren't scoped to a site. With Lunar, build the resources from `lunarphp/filament`'s forms and tables, and add its plugin's dashboard widgets with `AdminPanel::plugins([fn (): Plugin => LunarPlugin::make()])`; Vanilo and Shopper have none for Filament 5, so they're written from scratch.
- **Never the engine's own admin or panel:** Lunar's `LunarPanel::register()` makes a panel of its own, the default, with its own `Staff` login, and changes Filament's defaults everywhere; Shopper's admin is an application of its own with its own roles; Vanilo's admin is a separate Bootstrap app with its own users.
- **Users are Aurora's:** Aurora's users are the admin's editors, with UUID keys. Shop customers aren't Aurora users (Shopper keeps them in `users`: use its customer model on a table of its own instead), and the engine's migrations that point at `users` with whole-number columns (Vanilo's `carts.user_id` and `orders.user_id`, Shopper's, Lunar's unless `lunar.database.users_id_type` is `uuid`) must be changed to `foreignUuid` before they run: copy them into the app and stop the package's own (or mark them as run). Never run an engine's migrations that create `permissions`/`roles` tables or add columns to `users`: Aurora has its own.
- **Permissions:** policies on Aurora's roles (Users & roles), a permission per area ("Manage products", "Manage orders"), not the engine's roles.
- **Tables:** Lunar's and Shopper's are prefixed (`lunar_`, `sh_`); Vanilo's aren't (`products`, `orders`, `carts`, `customers`), so a customer register of the site's own is Vanilo's `customers` rather than a second table. Images in Aurora's media library (`AssetPicker`, asset ids on the product), not in the engine's media collections: Aurora's `media` table keys models by UUID.
- **Payments:** Vipps MobilePay and Klarna, which Norwegian shops expect, have no driver in any of these engines (Vanilo has Stripe, Mollie, PayPal, Adyen and Braintree; Shopper and Lunar Stripe, Lunar also PayPal): a driver of the site's own on the provider's hosted checkout (Vipps's ePayment API, Klarna's hosted payment page), confirmed by its webhook.
- **The website:** product, list, cart and checkout as blocks and routes of the site's own (`aurora-development`, "The website's own screens, models and routes"), on the engine's cart and order classes.
- Pin exact versions while a package is below a stable release, and read its upgrade notes before every update.

## A small shop of the site's own

A `Product` model and resource (`aurora-porting`, section 3), a product block, and payment on the provider's hosted checkout (Stripe Checkout, Vipps ePayment, Klarna), with an `Order` model filled from its webhook. Don't grow it into a shop engine: when it needs carts, variants, discounts or stock, it's time for one of the above.

## Bringing the shop across

Into whichever was chosen, with the site's import command (stable ids as for pages): products, variants, prices, stock and categories; images into Aurora's media library (or into Shopify, with Shopify). Orders usually stay in the old system as history, exported for the accounts: ask. Customers' passwords never come across. Old product and category addresses get redirects.
