<?php

namespace Aurora\Tests\Feature;

use Aurora\Filament\Forms\Components\PagePicker;
use Aurora\Filament\Resources\Menus\MenuResource;
use Aurora\Filament\Resources\Menus\Pages\EditMenu;
use Aurora\Livewire\MenuItems;
use Aurora\Models\Menu;
use Aurora\Models\MenuItem;
use Aurora\Models\Page;
use Aurora\Tests\TestCase;
use Datlechin\FilamentMenuBuilder\Enums\LinkTarget;
use Datlechin\FilamentMenuBuilder\FilamentMenuBuilderPlugin;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

class MenuEditorTest extends TestCase
{
    use RefreshDatabase;

    protected function menu(): Menu
    {
        return Menu::query()->create(['name' => 'Main', 'is_visible' => true]);
    }

    public function test_items_are_added_from_an_add_menu_instead_of_side_panels(): void
    {
        $menu = $this->menu();

        $this->actingAs($this->userWithRole('Editor'))
            ->get(MenuResource::getUrl('edit', ['record' => $menu]))
            ->assertOk()
            ->assertSee('Menu items')
            ->assertDontSeeLivewire('menu-builder-panel')
            ->assertDontSeeLivewire('create-custom-link')
            // Aurora's item list (dialog edit form, icon picker), not the package's own.
            ->assertSeeLivewire(MenuItems::class);

        Livewire::actingAs($this->userWithRole('Editor'))
            ->test(EditMenu::class, ['record' => $menu->getRouteKey()])
            ->assertActionExists(TestAction::make('addFromPages')->schemaComponent('menuItems', schema: 'content'))
            ->assertActionExists(TestAction::make('addFromShortcuts')->schemaComponent('menuItems', schema: 'content'))
            ->assertActionExists(TestAction::make('addCustomLink')->schemaComponent('menuItems', schema: 'content'))
            ->assertActionExists(TestAction::make('addCustomText')->schemaComponent('menuItems', schema: 'content'));
    }

    public function test_a_page_is_picked_from_the_tree_and_appended(): void
    {
        $menu = $this->menu();
        $docs = Page::factory()->create(['title' => 'Docs', 'slug' => 'docs']);
        $start = Page::factory()->create(['title' => 'Getting started', 'slug' => 'getting-started', 'parent_id' => $docs->id]);
        $about = Page::factory()->create(['title' => 'About us', 'slug' => 'about']);
        $menu->menuItems()->create(['title' => [], 'linkable_type' => $docs->getMorphClass(), 'linkable_id' => $docs->id, 'order' => 4]);

        $component = Livewire::actingAs($this->userWithRole('Editor'))
            ->test(EditMenu::class, ['record' => $menu->getRouteKey()]);
        $add = TestAction::make('addFromPages')->schemaComponent('menuItems', schema: 'content');

        // The tree, a subpage after its page, with its address; what's in the menu is marked.
        $component->mountAction($add)
            ->assertSeeInOrder(['About us', 'Docs', 'In this menu', 'Getting started']);

        $rows = PagePicker::make('page')->getPageRows();
        $this->assertSame(['/about', '/docs', '/docs/getting-started'], array_column($rows, 'path'));
        $this->assertSame([0, 0, 1], array_column($rows, 'depth'));

        $component->callAction($add, ['page' => $about->id])
            ->assertHasNoActionErrors()
            ->assertDispatched('menu:changed');

        $added = $menu->menuItems()->where('linkable_id', $about->id)->sole();
        $this->assertSame([], $added->getTranslations('title'), 'It shows the page’s title.');
        $this->assertSame(5, $added->order);

        $component->callAction($add, ['page' => null])->assertHasActionErrors(['page' => 'required']);
    }

    public function test_adding_a_page_that_is_already_in_the_menu_is_confirmed_first(): void
    {
        $menu = $this->menu();
        $docs = Page::factory()->create(['title' => 'Docs', 'slug' => 'docs']);
        $menu->menuItems()->create(['title' => [], 'linkable_type' => $docs->getMorphClass(), 'linkable_id' => $docs->id, 'order' => 1]);

        $editor = Livewire::actingAs($this->userWithRole('Editor'))->test(EditMenu::class, ['record' => $menu->getRouteKey()]);

        $editor->callAction(TestAction::make('addFromPages')->schemaComponent('menuItems', schema: 'content'), ['page' => $docs->id])
            ->assertActionMounted('confirmDuplicate');

        $this->assertSame('“Docs” is already in this menu', $editor->instance()->getMountedAction()->getModalHeading());

        $this->assertSame(1, $menu->menuItems()->count(), 'Nothing is added before it is confirmed.');

        // Over the "Add page" dialog: cancelling goes back to it.
        $this->assertSame(['addFromPages', 'confirmDuplicate'], array_column($editor->get('mountedActions'), 'name'));
        // As the dialog's Cancel and close do: only it closes.
        $editor->call('unmountAction', false);
        $this->assertSame(['addFromPages'], array_column($editor->get('mountedActions'), 'name'));

        // Adding it again closes both.
        $editor->callMountedAction();
        $editor->callMountedAction()->assertHasNoErrors();

        $this->assertSame(2, $menu->menuItems()->where('linkable_id', $docs->id)->count());
        $this->assertSame([], $editor->get('mountedActions'));
    }

    public function test_a_page_with_subpages_is_added_on_its_own_or_with_its_tree(): void
    {
        $menu = $this->menu();
        $docs = Page::factory()->create(['title' => 'Docs', 'slug' => 'docs']);
        $start = Page::factory()->create(['title' => 'Getting started', 'slug' => 'getting-started', 'parent_id' => $docs->id]);
        Page::factory()->create(['title' => 'Install', 'slug' => 'install', 'parent_id' => $start->id]);

        $editor = Livewire::actingAs($this->userWithRole('Editor'))->test(EditMenu::class, ['record' => $menu->getRouteKey()]);
        $add = TestAction::make('addFromPages')->schemaComponent('menuItems', schema: 'content');

        // Only the page, by default.
        $editor->callAction($add, ['page' => $docs->id])->assertHasNoActionErrors();
        $this->assertSame(1, MenuItem::query()->where('menu_id', $menu->id)->count());

        // With its subpages: Docs › Getting started › Install.
        MenuItem::query()->where('menu_id', $menu->id)->delete();
        $editor->callAction($add, ['page' => $docs->id, 'subpages' => 'tree'])->assertHasNoActionErrors();

        $top = $menu->menuItems()->whereNull('parent_id')->sole();
        $this->assertTrue($top->linkable->is($docs));
        $this->assertSame(['Getting started'], $top->children->map(fn (MenuItem $item): string => $item->linkable->title)->all());
        $this->assertSame(['Install'], $top->children->first()->children->map(fn (MenuItem $item): string => $item->linkable->title)->all());
    }

    public function test_a_shortcut_is_picked_on_its_own(): void
    {
        $menu = $this->menu();

        Livewire::actingAs($this->userWithRole('Editor'))
            ->test(EditMenu::class, ['record' => $menu->getRouteKey()])
            ->callAction(TestAction::make('addFromShortcuts')->schemaComponent('menuItems', schema: 'content'), ['item' => 1])
            ->assertHasNoActionErrors();

        $this->assertSame(['/sitemap.xml'], $menu->menuItems()->pluck('url')->all());
    }

    public function test_custom_links_and_text_labels_are_added_from_their_modals(): void
    {
        $menu = $this->menu();

        Livewire::actingAs($this->userWithRole('Editor'))
            ->test(EditMenu::class, ['record' => $menu->getRouteKey()])
            ->callAction(TestAction::make('addCustomLink')->schemaComponent('menuItems', schema: 'content'), ['title' => 'Docs', 'url' => 'https://example.com/docs', 'target' => LinkTarget::Blank->value])
            ->assertHasNoActionErrors()
            ->assertNotified('Added “Docs”')
            ->callAction(TestAction::make('addCustomText')->schemaComponent('menuItems', schema: 'content'), ['title' => 'Company', 'icon' => 'phosphor-buildings'])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('menu_items', ['menu_id' => $menu->id, 'title->en' => 'Docs', 'url' => 'https://example.com/docs', 'target' => LinkTarget::Blank->value, 'order' => 1]);
        $this->assertDatabaseHas('menu_items', ['menu_id' => $menu->id, 'title->en' => 'Company', 'url' => null, 'icon' => 'phosphor-buildings', 'order' => 2]);
    }

    public function test_the_menu_reads_in_the_language_of_the_page_being_visited(): void
    {
        $this->withEnglishAndNorwegian();
        Page::factory()->homepage()->create(['title' => ['en' => 'Home', 'nb' => 'Hjem'], 'slug' => ['en' => 'home', 'nb' => 'hjem']]);
        $about = Page::factory()->published()->create(['title' => ['en' => 'About us', 'nb' => 'Om oss'], 'slug' => ['en' => 'about-us', 'nb' => 'om-oss']]);
        $menu = Menu::query()->create(['name' => 'Main', 'is_visible' => true]);
        $menu->locations()->create(['location' => 'header']);

        // A page's item without a title of its own; one with its own wording, translated; one not.
        $item = fn (array $attributes): MenuItem => MenuItem::query()->create(['menu_id' => $menu->id, 'order' => 1, ...$attributes]);
        $item(['title' => [], 'linkable_type' => $about->getMorphClass(), 'linkable_id' => $about->id]);
        $item(['title' => ['en' => 'Our story', 'nb' => 'Vår historie'], 'linkable_type' => $about->getMorphClass(), 'linkable_id' => $about->id]);
        $item(['title' => ['en' => 'Careers'], 'url' => '/careers']);
        // Only a Norwegian title of its own: in English it is the page's, not the Norwegian one.
        $item(['title' => ['nb' => 'Kontakt oss'], 'linkable_type' => $about->getMorphClass(), 'linkable_id' => $about->id]);

        $this->get('/nb/om-oss')->assertOk()
            ->assertSeeText('Om oss')
            ->assertSeeText('Vår historie')
            ->assertSeeText('Careers')
            ->assertDontSeeText('Our story');

        $this->get('/nb/om-oss')->assertSeeText('Kontakt oss');
        $this->get('/about-us')->assertOk()->assertSeeText('About us')->assertSeeText('Our story')->assertDontSeeText('Kontakt oss');
    }

    public function test_custom_links_and_text_labels_are_written_per_language(): void
    {
        // As the panel sets it up once the website has a second language.
        FilamentMenuBuilderPlugin::get()->translatable(['en', 'nb']);
        $menu = $this->menu();

        Livewire::actingAs($this->userWithRole('Editor'))
            ->test(EditMenu::class, ['record' => $menu->getRouteKey()])
            ->callAction(TestAction::make('addCustomText')->schemaComponent('menuItems', schema: 'content'), ['title' => ['en' => 'Company', 'nb' => 'Selskapet']])
            ->assertHasNoActionErrors()
            ->assertNotified('Added “Company”')
            ->callAction(TestAction::make('addCustomLink')->schemaComponent('menuItems', schema: 'content'), ['title' => ['en' => 'Docs', 'nb' => ''], 'url' => '/docs', 'target' => LinkTarget::Self->value])
            ->assertHasNoActionErrors();

        $this->assertSame(['en' => 'Company', 'nb' => 'Selskapet'], MenuItem::query()->where('order', 1)->sole()->getTranslations('title'));
        $this->assertSame(['en' => 'Docs'], MenuItem::query()->where('order', 2)->sole()->getTranslations('title'), 'A language left empty shows the default one.');
    }

    public function test_an_items_title_is_edited_per_language_in_a_dialog(): void
    {
        FilamentMenuBuilderPlugin::get()->translatable(['en', 'nb']);
        $menu = $this->menu();
        $item = MenuItem::query()->create(['menu_id' => $menu->id, 'title' => ['en' => 'Company'], 'order' => 1]);

        $items = Livewire::actingAs($this->userWithRole('Editor'))->test(MenuItems::class, ['menu' => $menu]);

        $this->assertFalse($items->instance()->editAction()->isModalSlideOver(), 'A dialog, like the add ones.');

        // The Norwegian field is typed into after picking Norwegian; both are saved.
        $items->call('mountAction', 'edit', ['id' => $item->id])
            ->assertSet('mountedActions.0.data.title.en', 'Company')
            ->set('mountedActions.0.data.title_language', 'nb')
            ->set('mountedActions.0.data.title.nb', 'Selskapet')
            ->call('callMountedAction')
            ->assertHasNoErrors();

        $this->assertSame(['en' => 'Company', 'nb' => 'Selskapet'], $item->fresh()->getTranslations('title'));
    }

    public function test_a_page_items_page_is_changed_by_picking_another(): void
    {
        $menu = $this->menu();
        $about = Page::factory()->create(['title' => 'About us']);
        $contact = Page::factory()->create(['title' => 'Contact']);
        $item = MenuItem::query()->create(['menu_id' => $menu->id, 'title' => 'About us', 'linkable_type' => $about->getMorphClass(), 'linkable_id' => $about->id, 'order' => 1]);

        Livewire::actingAs($this->userWithRole('Editor'))
            ->test(MenuItems::class, ['menu' => $menu])
            ->call('mountAction', 'edit', ['id' => $item->id])
            ->assertSet('mountedActions.0.data.linkable_id', $about->id)
            ->set('mountedActions.0.data.linkable_id', $contact->id)
            ->call('callMountedAction')
            ->assertHasNoErrors();

        $this->assertTrue($item->fresh()->linkable->is($contact));
    }

    public function test_the_menu_is_shown_and_edited_in_the_language_picked_on_its_page(): void
    {
        $this->withEnglishAndNorwegian();
        FilamentMenuBuilderPlugin::get()->translatable(['en', 'nb']);
        $menu = $this->menu();
        $item = MenuItem::query()->create(['menu_id' => $menu->id, 'title' => ['en' => 'Company', 'nb' => 'Selskapet'], 'order' => 1]);

        Livewire::actingAs($this->userWithRole('Editor'))
            ->test(EditMenu::class, ['record' => $menu->getRouteKey()])
            ->set('activeLocale', 'nb')
            ->assertDispatched('menu:locale', locale: 'nb');

        Livewire::actingAs($this->userWithRole('Editor'))
            ->test(MenuItems::class, ['menu' => $menu])
            ->assertSee('Company')
            ->dispatch('menu:locale', locale: 'nb')
            ->assertSee('Selskapet')
            ->call('mountAction', 'edit', ['id' => $item->id])
            ->assertSet('mountedActions.0.data.title_language', 'nb');
    }
}
