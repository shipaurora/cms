<?php

namespace Aurora\Events;

use Aurora\Models\Page;

/**
 * Something that happened to a page.
 */
abstract class PageEvent extends ContentEvent
{
    public function __construct(public readonly Page $page) {}

    public function payload(): array
    {
        return [
            'id' => $this->page->id,
            'site' => $this->page->site?->handle,
            'title' => $this->page->title,
            'path' => $this->page->path(),
            'url' => $this->page->url(),
            'status' => $this->page->status->value,
            'live' => $this->page->isLive(),
        ];
    }
}
