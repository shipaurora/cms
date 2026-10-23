<?php

namespace Aurora\Blocks;

use CarlJanzell\FilamentPageBuilder\Editable as PageBuilderEditable;

/**
 * Text an editor can change right on the canvas (a block's editables()):
 * Editable::text(), Editable::richText(), ->multiline(), ->placeholder().
 *
 * Rich text gets a small editor on the canvas: formatting, links, headings, lists and
 * quotes (Aurora\Support\InlineRichText). Text with more than that (a table) is edited in
 * the inspector.
 */
class Editable extends PageBuilderEditable {}
