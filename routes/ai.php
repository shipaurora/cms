<?php

use Aurora\Mcp\Servers\CmsServer;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server\Middleware\AddWwwAuthenticateHeader;

// OAuth discovery, dynamic client registration and the authorization flow (Passport), so
// Claude Desktop and claude.ai can connect as a custom connector: paste the URL, sign in,
// approve. The consent screen is resources/views/oauth/authorize.blade.php.
Mcp::oauthRoutes();

// Streamable HTTP endpoint. Accepts OAuth access tokens and personal access tokens
// (created on the "Connect assistant" page) through the Passport "api" guard.
Mcp::web('/mcp', CmsServer::class)
    ->middleware([AddWwwAuthenticateHeader::class, 'auth:api', 'throttle:120,1']);

// Local stdio server for development: `php artisan mcp:start cms` (acts as MCP_USER).
Mcp::local('cms', CmsServer::class);
