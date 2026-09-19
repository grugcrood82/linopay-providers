-- Post-init script for the smoke-test MySQL container.
--
-- The official MySQL image creates the user with `REQUIRE SSL` by
-- default under caching_sha2_password, but our smoke stack runs with
-- `--ssl=0` and the wp-cli mariadb client doesn't ship the TLS
-- plugin. Strip the SSL requirement so wp-cli can connect.
--
-- This file is mounted at `/docker-entrypoint-initdb.d/01-disable-ssl.sql`
-- inside the db container and runs once, after the entrypoint has
-- created the schema + user.
--
-- We have to do this for both `wordpress@%` (the wildcard-host user
-- the entrypoint creates) AND `wordpress@localhost` (which wp-cli
-- uses when connecting from inside the same container, if at all).
-- `IF EXISTS` makes the statement idempotent across the two.

ALTER USER IF EXISTS 'wordpress'@'%' REQUIRE NONE;
ALTER USER IF EXISTS 'wordpress'@'localhost' REQUIRE NONE;
FLUSH PRIVILEGES;
