<?php

/**
 * Returns a list of array('db-user', 'db-name', 'SQL commands or SQL filename').
 * All filenames must match following regexp: /(\.sql|\.gz)$/i.
 *
 * Available/injected variables: $sDbUser, $sDbName.
 *
 * @var $sDbName string Injected DB name.
 * @var $sDbUser string Injected DB username.
 */

return array(
    array('postgres', 'template1', "DROP DATABASE IF EXISTS $sDbName;"),

    // MySQL : GRANT ALL ON `database`.* TO 'user'@'localhost' IDENTIFIED BY 'password';
    array('postgres', 'template1', "DO $$ BEGIN
               IF NOT EXISTS (SELECT * FROM pg_catalog.pg_user WHERE usename='$sDbUser') THEN
                  CREATE ROLE $sDbUser LOGIN;
                  ALTER ROLE $sDbUser SET client_min_messages TO WARNING;
               END IF;
            END $$;"),

    array('postgres', 'template1', "CREATE DATABASE $sDbName WITH
            OWNER = $sDbUser
            TEMPLATE = template0
            ENCODING = 'UTF8'
            TABLESPACE = pg_default
            LC_COLLATE = 'en_US.UTF-8'
            LC_CTYPE = 'en_US.UTF-8'
            CONNECTION LIMIT = -1;"),
    array('postgres', 'template1', "
            COMMENT ON DATABASE $sDbName IS 'Temporary Data Warehouse for tests';
            ALTER DATABASE $sDbName SET TimeZone='UTC';
            ALTER DATABASE $sDbName SET constraint_exclusion = partition;"),
    array('postgres', $sDbName, 'CREATE EXTENSION hstore; CREATE EXTENSION unaccent;'),

    array($sDbUser, $sDbName, '/path/to/schemas.sql'),
    array($sDbUser, $sDbName, '/apth/to/some/data.sql.gz'),
);
