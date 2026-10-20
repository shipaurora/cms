<?php

namespace Aurora\Http\Controllers;

use Aurora\Enums\Api;
use Aurora\Filament\Pages\SiteSettings;
use Aurora\Support\CurrentSite;
use Aurora\Support\PublicApi;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;

/**
 * The GraphQL API's playground (GraphiQL), for those who may change the API's settings: it
 * reads the schema from the API itself, so its documentation is always the API's own.
 */
class GraphqlPlaygroundController
{
    public function __invoke(): View
    {
        Gate::authorize('manage-settings');

        return view('aurora::api.graphql-playground', [
            'endpoint' => url(PublicApi::GRAPHQL_PATH),
            'enabled' => PublicApi::enabled(Api::Graphql),
            'needsKey' => PublicApi::needsKey(Api::Graphql),
            'settingsUrl' => SiteSettings::getUrl(['tab' => 'integrations', 'section' => 'graphql'], panel: 'admin', tenant: CurrentSite::default()),
        ]);
    }
}
