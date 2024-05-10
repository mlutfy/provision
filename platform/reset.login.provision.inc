<?php
/**
 * @file
 *   Provision hooks for the provision login_reset command.
 */

/**
 * Provision login-reset command
 *
 * These are the hooks that will be executed by the drush_invoke function
 * when generating a one-time login reset URL.
 */

/**
 * Generate a one-time login reset url
 */
function drush_provision_drupal_provision_login_reset() {
  chdir(d()->site_path);
  $command = 'drush uli';
  $output = system($command, $retval);

  if ($retval == 0) {
    drush_set_option('login_link', $output);
    drush_log(dt('Login url: !onetime', ['!onetime' => $output]), 'success');
  }
  else {
    drush_log(dt('Failed to run drush uli: %error', ['%error' => $output]), 'error');
  }
}
