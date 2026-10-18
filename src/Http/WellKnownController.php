<?php

namespace PanelMcp\Http;

use Illuminate\Http\JsonResponse;
use PanelMcp\OAuthService;

/**
 * OAuth discovery documents for the MCP endpoint. Mounted by PanelMcpProvider at
 * /.well-known/oauth-protected-resource[/mcp] and /.well-known/oauth-authorization-server[/mcp]
 * (no session, no cookies).
 */
final class WellKnownController
{
    public function __construct(private readonly OAuthService $oauth) {}

    /**
     * RFC 9728: tells an MCP client which authorization server protects /mcp.
     */
    public function protectedResource(): JsonResponse
    {
        return new JsonResponse([
            'resource' => $this->oauth->resource(),
            'authorization_servers' => [$this->oauth->issuer()],
            'scopes_supported' => array_keys(OAuthService::SCOPES),
            'bearer_methods_supported' => ['header'],
            'resource_name' => config('app.name', 'Pterodactyl'),
        ]);
    }

    /**
     * RFC 8414 authorization server metadata.
     */
    public function authorizationServer(): JsonResponse
    {
        $issuer = $this->oauth->issuer();

        return new JsonResponse([
            'issuer' => $issuer,
            'authorization_endpoint' => "{$issuer}/oauth/authorize",
            'token_endpoint' => "{$issuer}/oauth/token",
            'registration_endpoint' => "{$issuer}/oauth/register",
            'revocation_endpoint' => "{$issuer}/oauth/revoke",
            'scopes_supported' => array_keys(OAuthService::SCOPES),
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'revocation_endpoint_auth_methods_supported' => ['none'],
            'code_challenge_methods_supported' => ['S256'],
            'authorization_response_iss_parameter_supported' => true,
        ]);
    }
}
