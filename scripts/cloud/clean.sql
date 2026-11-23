-- make cloud-clean: the database of the emulated cloud and its two identities, as the MySQL root user. The users are
-- those of the CDK app's local stage (infra/cdk/lib/stage.ts), and the second user of the application identity
-- that the alternating rotation of make cloud-check creates.
DROP DATABASE IF EXISTS dian_einvoice_cloud;
DROP USER IF EXISTS 'dian_cloud_migrations'@'%', 'dian_cloud_app'@'%', 'dian_cloud_app_clone'@'%';
