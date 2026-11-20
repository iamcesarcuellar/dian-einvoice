-- The database of the emulated cloud (make cloud-up), apart from the one of the local stack, in the same MySQL:
-- its documents live in the bucket of the emulator and its secrets under the keys of the emulator, so the two
-- environments never share data. make cloud-up runs this as the MySQL root user; make cloud-clean drops it.
CREATE DATABASE IF NOT EXISTS dian_einvoice_cloud;
GRANT ALL PRIVILEGES ON dian_einvoice_cloud.* TO 'dian'@'%';
