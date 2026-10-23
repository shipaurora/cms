@props(['page' => null, 'blocks' => null])

{{-- A page's blocks in the language being shown (<x-aurora::blocks :page="$page" />), drawn by
     whatever builds Aurora's pages, so websites never need to know what that is. Attributes
     go on the element around them (class="flex flex-col gap-24"), and a list of blocks can be
     drawn instead of a page's (:blocks="…", in the language being shown already; a block left
     out takes the blocks in it along). A website route's own page (<x-aurora::layout>) shows
     its content here instead. --}}
@if ($blocks === null && $page?->isStandIn())
    <div {{ $attributes->class('fpb-page') }}>{{ $page->standInContent() }}</div>
@else
    <x-page-builder::blocks :blocks="$blocks ?? $page?->localizedBlocks() ?? []" panel="admin" {{ $attributes }} />
@endif
