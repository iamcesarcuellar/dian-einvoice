-- The database of the integration tests, apart from the one of the local stack. make test-backend runs this
-- as the MySQL root user; the tests themselves recreate its tables on every run.
CREATE DATABASE IF NOT EXISTS dian_einvoice_test;
GRANT ALL PRIVILEGES ON dian_einvoice_test.* TO 'dian'@'%';
-- The migrations identity of the tests of php artisan db:application-identity: it may change the schema of the test
-- database, create the users of those tests and grant them what it holds there, as the migrations identity of the
-- functions does on the database of the emulated cloud (infra/functions/scripts/database.mjs). Accounts with SYSTEM_USER,
-- such as root, stay out of its reach.
CREATE USER IF NOT EXISTS 'dian_test_migrations'@'%' IDENTIFIED BY 'dian-test-migrations';
GRANT ALL PRIVILEGES ON dian_einvoice_test.* TO 'dian_test_migrations'@'%' WITH GRANT OPTION;
GRANT CREATE USER ON *.* TO 'dian_test_migrations'@'%';
