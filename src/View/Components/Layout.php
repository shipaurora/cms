<?php

namespace Aurora\View\Components;

use Aurora\Models\Layout as LayoutModel;
use Aurora\Models\Page;
use Aurora\Support\Layouts;
use Closure;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Support\Facades\View;
use Illuminate\Support\HtmlString;
use Illuminate\View\Component;

/**
 * <x-aurora::layout>: a website route's own page in one of the site's layouts, its content
 * where a page's blocks would be (Layouts::wrap()).
 *
 *     <x-aurora::layout :title="__('Events')" description="What's on in Bergen" layout="landing">
 *         …the route's own markup…
 *     </x-aurora::layout>
 *
 * A class, not a view, so the stand-in page is there before the content inside is drawn:
 * current_page() there (blocks, partials) answers it, as on a page.
 */
class Layout extends Component
{
    /**
     * @var array<string, mixed>
     */
    protected array $shared;

    /**
     * @param  array<string, mixed>  $settings  the layout's own fields, as a page's would be
     */
    public function __construct(
        public string $title,
        public ?string $description = null,
        public ?string $layout = null,
        public bool $noindex = false,
        public array $settings = [],
    ) {
        $this->shared = array_intersect_key(View::getShared(), array_flip(['currentPage', 'previewing']));
        $chosen = (filled($layout) ? LayoutModel::cached()->firstWhere('handle', $layout) : null) ?? LayoutModel::default();

        View::share(['currentPage' => Page::standIn($title, new HtmlString(''), $description, $noindex, $chosen), 'previewing' => false]);
    }

    public function render(): Closure
    {
        // Drawn here, then handed back as a view: a component's closure may not return HTML.
        return function (array $data): ViewContract {
            try {
                return view('aurora::partials.html', ['html' => Layouts::wrap($data['slot'], $this->title, $this->description, $this->layout, $this->noindex, $this->settings)]);
            } finally {
                View::share(['currentPage' => $this->shared['currentPage'] ?? null, 'previewing' => $this->shared['previewing'] ?? false]);
            }
        };
    }
}
