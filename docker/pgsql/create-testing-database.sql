-- The suite is pinned to pm_testing by phpunit.xml. The Postgres image only creates the database
-- named in POSTGRES_DB, so the second one is created here, once, on first boot of the volume.
SELECT 'CREATE DATABASE pm_testing'
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'pm_testing')\gexec
