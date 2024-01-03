<?php


/**
 * @file
 *   Provision hooks for the install command
 */
function drush_http_provision_install() {
  d()->service('http')->create_config('site');
}

/**
 * Implementation of hook_provision_post_install
 */
function drush_http_post_provision_install() {
  d()->service('http')->parse_configs();
}

/**
 * Implementation of hook_provision_install_rollback()
 *
 * Remove the apache configs if we fail to install the site.
 */
function drush_http_provision_install_rollback() {
  // [ML] SYMBIOTIC Do not rollback, makes it very annoying to debug
  // d()->service('http')->delete_config('site');
}
