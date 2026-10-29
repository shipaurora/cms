# Customers: a CRM of the site's own

Aurora keeps no register of customers, members or contacts. When the old site has one (Netflex customers, WooCommerce or Simple Commerce customers, a members' area, a contacts table, newsletter subscribers with names), offer the user a `Customer` model of the site's own: its migration, a Filament resource under a group of its own in the admin, and an import. It's the same pattern as the employees in `aurora-porting` section 3, shaped by the old site's data rather than a fixed template.

With a shop ([shop.md](shop.md)), the shop keeps customers of its own for orders and addresses. Don't keep two lists of the same people apart: on Shopify, its customer webhooks update the site's customers by email; with Lunar or Shopper, link each of the site's customers to theirs (a nullable `lunar_customer_id` or the like, filled when someone first orders); with Vanilo, its `customers` table is the register, extended with what's below. Consent and newsletter lists stay with the site's register.

## Ask before building

- **What comes across:** list the old system's customer fields, with how many records use each, and agree on the set. Leave out what nobody has filled in.
- **Per site or shared:** with several sites in Aurora, are customers each site's own (`BelongsToSite`, registered `perSite: true`) or one register for all?
- **Logins:** customers are records, not Aurora users. Aurora's users are the admin's editors. If customers log in on the website (a members' area), say so and agree how: a guard of the site's own on the `Customer` model, or not at all. Passwords never come across from another system; customers who log in set a new one.
- **Personal data:** bring across only what the site still needs, and consent only where the old system recorded it (when and how). Never set consent for people who didn't give it.

## The model

Shape the columns after the old data. A typical start:

```php
Schema::create('customers', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('site_id')->nullable()->constrained()->cascadeOnDelete(); // only per site
    $table->string('email');
    $table->string('first_name')->nullable();
    $table->string('last_name')->nullable();
    $table->string('phone')->nullable();
    $table->string('company')->nullable();
    $table->json('address')->nullable();          // or columns, when the site searches or sorts by them
    $table->string('locale', 8)->nullable();       // the language to write to them in
    $table->text('notes')->nullable();
    $table->timestamp('newsletter_consent_at')->nullable();
    $table->string('newsletter_consent_source')->nullable(); // "Netflex import", "Contact form", …
    $table->timestamp('unsubscribed_at')->nullable();
    $table->string('external_id')->nullable()->index(); // the old system's id, for re-running the import
    $table->timestamps();
    $table->softDeletes();
    $table->unique(['site_id', 'email']);
});
```

`HasUuids`, `SoftDeletes` and, per site, `BelongsToSite`. Groups, segments or tags from the old system become **lists** (below) when they decide who gets which newsletter, or a `TagsInput`/`Select` on the customer when they only describe them.

## The resource

`php artisan make:filament-resource Customer --generate`, with a `CustomerPolicy` and permissions of its own (`Permissions::register('Customers', …)`; customers' personal data usually isn't for every editor: ask which roles get them), then make it read like the rest of Aurora's admin (`aurora-development`, "The website's own screens, models and routes"):

- Registered in a service provider's `register()` with `AdminPanel::discover(app_path('Filament/Resources'), 'App\Filament\Resources', perSite: …)`, in a group of its own: `AdminPanel::navigationGroup('Customers', fn (): string => __('Customers'), 'phosphor-users-three')`.
- Table: name, email, company, lists (badges), consent; searchable on name, email and company; filters for list, consent and unsubscribed; bulk actions to add to or remove from a list.
- Form: sections with Phosphor icons and sentence-case labels, consent shown read-only with where it came from (editors don't tick consent for people).
- An export action, and an import action for the spreadsheets editors keep (Filament's `ImportAction` with an importer class; search Boost's docs for its setup, which needs migrations of its own).
- Every label through `__()`, with lines in `lang/{locale}.json`.

## Customers from the website's forms

Aurora fires `Aurora\Events\FormSubmitted` (with the `FormSubmission`) for every form sent that isn't spam. A listener of the site's own can update or create the customer by email from a contact or sign-up form, and add them to a list when the form had a consent checkbox that was ticked. Agree with the user which forms do this; a contact form shouldn't quietly sign people up for newsletters.

## Bringing them across

Part of the site's import command, run as often as needed: `Customer::query()->updateOrCreate(['external_id' => $oldId], [...])` (or by email when the old system has no ids), the email lower-cased and trimmed, duplicates by email merged and reported. Tests: the import runs twice without doubles, and a customer without consent doesn't land on a list.
