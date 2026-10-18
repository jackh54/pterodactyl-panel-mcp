# Security policy

Panel MCP gives AI clients access to game servers, so security reports are taken seriously.

## Reporting a vulnerability

Please **do not open a public issue**. Report it privately through
[GitHub's private vulnerability reporting](https://github.com/jackh54/pterodactyl-panel-mcp/security/advisories/new)
with:

- what an attacker can do, and what they need first (an account? a connected client? nothing?),
- steps to reproduce on a local panel,
- the panel commit (`2.0-develop`) and extension version.

You'll get an answer within a few days. Fixes are released as a new version with an advisory that
credits you, unless you prefer otherwise.

## Scope

In scope: the OAuth endpoints (`/oauth/*`, `/.well-known/oauth-*`), the MCP endpoint (`/mcp`), every
tool, the consent and Connected Apps pages, and token storage.

Out of scope: vulnerabilities in Pterodactyl itself (report those to the Pterodactyl project), in an
MCP client, or that need a root administrator account to exploit against itself.

## Operator checklist

- Serve the panel over HTTPS only.
- Keep "require two-factor authentication" on for at least administrators; MCP connections follow it.
- Give `query_database` a SELECT-only account limited to the databases you list, never the panel's database.
- Keep secrets out of the operations guide: every root administrator's client can read it.
- Review **Account → Connected Apps** now and then, and remove what you don't use.
