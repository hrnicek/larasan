-- The Postgres image only creates POSTGRES_DB, so the test database is created here on first boot.
SELECT 'CREATE DATABASE pm_testing'
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'pm_testing')\gexec
