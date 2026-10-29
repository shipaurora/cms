# Newsletters: campaigns in Aurora, sent by Brevo or Mailchimp

Aurora doesn't send newsletters. When the old site had them (Netflex newsletters, a Mailchimp form, MailPoet, Statamic's Campaign Monitor addon), recommend this to the user, and build it only once they agree: editors put a campaign together in Aurora's admin with blocks, as they do pages, pick the lists it goes to and when; Aurora turns it into email HTML and hands it to a provider that sends it, handles the unsubscribes and counts the opens. Both providers below take raw HTML campaigns over their API.

| | Brevo | Mailchimp |
| --- | --- | --- |
| A list in Aurora | a Brevo list (its id) | a tag in the one audience the site uses |
| Free plan | 300 emails a day, API included | since February 2026: 250 contacts, 500 sends a month, Mailchimp's footer, no scheduling |
| PHP SDK | `getbrevo/brevo-php` (maintained) | `mailchimp/marketing` (last release 2022): don't |

Recommend **Brevo** unless the site already uses Mailchimp and wants to stay: its lists are lists, and its free plan does more. Either way, talk to them with Laravel's `Http` client, not an SDK: it's about fifteen endpoints.

Built on the site's customers ([customers.md](customers.md)): build those first, or with it.

## The pieces

- **`NewsletterList`** (name, the provider's list id or tag, `site_id` if per site) and a `customer_newsletter_list` pivot (`subscribed_at`, `unsubscribed_at`). The customer resource gets "Add to list" and "Remove from list" bulk actions, and the list resource shows its members with a count.
- **`Campaign`**: `name`, `subject`, `preview_text`, `from_name`, `from_email`, `reply_to`, `blocks` (JSON), the lists (a pivot), `status` (`draft`, `scheduled`, `sending`, `sent`, `failed`), `scheduled_at`, `sent_at`, `provider_campaign_id`, `stats` (JSON) and `stats_updated_at`.
- A **"Newsletters" group** in the admin with both resources: `AdminPanel::navigationGroup('Newsletters', fn (): string => __('Newsletters'), 'phosphor-paper-plane-tilt', after: 'Customers')`.
- A **provider driver** behind an interface of the site's own, chosen in `config/services.php` (`NEWSLETTER_PROVIDER=brevo`, the key in `.env`):

```php
interface NewsletterProvider
{
    public function upsertContact(Customer $customer, array $listIds = []): void;
    public function addToList(Customer $customer, NewsletterList $list): void;
    public function removeFromList(Customer $customer, NewsletterList $list): void;
    public function createOrUpdateCampaign(Campaign $campaign, string $html): string; // the provider's campaign id
    public function sendTest(Campaign $campaign, array $emails): void;
    public function sendNow(Campaign $campaign): void;
    public function stats(Campaign $campaign): array; // delivered, opens, clicks, bounces, unsubscribes
    public function unsubscribesFrom(Request $webhook): array; // emails (and lists) from the provider's webhook
}
```

  Brevo: `api-key` header, `https://api.brevo.com/v3`; contacts `POST /contacts` (`updateEnabled: true`), lists `POST /contacts/lists/{id}/contacts/add|remove`; campaigns `POST`/`PUT /emailCampaigns` (`htmlContent`, `recipients.listIds`), `POST /emailCampaigns/{id}/sendTest`, `/sendNow`; stats `GET /emailCampaigns/{id}`. Mailchimp: Basic auth, `https://{dc}.api.mailchimp.com/3.0` (`dc` is the key's suffix); members `PUT /lists/{audience}/members/{md5(lower email)}`, tags `POST …/members/{hash}/tags`; campaigns `POST /campaigns` (`recipients.segment_opts` matching the lists' tags), `PUT /campaigns/{id}/content` (`html`), `POST /campaigns/{id}/actions/test`, `/actions/send`; stats `GET /reports/{id}`. Read the provider's API reference for the fields before writing each call: these change.

## The editor: blocks made for email

Campaigns are edited with the same visual editor as pages, with blocks of their own. Email clients draw HTML like it's 2005, so **page blocks don't go in emails**: the email blocks are tables about 600px wide with inline styles, absolute image URLs, buttons made of table cells, no scripts, forms or web fonts.

- A design page for the campaign resource that extends the page builder's own (`CarlJanzell\FilamentPageBuilder\Filament\Pages\DesignPage`, not Aurora's page editor, which is made for pages), registered on the resource like Aurora's `PageResource` registers its own. It overrides `registry()` to return a `BlockRegistry` of the email blocks only (`(new BlockRegistry)->register([EmailHeadingBlock::class, …])`), so they never show in the pages' block list, and Aurora's `Blocks::register()` isn't used for them. Give the canvas the email's look (`canvasStylesView()`) and the 600px width. Check these methods against the installed version of `carljanzell/filament-page-builder` first.
- A small set to start with, made with `aurora:make-block` and moved to `app/Newsletter/Blocks`: header (logo), heading, text (rich text), image, button, two columns, divider, footer (address, unsubscribe link). More only when editors need them.
- **Rendering:** one Blade layout for the email (`resources/views/newsletter/email.blade.php`: the 600px table, the brand colours as fixed values, the preview text hidden at the top), the blocks drawn into it, then the CSS inlined with `TijsVerkoyen\CssToInlineStyles\CssToInlineStyles` (it comes with Laravel's mailer). Absolute URLs for every image and link. Keep a "View in browser" route that shows the same HTML, so editors can see it and the provider's mirror link has a fallback.
- **Personal fields:** editors write `%%first_name%%` and `%%unsubscribe%%`; the driver turns them into the provider's tags (`{{ contact.FIRSTNAME }}` and `{{ unsubscribe }}` for Brevo, `*|FNAME|*` and `*|UNSUB|*` for Mailchimp). The footer block always has the unsubscribe link and the sender's address, and sending refuses an HTML without them.

## Sending and scheduling

- **Send a test** (an action on the campaign: addresses, by default the editor's own): saves the campaign to the provider and sends the test there.
- **Schedule** sets `scheduled_at` and `status = scheduled`. Aurora sends it, not the provider: a command run every minute (`Schedule::command('newsletters:send-due')->everyMinute()->withoutOverlapping()` in `routes/console.php`) sends the campaigns that are due. That works on Mailchimp's free plan too, isn't held to Mailchimp's quarter-hours, and lets editors change or cancel a campaign until the minute it goes. A delayed job per campaign works as well, if it checks `scheduled_at` and `status` again when it runs (a campaign may have been moved or cancelled). Either way it needs the server's scheduler (`* * * * * php artisan schedule:run`) and a queue worker: say so to the user.
- **Send now** and the scheduled send do the same: render, `createOrUpdateCampaign()`, `sendNow()`, `status = sent`, `sent_at`, in a queued job that's safe to run twice (it checks `status` and keeps `provider_campaign_id`). A sent campaign can't be edited, only duplicated.
- **Before sending**, confirm in the action's modal: the lists, how many people that is, and the subject.

## Keeping the lists in step

- A customer added to or removed from a list in Aurora is sent to the provider (`addToList()`, `removeFromList()`, queued). Only customers with consent (`newsletter_consent_at`) and not unsubscribed are added; the bulk action says how many it left out.
- Unsubscribes happen at the provider. A webhook route of the site's own (Brevo's marketing webhook `unsubscribe` event, Mailchimp's list webhook) marks the customer `unsubscribed_at` and leaves the list; verify the request (a secret in the webhook's address) and never let it create customers. Mailchimp unsubscribes from the whole audience, Brevo can from one list: keep both the customer's flag and the list membership.
- A command once a day (`newsletters:fetch-stats`) stores the stats of campaigns sent in the last 30 days, shown on the campaign's page.

## Bringing the old newsletters across

Subscribers come across as customers with their consent and lists (only where the old system recorded consent). Old campaigns usually don't: ask. If the provider already has the lists (a site that used Mailchimp), match them by id instead of creating new ones, and fetch its unsubscribes before the first send.

Tests (the provider faked with `Http::fake()`): a scheduled campaign is sent once when due and not before; sending refuses HTML without the unsubscribe link; a webhook unsubscribe marks the customer; a customer without consent isn't sent to a list.
