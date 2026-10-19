<?php

namespace Aurora\Enums;

use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * The website's read-only APIs (Aurora\Support\PublicApi), each switched on under Settings ›
 * Integrations. Both take the same API keys (Aurora\Models\ApiKey).
 */
enum Api: string implements HasIcon, HasLabel
{
    case Rest = 'rest';
    case Graphql = 'graphql';

    public function getLabel(): string
    {
        return match ($this) {
            self::Rest => __('REST API'),
            self::Graphql => __('GraphQL API'),
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Rest => 'phosphor-brackets-curly',
            self::Graphql => 'phosphor-graph',
        };
    }

    /**
     * Its settings: switched on, and whether the key is needed.
     */
    public function setting(): string
    {
        return $this->value.'_api';
    }
}
