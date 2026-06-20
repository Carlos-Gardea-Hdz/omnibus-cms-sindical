-- Auto-run on first pgsql container init. Creates the dedicated testing DB so
-- Pest Feature tests (RefreshDatabase) run against PostgreSQL 18 — never SQLite.
CREATE DATABASE cms_testing;
GRANT ALL PRIVILEGES ON DATABASE cms_testing TO cms;
