<?php

namespace Aurora\Filament\Concerns;

use Aurora\Support\ContentLocale;

/**
 * For pages with the language switcher (lara-zeus/spatie-translatable): the language being
 * edited is the content language too, so everything on the page (previews, addresses,
 * page lists) reads in it.
 */
trait FollowsActiveLocale
{
    public function bootedFollowsActiveLocale(): void
    {
        ContentLocale::set($this->activeLocale ?? null);
    }

    public function updatedActiveLocale(): void
    {
        ContentLocale::set($this->activeLocale);

        $this->updatedTranslatableActiveLocale();

        $this->fillRequiredFromSourceLanguage();
    }

    /**
     * A required field with no text in this language yet starts with the source language's,
     * ready to translate, so the page can be saved in this language straight away.
     */
    protected function fillRequiredFromSourceLanguage(): void
    {
        $record = $this->getRecord();
        $source = $this->sourceLocale();

        if (! method_exists($record, 'isTranslatableAttribute') || $this->activeLocale === $source) {
            return;
        }

        foreach ($this->form->getFlatFields(withHidden: false) as $field) {
            $attribute = $field->getName();

            if (! $field->isRequired() || ! $record->isTranslatableAttribute($attribute) || filled($field->getState())) {
                continue;
            }

            $field->state($record->getTranslation($attribute, $source, false));
        }
    }

    /**
     * The language the record is written in (a page's own language, else the default site language).
     */
    protected function sourceLocale(): string
    {
        $record = $this->getRecord();

        return method_exists($record, 'primaryLocale') ? $record->primaryLocale() : ContentLocale::default();
    }
}
