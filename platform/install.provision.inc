<?php
/**
 * @file
 *   Provision hooks for the provision install command.
 */

/**
 * Provision install command
 *
 * These are the hooks that will be executed by the drush_invoke function
 * when doing a provision_install.
 */

/**
 * Check that we are trying to install a new site , and a new site only
 */
function drush_provision_drupal_provision_install_validate() {

  if (!d()->uri) {
    return drush_set_error("PROVISION_URL_REQUIRED", dt("You need to specify a valid url to install a site"));
  }
  if (_provision_drupal_site_exists()) {

    // If "force-reinstall" option is set, delete the database and files.
    if (drush_get_option('force-reinstall', FALSE)) {

      drush_log(dt('Forcing reinstall...'), 'ok');

      // Drop the database if we can figure out what it is.
      // If last install failed, the database and the site folder is deleted.
      if (file_exists(d()->site_path . '/drushrc.php')) {

        // Load the current database name from drushrc.php.
        // I cannot find another way to find the current db_name!
        require_once(d()->site_path . '/drushrc.php');
        $old_db_name = $options['db_name'];

        if (d()->service('db')->database_exists($old_db_name)) {
          d()->service('db')->drop_database($old_db_name);
          drush_log(dt('Dropped database @database.', array(
            '@database' => $old_db_name,
          )), 'ok');
        }
      }

      // Destroy site_path.
      if (file_exists(d()->site_path)) {
        provision_file()->chmod(d()->site_path, 0755, TRUE);
        _provision_recursive_delete( d()->site_path );
        drush_log(dt('Deleted @site_path.', array('@site_path' => d()->site_path)), 'ok');
      }
    }

    // Check again if site does not exist after the forced reinstall.
    if (_provision_drupal_site_exists()) {
      return drush_set_error('PROVISION_SITE_INSTALLED',  dt('The file !file still exists. Provision was unable to delete it.', array(
        '!file' => d()->site_path . '/settings.php',
      )));
    }
  }
}

/**
 * Set up the directories and settings.php file that we need.
 */
function drush_provision_drupal_pre_provision_install() {
  // This is the actual drupal provisioning requirements.
  _provision_drupal_create_directories();
}

/**
 * If the install went south, and the site is not PROVISION_SITE_INSTALLED, clean up behind ourselves
 */
function drush_provision_drupal_pre_provision_install_rollback() {
  provision_file()->chmod(d()->site_path, 0755);
  _provision_recursive_delete( d()->site_path );
  drush_invoke_process('@none', 'provision-save', array(d()->name), array('delete' => TRUE));
  foreach (d()->drush_aliases as $alias) {
    drush_invoke_process('@none', 'provision-save', array($alias), array('delete' => TRUE));
  }
}


/**
 * Install Drupal with the pre-configured settings, by calling an external script
 *
 * This is an external script so that php is running in it's own namespace, and
 * weird problems such as the multiple database connections don't confuse drupal.
 */
function drush_provision_drupal_provision_install() {
  provision_file()->chmod(d()->site_path, 0755, TRUE);
  // Requires at least the database settings to complete.
  provision_prepare_environment();
  _provision_drupal_create_settings_file();
  provision_drupal_push_site($override_slave_authority = TRUE);
  provision_save_site_data();

  $drupal_core_version = provision_get_drupal_core_version();
  drush_log('provision_install Detected core ' . $drupal_core_version, 'ok');

  // Still somewhat experimental (works for D10, and hostmaster sort of, but not much tested otherwise)
  $site_name = ltrim(d()->name, '@');

  // Tested with: hostmaster, Drupal 10, Drupal 7
  drush_log('provision_install: chdir: ' . d()->site_path, 'ok');
  chdir(d()->site_path);

  $client_email = drush_get_option('client_email');
  $profile = drush_get_option('profile') ?? '';

  $aegir_db_user = drush_get_option('db_user');
  $aegir_db_pass = drush_get_option('db_passwd');
  $aegir_db_port = drush_get_option('db_port');
  $aegir_db_host = drush_get_option('db_host');
  $aegir_db_name = drush_get_option('db_name');

  if ($profile) {
    $profile = escapeshellarg($profile);
  }

  // Workaround because d7 complains about this address
  if ($client_email == 'root@localhost') {
    $client_email = 'root@' . gethostbyaddr(gethostbyname(gethostname()));
  }
  // And somehow for d7 hostmaster, we have this instead of the FQDN
  if ($site_name == 'hostmaster') {
    $site_name = gethostbyaddr(gethostbyname(gethostname()));
  }

  $db = "mysql://$aegir_db_user:$aegir_db_pass@$aegir_db_host:$aegir_db_port/$aegir_db_name";
  $command = '../../../vendor/drush/drush/drush site-install ' . $profile . ' --db-url=' . $db . ' --site-name=' . $site_name . ' --sites-subdir=' . $site_name . ' --site-mail=' . $client_email . ' --locale=en';
  if (version_compare($drupal_core_version, 10, '<')) {
    $command = 'drush -y site-install ' . $profile . ' --db-url=' . $db . ' --site-name=' . $site_name . ' --sites-subdir=' . $site_name . ' --site-mail=' . $client_email . ' --locale=en';
  }
  drush_log(dt("Drupal: running: %command", ['%command' => $command]), 'ok');
  $output = system($command, $retval);
  drush_log(dt("Drupal: output: %output", ['%output' => $output]), 'ok');

  // Fix permissions
  drush_log(dt("Drupal: running fix-drupal-site-ownership.sh and permissions..."), 'ok');
  drush_shell_exec("sudo --non-interactive /usr/local/bin/fix-drupal-site-ownership.sh --site-path=%s --script-user=%s --web-group=%s", d()->site_path, d()->server->script_user, d()->server->web_group);
  drush_shell_exec("sudo --non-interactive /usr/local/bin/fix-drupal-site-permissions.sh --site-path=%s --script-user=%s --web-group=%s", d()->site_path, d()->server->script_user, d()->server->web_group);
  drush_log(dt("Drupal: finished running fix-drupal-site-ownership.sh and permissions"), 'ok');
}

/**
 * Finish the installation, regenerate the caches on the site so that
 * any changes to things such as available modules/ themes can take affect.
 */
function drush_provision_drupal_post_provision_install() {

  // If Drupal profile was installed, rebuild drupal caches and save enabled packages for aegir.
  if (d()->install_method == 'profile' && drush_get_option('profile_installed', FALSE, 'site')) {
    if (drush_drupal_major_version() < 12) {
      _provision_drupal_rebuild_caches();
    }
    drush_set_option('packages', _scrub_object(provision_drupal_system_map()), 'site');
    _provision_client_create_symlink();
    drush_include_engine('drupal', 'cron_key');
  }
  // For all other install methods, just create the symlink.
  else {
    _provision_client_create_symlink();
  }
}
