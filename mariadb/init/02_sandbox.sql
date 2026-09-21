SET NAMES utf8mb4;

CREATE DATABASE IF NOT EXISTS sandbox CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

GRANT ALL PRIVILEGES ON sandbox.* TO 'student'@'%';

CREATE USER IF NOT EXISTS 'readonly'@'%' IDENTIFIED BY 'readonly';
GRANT SELECT ON learn.* TO 'readonly'@'%';

FLUSH PRIVILEGES;
