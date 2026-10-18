# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [1.0.0] - 2026-10-18

First public release.

### Added
- MCP server at `/mcp` (Streamable HTTP, stateless, JSON responses; protocol versions 2024-11-05 to 2025-11-25).
- OAuth 2.1 authorization server: discovery (RFC 8414, RFC 9728), dynamic client registration, consent
  page with Read / Control / Change files scopes, PKCE S256, rotating refresh tokens with reuse detection,
  revocation, per-user connection cap.
- 33 tools for servers, console, files (including binary upload/download, upload links and jar/class
  inspection), schedules, startup variables, activity, server limits, an operations guide and optional
  read-only SQL.
- Account → Connected Apps card and a server-rendered connections page; email to the account owner on
  every new connection.
- Activity log entries for every action, written once as the connected user.
- `ProvidesTools` extension point so other extensions can add tools.
- `panel-mcp:guide` command to install the operations guide.
- Admin setting for extra instructions sent to every client.
- End-to-end test suite against a fake Wings node.

[1.0.0]: https://github.com/jackh54/pterodactyl-panel-mcp/releases/tag/v1.0.0
