-- Local databases and users. Mirrors production: the API user has DML only, the migrations user
-- also DDL. Passwords are for the local container only.
CREATE DATABASE yeastie CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE yeastie_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER 'yeastie_api'@'%' IDENTIFIED BY 'api-local';
GRANT SELECT, INSERT, UPDATE, DELETE ON yeastie.* TO 'yeastie_api'@'%';

CREATE USER 'yeastie_migrations'@'%' IDENTIFIED BY 'migrations-local';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES ON yeastie.* TO 'yeastie_migrations'@'%';

CREATE USER 'yeastie_test'@'%' IDENTIFIED BY 'test-local';
GRANT ALL PRIVILEGES ON yeastie_test.* TO 'yeastie_test'@'%';
