<?php

namespace Aurora\Filament\Pages;

use Aurora\Filament\Concerns\TranslatesPageLabels;
use BackedEnum;
use Filament\Clusters\Cluster;
use Filament\Pages\Enums\SubNavigationPosition;
use UnitEnum;

/**
 * "Users & Roles": one sidebar entry for the people who work in the CMS and what their roles
 * let them do, with a side menu (Users, Roles) between the two.
 */
class UsersAndRoles extends Cluster
{
    use TranslatesPageLabels;

    protected static string|BackedEnum|null $navigationIcon = 'phosphor-users-three';

    protected static string|BackedEnum|null $activeNavigationIcon = 'phosphor-users-three-bold';

    protected static string|UnitEnum|null $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Users & Roles';

    protected static ?string $clusterBreadcrumb = 'Users & Roles';

    protected static ?int $navigationSort = 1;

    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Start;

    /**
     * Users and Roles, once each: the roles screen is registered twice, found among
     * Aurora's resources and by the roles-and-permissions plugin.
     *
     * @return array<class-string>
     */
    public static function getClusteredComponents(): array
    {
        return array_values(array_unique(parent::getClusteredComponents()));
    }

    public static function getClusterBreadcrumb(): ?string
    {
        return __(parent::getClusterBreadcrumb());
    }
}
