# A shop: what to offer, and how each fits Aurora

Aurora has no shop. When the old site sells things (Netflex Commerce, Statamic Cargo or Simple Commerce, WooCommerce, a cart of its own), list what it uses, with counts: products and variants, stock, orders, payment methods (Vipps MobilePay and Klarna, in Norway), shipping, discounts, customer accounts, gift cards. Then ask the user which of the options below it becomes. Don't build any of it before they've chosen.

## The options

Checked in October 2026; versions move, so check what installs before promising one (next section).

| Option | Fits | Installs beside Aurora | In Aurora's admin |
| --- | --- | --- | --- |
| **Shopify, headless**: the shop served on the Aurora website | A real shop, now. The default recommendation | Nothing to install: its APIs | A thin layer: a product cache to pick products in blocks, recent orders read-only, links into Shopify's admin for the rest |
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

## Shopify, headless: the shop on the website

**Recommend it headless.** Shopify keeps the products, stock, checkout, payments and orders; the shop itself (product pages, collections, search, the cart) is served by the Aurora website, from Shopify's Storefront API. Not on Shopify's own storefront (a theme in its Online Store): that would be a second website, with a design of its own to keep in step, the same products at two addresses, editors working in two places, and the shop out of reach of Aurora's blocks, menus, translations and SEO. The one Shopify page visitors see is the checkout, on a subdomain of the site's own and in its colours. Say this to the user when offering Shopify, and build it headless unless they choose otherwise. Vipps MobilePay and Klarna are payment apps in Shopify, and Shopify Payments works in Norway.

- **Plan:** Basic at least (headless storefronts are included from Basic). A styled checkout beyond logo and colours needs Plus. Payment providers other than Shopify Payments cost a fee per sale on top (2 % on Basic).
- **Access:** an app made in Shopify's Dev Dashboard (custom apps can't be made in the store's admin since January 2026) with `read_products`, `read_orders`, `read_customers`. A store in our own Shopify organization gets tokens by client credentials, which expire after 24 hours; a client's store goes through OAuth for a lasting token. A Storefront API token from the Headless channel for the website. Keys in `.env`, `config/services.php`.
- **No SDK:** Laravel's `Http` client against the GraphQL endpoints (`https://{store}.myshopify.com/admin/api/{version}/graphql.json`, `…/api/{version}/graphql.json` for the Storefront API), the version pinned in config and moved every few quarters (each is supported for a year). `shopify/shopify-api` was archived in October 2026.
- **On the website:** product and collection blocks of the site's own, reading the Storefront API (cached), a product page per product on the site's own route (`/produkter/{handle}`, in the sitemap, with Product structured data), and a cart on the Cart API (`cartCreate`, `cartLinesAdd`, the cart id in the session) that sends people to Shopify's checkout (`cart.checkoutUrl`, fetched when they press the button). For a site with only a few products, Shopify's Storefront Web Components in a block are the low-code alternative, still on the website.
- **Shopify's own storefront out of the way:** the headless storefront from Shopify's Headless channel; the checkout on a subdomain (`checkout.example.no`, in Settings › Domains, its target the Online Store channel), styled with the site's logo and colours. Send people who still reach the store's Shopify address (old links, `*.myshopify.com`) to the website with Shopify's redirect theme, so products are only found there. Never turn on the Online Store password to hide it: it blocks the headless checkout too.
- **Customer accounts:** when customers sign in (order history, addresses), do it on the website with the Customer Account API (OAuth with PKCE, from the Headless channel; names and emails need Shopify's protected customer data approval), and pass the signed-in customer on to the checkout (the cart's buyer identity). Shopify's hosted account pages, on `account.example.no`, are the simpler choice when the site needs no account pages of its own.
- **In Aurora's admin (thin):** a `shopify_products` table (and `shopify_collections`, from the `collections/*` webhooks, for the collection blocks) kept current by Shopify's `products/create`, `products/update` and `products/delete` webhooks plus a nightly full sync (webhooks can arrive out of order or not at all), and a read-only resource of it under a "Shop" group whose products open in Shopify's admin to be edited. Blocks pick products from it (`Select` with search). Recent orders read-only, linking to Shopify. Never rebuild orders, refunds, stock or discounts: Shopify does them.
- **Webhooks:** verify `X-Shopify-Hmac-SHA256` (HMAC-SHA256 of the raw body with the app's secret), skip a `X-Shopify-Webhook-Id` already handled, answer within five seconds and do the work in a queued job.
- **Customers:** with a customer register of the site's own ([customers.md](customers.md)), `customers/create` and `customers/update` webhooks update it by email, with the newsletter consent Shopify recorded (`emailMarketingConsent`), never assumed.

### Blocks for the shop

Once Shopify is connected, **offer to make the shop's blocks**, so editors place products on any page in the visual editor the way they place everything else. List these and ask which the site needs (from the old site's templates when porting), then make each with `php artisan aurora:make-block`:

| Block | Editors choose | It shows |
| --- | --- | --- |
| Product card | A product | Image, title, price (and the sale price), a link to its page, an add-to-cart button for a product without variants |
| Product grid | A collection, how many, the order (Shopify's, newest, price), columns | A grid of product cards; "See all" to the collection's page |
| Hand-picked products | Products, in order | The same cards, the editor's own selection (a campaign, "our favourites") |
| Featured product | A product, image left or right | Large: images, description, the variant choices and add to cart; for a landing page |
| Collection list | Collections | A card per collection, with its image and a link to its page |
| Product carousel | A collection or hand-picked products | Cards in a row that scrolls |
| Add to cart | A product and variant, a label | Just the button, for a page about one product |

The cart itself (an icon with the count in the header, a cart drawer or page) is the layout's and a route's, not a block.

How each is made:
- **Picking products:** `Select::make('product')->options(fn (): array => ShopifyProduct::query()->orderBy('title')->pluck('title', 'handle')->all())->searchable()` from the product cache (collections likewise), never a free text field, so the editor and AI tools choose from what exists (`aiHints()`: "choose product from its options; never invent products"). Store the handle (or GID), not the product's text: the block shows what Shopify has now.
- **Showing them:** the view reads the Storefront API through one class of the site's own (`App\Shop\Storefront`: `product($handle)`, `collection($handle, $first, $sortKey)`), in the language and market being shown (`@inContext(language: NB, country: NO)` from `ContentLocale::current()`), cached for a few minutes (`Cache::remember`, keyed by handle, language and query; the `products/update` webhook forgets them). Prices from Shopify, formatted for the language (`Number::currency($amount, $currencyCode, locale: …)`); a product that's sold out says so and doesn't offer the button.
- **Images:** Shopify's CDN with its size parameters (`url(transform: {maxWidth: 800})`, `srcset` for a few widths), `alt` from Shopify's `altText`. Not copied into Aurora's media library.
- **Add to cart:** a `<form method="post">` to the site's own cart route (`cartLinesAdd` with the variant id), with Alpine updating the header's count when JavaScript is there; it works without. While editing (`is_editing()`), the button does nothing.
- **Editable around them:** the block's own heading and intro text as `editables()`, colours from `brand-*` and `accent-*`, card style through `Fields::tone()` (`aurora-development`).
- **Defaults and screenshots:** `defaults()` take the first products and collection from the cache, so a new block shows real products straight away, and its screenshot (`aurora:block-previews`) does too. A product that's gone draws nothing on the website and a hint while editing; never an error.
- **Search engines:** product cards link to the site's own product pages, and the product page (a route, see above) carries the Product structured data, not the blocks.
- **Tests:** with Shopify's API faked (`Http::fake()` for the GraphQL endpoint): each block renders a product, a sold-out product shows no button, and a missing product draws nothing.

The same blocks fit a shop engine inside Aurora: only `App\Shop\Storefront` changes, reading the engine's models instead of Shopify.

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
