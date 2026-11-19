-- The database of the integration tests, apart from the one of the local stack. make test-backend runs this
-- as the MySQL root user; the tests themselves recreate its tables on every run.
CREATE DATABASE IF NOT EXISTS dian_einvoice_test;
GRANT ALL PRIVILEGES ON dian_einvoice_test.* TO 'dian'@'%';
