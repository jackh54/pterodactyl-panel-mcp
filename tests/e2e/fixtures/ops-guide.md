# Example network: operations guide (test fixture)

A short guide with the heading layout get_ops_guide understands: level-2 headings, numbered
sections, optional letter suffixes.

---

## 1. Ground rules (read first)

1. **Read-only by default.** Look, measure and report. Ask before changing anything.
2. **Live players.** Plan a rollback before any change.

## 2b. Backups

- Nightly at 04:30 UTC. Check the newest snapshot before a risky change.

## 2c. Backup restores

- Restore into a copy first, never over the live world.

## 4. Restarts

- Use the server's restart plugin with a countdown (`restart-safely 300`), not a hard stop.

## 5. Plugins

- Jars go in /plugins and load at the next restart.

## 7. Dangerous commands

- NEVER run `/somecommands reload` on the survival server: it unregisters every listener.
