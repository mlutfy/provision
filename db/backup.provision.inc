<?php

function drush_db_pre_provision_backup() {
  d()->service('db')->generate_dump();
}

function drush_db_pre_provision_backup_rollback() {
  provision_file()->chmod(d()->site_path, 0755);
  provision_file()->unlink(d()->site_path . '/database.sql')
    ->succeed('Deleted mysql dump from sites directory')
    ->fail('Could not delete mysql dump from sites directory');
  provision_file()->chmod(d()->site_path, 0555);
}

function drush_db_post_provision_backup() {
  drush_db_pre_provision_backup_rollback();
}

