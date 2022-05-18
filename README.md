# vaultpress-db-import
PHP Script to import VaultPress database backup files.

Copy this script to a folder where you have extracted all the sql files.

** Usage: 
php vaultpress_db_import.php

** Recommended settings in php.ini
display_error = On
memory_limit = 2G


** Recommended settings in my.ini
[mysqld]
max_allowed_packet = 2G
