# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [1.1.0] - 2026-10-28

### Added
- Backups: `list_backups`, `create_backup` (name, lock, ignore patterns), `download_backup` (short-lived link),
  `restore_backup`, `delete_backup` and `set_backup_lock`.
- Ports: `list_allocations`, `add_allocation`, `update_allocation` (notes, primary) and `remove_allocation`.
- Databases: `list_databases`, `create_database`, `rotate_database_password` and `delete_database`. Passwords are
  returned only to connections with the new Manage scope, for accounts with `database.view_password`.
- Server settings: `rename_server`, `reinstall_server`, and `set_docker_image` for the images the server's egg offers.
- A fourth OAuth scope, `panel:manage` ("Manage backups, ports and databases"), for the tools that restore or delete
  data, change ports and databases, or reinstall a server. Connections approved before 1.1 do not get it until the
  user connects again and ticks it.
- `get_server` shows the server's backup, database and port limits, and the Docker images it may switch to.
- CI checks that every panel class, interface and enum the extension uses still exists on upstream `2.0-develop`.

### Changed
- `create_backup` joins the Control scope, and the consent page describes each scope's new tools.
- Restoring a backup, adding a port and creating a database count against the panel's own per-server rate limits,
  shared with the panel's web UI.
- The new tools resolve the panel's actions only when they run, and a tool whose action the panel build lacks is not
  listed, so older 2.0 builds lose that tool rather than the whole server.
- Client instructions now ask for confirmation before restores, database deletes and reinstalls, and suggest a backup
  before big changes.
- GitHub Actions moved to `actions/checkout`, `actions/setup-node` and `actions/upload-artifact` v7; npm dev
  dependencies refreshed within their ranges.

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

[1.1.0]: https://github.com/jackh54/pterodactyl-panel-mcp/releases/tag/v1.1.0
[1.0.0]: https://github.com/jackh54/pterodactyl-panel-mcp/releases/tag/v1.0.0
