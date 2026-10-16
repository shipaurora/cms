{{-- The relation manager's own view, plus the dialogs its actions open: with no table on
     this tab, nothing else would render them. --}}
<div class="fi-resource-relation-manager">
    {{ $this->content }}

    <x-filament-actions::modals />

    <x-filament-panels::unsaved-action-changes-alert />
</div>
