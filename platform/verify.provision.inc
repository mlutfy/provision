<?php

/**
 * @file
 * Code related to verify tasks.
 */

/**
 * Provision verify command.
 *
 * Disable an enabled and installed site
 */
function drush_provision_drupal_provision_verify_validate() {
  if (d()->type === 'site') {
    provision_prepare_environment();
    _provision_drupal_create_settings_file();
    provision_drupal_push_site();
    drush_errors_on();
  }
}


/**
 * Implements drush_hook_pre_COMMAND().
 */
function drush_provision_drupal_pre_provision_verify() {
  if (d()->type === 'server') {

    $config_root = dirname(d()->config_path);
    provision_file()->create_dir($config_root, dt('Provision configuration root'), 0711);
    provision_file()->create_dir(d()->config_path, dt('Provision configuration'), 0711);
    provision_file()->create_dir(d()->clients_path, dt('Provision client home'), 0750);
    if (d()->name == '@server_master') {
      provision_file()->create_dir(d()->backup_path, dt('Backup'), 0750);
      provision_file()->create_dir(d()->include_path, dt('Provision PHP configuration'), 0711);
      if (!provision_file()->exists(d()->include_path . '/global.inc')->succeed('Global configuration file exists')->status()) {
        // Create an empty global.inc so the include doesn't fail with
        // open_basedir restrictions.
        $config = new Provision_Config_Global_Settings(d()->name);
        $config->write();
      }
    }
    else {
      if (provision_file()->exists(d()->include_path)) {
        d()->service('http')->sync(d()->include_path);
      }
    }
  }
  elseif (d()->type === 'platform') {

    // Add platform root auto-discovery to avoid confusing
    // Composer based D8+ codebase root with Drupal real root.
    d()->root = provision_auto_fix_platform_root(d()->root);

    drush_log(dt("Checking for platform at path @path...", array('@path' => d()->root)), 'debug');
    drush_log(dt("Platform makefile: @makefile", array('@makefile' => d()->makefile)), 'debug');

    // Re-set the ROOT PATH for Drush.
    // In cases where we just build the platform Drush would have cleared this value.
    // See Drush commit 3b74d40b1228f022464b92243c16127eb613c2df
    drush_set_context('DRUSH_SELECTED_DRUPAL_ROOT', d()->root);

    // Fix ownership early enough
    $success = drush_shell_exec("sudo --non-interactive /usr/local/bin/fix-drupal-platform-ownership.sh --root=%s --script-user=%s --web-group=%s", d()->root, d()->server->script_user, d()->server->web_group);

    provision_file()->writable(d()->root . '/sites')
      ->succeed('Drupal sites directory @path is writable by the provisioning script')
      ->fail('Drupal sites directory @path is not writable by the provisioning script', 'PROVISION_SITES_DIR_NOT_WRITABLE');

    // Ensure sites.php file exists for Drupal 8 and above.
    $sitesfile = "sites/sites.php";
    if (drush_drupal_major_version() >= 8 && !file_exists($sitesfile)) {
      if (!drush_op('copy', 'sites/example.sites.php', $sitesfile) && !drush_get_context('DRUSH_SIMULATE')) {
        return drush_set_error(dt('Failed to copy sites/sites.php to @sitesfile', array('@sitesfile' => $sitesfile)));
      }
    }

    // $drupal_version = provision_get_drupal_core_version();
    // $drupal_major = explode('.', $drupal_version)[0];
    drush_set_option('sites', array_keys((array) provision_drupal_find_sites()), 'drupal');
    drush_log(dt("This platform is running @short_name @version", array('@short_name' => 'drupal', '@version' => provision_get_drupal_core_version())), 'ok');
    // require_once __DIR__ . '/../platform/drupal/packages_' . $drupal_major . '.inc';
    drush_set_option('packages', _scrub_object(provision_find_packages()), 'drupal');

    // Determine latest BOA contrib directory name and add INI template if missing.
    $pathSal = d()->root . '/sites/all';
    $pathDrc = d()->root . '/sites/all/drush';

    if (!provision_file()->exists($pathSal)->status()) {
      provision_file()->create_dir($pathSal, dt("Sites All dir created"), 0755);
    }
    if (!provision_file()->exists($pathDrc)->status()) {
      provision_file()->create_dir($pathDrc, dt("Sites All Drush dir created"), 0755);
    }

    if (file_exists(d()->root . '/sites/all/modules')) {
      provision_file()->chmod(d()->root . '/sites/all/modules', 02775)
        ->succeed('Changed permissions of <code>@path</code> to @perm')
        ->fail('Could not change permissions of <code>@path</code> to @perm');
      provision_file()->chmod(d()->root . '/sites/all/themes', 02775)
        ->succeed('Changed permissions of <code>@path</code> to @perm')
        ->fail('Could not change permissions of <code>@path</code> to @perm');
      provision_file()->chmod(d()->root . '/sites/all/libraries', 02775)
        ->succeed('Changed permissions of <code>@path</code> to @perm')
        ->fail('Could not change permissions of <code>@path</code> to @perm');
      provision_file()->chmod(d()->root . '/sites/all', 0755)
        ->succeed('Changed permissions of <code>@path</code> to @perm')
        ->fail('Could not change permissions of <code>@path</code> to @perm');
      provision_file()->chmod(d()->root . '/sites', 0751)
        ->succeed('Changed permissions of <code>@path</code> to @perm')
        ->fail('Could not change permissions of <code>@path</code> to @perm');
    }

    if (provision_file()->exists($pathDrc)->status()) {
      provision_file()->chmod(d()->root . '/sites/all/drush', 0755)
        ->succeed('Changed permissions of <code>@path</code> to @perm')
        ->fail('Could not change permissions of <code>@path</code> to @perm');
    }

    provision_drupal_push_site();
  }
  elseif (d()->type === 'site') {

    // Re-set the ROOT PATH for Drush.
    // In cases where we just build the platform Drush would have cleared this value.
    // See Drush commit 3b74d40b1228f022464b92243c16127eb613c2df
    drush_set_context('DRUSH_SELECTED_DRUPAL_ROOT', d()->root);
    // [ML] SYMBIOTIC Skipping this, which also means we do not manage platform packages in Aegir anymore
    // drush_set_option('packages', _scrub_object(provision_drupal_system_map()), 'site');
    // This is the actual drupal provisioning requirements.
    _provision_drupal_create_directories();
    _provision_drupal_maintain_aliases();
    _provision_drupal_ensure_htaccess_update();
    // Requires at least the database settings to complete.

    _provision_drupal_create_settings_file();

    // If this is the hostmaster site, save the ~/.drush/drushrc.php file.
    $hostmaster = provision_sitealias_get_record('@hostmaster');
    if (d()->root == $hostmaster['root'] && d()->uri == $hostmaster['uri']) {
      d()->service('db')->fetch_site_credentials();
      $aegir_drushrc = new Provision_Config_Drushrc_Aegir();
      $aegir_drushrc->write();

      drush_invoke_process('@none', "cache-clear", array('drush'));
    }

    provision_drupal_push_site(drush_get_option('override_slave_authority', FALSE));
  }
}

/**
 * Implements drush_hook_provision_post_COMMAND().
 */
function drush_provision_drupal_post_provision_verify() {
  // Add platform root auto-discovery to avoid confusing
  // Composer based D8+ codebase root with Drupal real root.
  d()->root = provision_auto_fix_platform_root(d()->root);

  if (d()->type === 'site') {
    $drupal_core_version = provision_get_drupal_core_version();

    if ($drupal_core_version && version_compare($drupal_core_version, 8, '>=')) {
      $command = "drush cr";
      drush_log(dt("Drupal: running: %command", ['%command' => $command]), 'ok');
      chdir(d()->site_path);
      $output = system($command, $retval);
      drush_log(dt("Drupal: output: %output", ['%output' => $output]), 'ok');
    }
    else {
      $command = "drush cc all";
      drush_log(dt("Drupal: running: %command", ['%command' => $command]), 'ok');
      chdir(d()->site_path);
      $output = system($command, $retval);
      drush_log(dt("Drupal: output: %output", ['%output' => $output]), 'ok');
    }
    drush_set_option('installed', TRUE, 'site');
    _provision_client_create_symlink();
    drush_include_engine('drupal', 'cron_key');
  }
}
