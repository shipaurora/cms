<?php

namespace Aurora\Http\Controllers;

use Aurora\Enums\Api;
use Aurora\Filament\Pages\SiteSettings;
use Aurora\Support\CurrentSite;
use Aurora\Support\PublicApi;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;

/**
 * The REST API's explorer (Swagger UI), for those who may change the API's settings: its
 * endpoints, from the API's own routes (OpenApiSpecController), to read and try out.
 */
class RestApiExplorerController
{
    public function __invoke(): View
    {
        Gate::authorize('manage-settings');

        return view('aurora::api.rest-explorer', [
            'specUrl' => route('aurora.explorer.openapi'),
            'enabled' => PublicApi::enabled(Api::Rest),
            'settingsUrl' => SiteSettings::getUrl(['tab' => 'integrations', 'section' => 'rest-api'], panel: 'admin', tenant: CurrentSite::default()),
        ]);
    }
}
