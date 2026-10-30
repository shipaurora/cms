<?php

namespace Aurora\Mcp;

use Attribute;

/**
 * Keeps one of the website's models away from AI apps and the assistant (Aurora\Support\AiModels),
 * whatever its policy allows.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class HiddenFromAi {}
