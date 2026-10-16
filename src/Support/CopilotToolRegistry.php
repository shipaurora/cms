<?php

namespace Aurora\Support;

use EslamRedaDiv\FilamentCopilot\Services\ToolRegistry;
use EslamRedaDiv\FilamentCopilot\Tools\RecallTool;
use EslamRedaDiv\FilamentCopilot\Tools\RememberTool;

/**
 * The copilot's built-in tools, minus its resource/page/widget discovery: the CMS tools
 * (Aurora\Support\CopilotTools) cover everything, and offering both only confuses the model.
 * Remembering preferences across conversations stays.
 */
class CopilotToolRegistry extends ToolRegistry
{
    protected array $toolClasses = [
        RememberTool::class,
        RecallTool::class,
    ];
}
