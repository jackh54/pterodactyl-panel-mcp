-- Test database for the query_database tool, plus the read-only account the panel uses for it.
-- Load with: docker --context orbstack exec -i v2dev-mariadb mariadb -uroot -pdevroot < tests/mcp/fixtures/sqldb.sql
DROP DATABASE IF EXISTS mcp_game;
CREATE DATABASE mcp_game;
USE mcp_game;
CREATE TABLE mcp_game.bans (
    id INT UNSIGNED NOT NULL PRIMARY KEY,
    name VARCHAR(32) NOT NULL,
    ip VARCHAR(45) NOT NULL,
    blob_col VARBINARY(16) NULL
);
INSERT INTO mcp_game.bans (id, name, ip, blob_col)
    SELECT seq, CONCAT('player', seq), CONCAT('10.0.', seq DIV 256, '.', seq MOD 256), UNHEX('00FF10') FROM seq_1_to_700;

DROP USER IF EXISTS 'mcp_ro'@'%';
CREATE USER 'mcp_ro'@'%' IDENTIFIED BY 'mcp-ro-test-only';
GRANT SELECT ON mcp_game.* TO 'mcp_ro'@'%';

-- The account of the fixture database host (seed.php: "mcp-test-dbhost"), which the panel uses for
-- create_database, rotate_database_password and delete_database. It may only create what the panel
-- names s<server id>_<name>; the SELECT on one help table lets it open the "mysql" schema, which the
-- panel connects to first. (Pattern s%_% because GRANT refuses to hand out a privilege held through a
-- pattern written with an escaped underscore.)
DROP USER IF EXISTS 'mcp_dbhost'@'%';
CREATE USER 'mcp_dbhost'@'%' IDENTIFIED BY 'mcp-dbhost-test-only';
GRANT CREATE USER, RELOAD ON *.* TO 'mcp_dbhost'@'%';
GRANT SELECT ON mysql.help_topic TO 'mcp_dbhost'@'%';
GRANT ALL PRIVILEGES ON `s%_%`.* TO 'mcp_dbhost'@'%' WITH GRANT OPTION;
