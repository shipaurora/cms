<?php

namespace Aurora\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum RevisionSource: string implements HasColor, HasIcon, HasLabel
{
    case Editor = 'editor';
    case Canvas = 'canvas';
    case Mcp = 'mcp';
    case Assistant = 'assistant';
    case Restore = 'restore';
    case System = 'system';

    public function getLabel(): string
    {
        return match ($this) {
            self::Editor => __('Page settings'),
            self::Canvas => __('Visual editor'),
            self::Mcp => __('External AI app (MCP)'),
            self::Assistant => __('AI assistant'),
            self::Restore => __('Restored'),
            self::System => __('System'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Editor => 'gray',
            self::Canvas => 'primary',
            self::Mcp => 'violet',
            self::Assistant => 'primary',
            self::Restore => 'warning',
            self::System => 'gray',
        };
    }

    /**
     * Made by an AI (the assistant in the admin panel or an external app) for a person.
     */
    public function isAi(): bool
    {
        return $this === self::Mcp || $this === self::Assistant;
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Editor => 'phosphor-sliders-horizontal',
            self::Canvas => 'phosphor-paint-brush',
            self::Mcp => 'phosphor-sparkle',
            self::Assistant => 'phosphor-chat-circle-dots',
            self::Restore => 'phosphor-arrow-u-up-left',
            self::System => 'phosphor-gear',
        };
    }
}
