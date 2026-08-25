-- Extensions the schema depends on. Runs once, on first cluster creation.
--
-- Migrations must NOT create extensions: on a managed PostgreSQL the
-- application role usually lacks the privilege, so a migration that works
-- locally would fail on the real database.

-- gen_random_uuid() for primary keys.
CREATE EXTENSION IF NOT EXISTS pgcrypto;

-- Case-insensitive text, for email and slug columns.
CREATE EXTENSION IF NOT EXISTS citext;

-- Trigram indexes, for course/user search without a separate search engine.
CREATE EXTENSION IF NOT EXISTS pg_trgm;

-- Accent-insensitive search, so "Ibadan" matches "Ìbàdàn".
CREATE EXTENSION IF NOT EXISTS unaccent;
