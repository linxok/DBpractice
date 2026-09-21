SELECT 'CREATE DATABASE sandbox OWNER student'
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'sandbox')\gexec

DO $$
BEGIN
    IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'readonly') THEN
        CREATE ROLE readonly LOGIN PASSWORD 'readonly';
    END IF;
END
$$;

GRANT CONNECT ON DATABASE learn TO readonly;
GRANT USAGE ON SCHEMA shop TO readonly;
GRANT SELECT ON ALL TABLES IN SCHEMA shop TO readonly;
ALTER DEFAULT PRIVILEGES IN SCHEMA shop GRANT SELECT ON TABLES TO readonly;
