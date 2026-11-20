-- The database of the emulated cloud (make cloud-up), apart from the one of the local stack, in the same MySQL:
-- its documents live in the bucket of the emulator and its secrets under the keys of the emulator, so the two
-- environments never share data. make cloud-up runs this as the MySQL root user, then creates the migrations
-- identity from its secret (infra/functions/scripts/database.mjs), which the console function migrates with and
-- creates the application identity with; make cloud-clean drops them (scripts/cloud/clean.sql). The single user
-- of the local stack keeps nothing here.
CREATE DATABASE IF NOT EXISTS dian_einvoice_cloud;
REVOKE IF EXISTS ALL PRIVILEGES ON dian_einvoice_cloud.* FROM 'dian'@'%';
