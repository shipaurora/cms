<?php

namespace Aurora\Filament\Actions;

use LaraZeus\SpatieTranslatable\Actions\LocaleSwitcher;

/**
 * The language switcher on translatable edit pages, with the translate icon the visual
 * editor's language menu has, so both read as the same control.
 */
class LanguageSwitcher extends LocaleSwitcher
{
    public function toEmbeddedHtml(): string
    {
        $icon = svg('phosphor-translate-bold', 'fi-icon fi-size-md')->toHtml();

        // Filament's select action has no icon: put one in front of the select, the way
        // Filament's own inputs show a prefix icon.
        return preg_replace(
            '/(<div [^>]*class="fi-input-wrp[^"]*"[^>]*>)(\s*)(<select\b.*?<\/select>)/s',
            '$1<div class="fi-input-wrp-prefix fi-input-wrp-prefix-has-content fi-inline">'.$icon.'</div><div class="fi-input-wrp-content-ctn">$3</div>',
            parent::toEmbeddedHtml(),
            1,
        ) ?? parent::toEmbeddedHtml();
    }
}
