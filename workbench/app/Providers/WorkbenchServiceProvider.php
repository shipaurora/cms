<?php

namespace Workbench\App\Providers;

use Aurora\Blocks\Blocks;
use Aurora\Models\Page;
use Aurora\Support\AdminPanel;
use Aurora\Support\SafeUrl;
use Aurora\Support\SettingsTabs;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Blocks\AccordionBlock;
use Workbench\App\Blocks\AlertBlock;
use Workbench\App\Blocks\BandBlock;
use Workbench\App\Blocks\ButtonBlock;
use Workbench\App\Blocks\ButtonGroupBlock;
use Workbench\App\Blocks\CallToActionBlock;
use Workbench\App\Blocks\CardBlock;
use Workbench\App\Blocks\CodeSampleBlock;
use Workbench\App\Blocks\FeatureBlock;
use Workbench\App\Blocks\FormBlock;
use Workbench\App\Blocks\GalleryBlock;
use Workbench\App\Blocks\GridBlock;
use Workbench\App\Blocks\HeadingBlock;
use Workbench\App\Blocks\HeroBlock;
use Workbench\App\Blocks\ImageBlock;
use Workbench\App\Blocks\LogoCloudBlock;
use Workbench\App\Blocks\PageListBlock;
use Workbench\App\Blocks\ParagraphBlock;
use Workbench\App\Blocks\PricingBlock;
use Workbench\App\Blocks\RichTextBlock;
use Workbench\App\Blocks\StatBlock;
use Workbench\App\Blocks\StepsBlock;
use Workbench\App\Blocks\TabsBlock;
use Workbench\App\Blocks\TeamMemberBlock;
use Workbench\App\Blocks\TestimonialBlock;
use Workbench\App\Filament\Widgets\FormsOverview;

/**
 * The test suite's website: a site's own blocks (a copy of the docs website's) and its header and
 * footer settings tabs, registered as a site built on Aurora registers them. The tests use them
 * the way editors and apps use a real site's.
 */
class WorkbenchServiceProvider extends ServiceProvider
{
    /**
     * Its own dashboard widget, in register(): the admin panel takes the list while the app's
     * providers boot.
     */
    public function register(): void
    {
        AdminPanel::widgets([FormsOverview::class]);
    }

    public function boot(): void
    {

        // In the order the editor's block palette lists them.
        Blocks::register([
            BandBlock::class,
            GridBlock::class,
            ButtonGroupBlock::class,
            HeroBlock::class,
            HeadingBlock::class,
            ParagraphBlock::class,
            RichTextBlock::class,
            ButtonBlock::class,
            AlertBlock::class,
            AccordionBlock::class,
            TabsBlock::class,
            StepsBlock::class,
            CardBlock::class,
            FeatureBlock::class,
            StatBlock::class,
            PricingBlock::class,
            TestimonialBlock::class,
            TeamMemberBlock::class,
            LogoCloudBlock::class,
            CallToActionBlock::class,
            ImageBlock::class,
            GalleryBlock::class,
            PageListBlock::class,
            FormBlock::class,
            CodeSampleBlock::class,
        ]);

        $this->registerSettings();
    }

    /**
     * The settings a site's header and footer read: the header's call-to-action button and
     * announcement bar, and the footer's note and social profiles.
     */
    protected function registerSettings(): void
    {
        // Tabs on Settings' Website page.
        SettingsTabs::register('header', fn (): Tab => Tab::make(__('Header'))->icon('phosphor-app-window')->columns(3)->schema([
            Section::make(__('Header'))
                ->icon('phosphor-app-window')
                ->description(__('The button at the right of the main navigation.'))
                ->columnSpan(2)
                ->columns(2)
                ->schema([
                    TextInput::make('header_cta_label')->label(__('Button label'))->placeholder(__('e.g. Start a project')),
                    Select::make('header_cta_page')->label(__('Links to'))
                        ->options(fn (): array => Page::options())
                        ->searchable(),
                ]),
            Section::make(__('Announcement bar'))
                ->icon('phosphor-megaphone')
                ->description(__('A slim banner above the header.'))
                ->columnSpan(1)
                ->schema([
                    Toggle::make('announcement.enabled')->label(__('Show the announcement'))->live(),
                    Textarea::make('announcement.text')->label(__('Message'))->rows(2)->maxLength(140)
                        ->visible(fn (Get $get): bool => (bool) $get('announcement.enabled')),
                    Grid::make(2)->visible(fn (Get $get): bool => (bool) $get('announcement.enabled'))->schema([
                        TextInput::make('announcement.link_label')->label(__('Link label')),
                        TextInput::make('announcement.url')->label(__('Link'))->rule(SafeUrl::rule()),
                    ]),
                ]),
        ]), siteKeys: ['header_cta_label', 'header_cta_page', 'announcement'], page: 'website', publicKeys: ['header_cta_label', 'header_cta_page', 'announcement']);

        SettingsTabs::register('footer', fn (): Tab => Tab::make(__('Footer'))->icon('phosphor-rows')->columns(3)->schema([
            Section::make(__('Footer'))
                ->icon('phosphor-rows')
                ->description(__('Footer links are managed under Menus.'))
                ->columnSpanFull()
                ->columns(2)
                ->schema([
                    TextInput::make('footer_note')->label(__('Footer note'))->columnSpanFull(),
                    Repeater::make('socials')
                        ->label(__('Social profiles'))
                        ->schema([
                            // Brand names, the same in every language: what is saved is what the footer shows.
                            Select::make('network')->options(collect(['LinkedIn', 'GitHub', 'Instagram', 'Facebook', 'TikTok', 'YouTube', 'X', 'Bluesky', 'Mastodon'])->mapWithKeys(fn (string $network): array => [$network => $network])->all())->required(),
                            TextInput::make('url')->url()->required(),
                        ])
                        ->columns(2)
                        ->grid(2)
                        ->reorderableWithDragAndDrop()
                        ->addActionLabel(__('Add profile'))
                        ->columnSpanFull(),
                ]),
        ]), siteKeys: ['footer_note', 'socials'], page: 'website', publicKeys: ['footer_note', 'socials']);
    }
}
