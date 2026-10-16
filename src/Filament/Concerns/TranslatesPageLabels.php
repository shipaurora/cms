<?php

namespace Aurora\Filament\Concerns;

use Illuminate\Contracts\Support\Htmlable;

/**
 * A custom page's title and sidebar entry in the person's language. The English text set on
 * the page is the translation key, in lang/*.json.
 */
trait TranslatesPageLabels
{
    public function getTitle(): string|Htmlable
    {
        $title = parent::getTitle();

        return $title instanceof Htmlable ? $title : __($title);
    }

    public static function getNavigationLabel(): string
    {
        return __(parent::getNavigationLabel());
    }
}
