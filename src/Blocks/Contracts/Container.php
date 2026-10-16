<?php

namespace Aurora\Blocks\Contracts;

use CarlJanzell\FilamentPageBuilder\Contracts\Container as PageBuilderContainer;

/**
 * A block that holds other blocks in named slots (a band, a grid, a row of buttons): its
 * view draws each slot with Blocks::slot('name').
 */
interface Container extends PageBuilderContainer {}
