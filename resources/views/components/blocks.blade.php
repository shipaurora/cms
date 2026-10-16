@props(['page'])

{{-- A page's blocks in the language being shown (<x-aurora::blocks :page="$page" />), drawn by
     whatever builds Aurora's pages, so websites never need to know what that is. --}}
<x-page-builder::blocks :blocks="$page->localizedBlocks()" panel="admin" />
