<?php
/**
 * @file
 * Provides the MySQL service driver.
 */

/**
 * The MySQL provision service.
 */
class Provision_Service_db_mysql extends Provision_Service_db_pdo {
  public $PDO_type = 'mysql';

  protected $has_port = TRUE;

  function default_port() {
    $script_user = d('@server_master')->script_user;
    if (!$script_user) {
      $script_user = drush_get_option('script_user');
    }
    if (!$script_user && $server->script_user) {
      $script_user = $server->script_user;
    }
    else {
      return 3306;
    }
  }

  function drop_database($name) {
    return $this->query("DROP DATABASE `%s`", $name);
  }

  function create_database($name) {
    return $this->query("CREATE DATABASE `%s`", $name);
  }

  function can_create_database() {
    $test = drush_get_option('aegir_db_prefix', 'site_') . 'tmp_test';
    $this->create_database($test);

    if ($this->database_exists($test)) {
      if (!$this->drop_database($test)) {
        drush_log(dt("Failed to drop database @dbname", array('@dbname' => $test)), 'warning');
      }
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Verifies that provision can grant privileges to a user on a database.
   *
   * @return
   *   TRUE if the check was successful.
   */
  function can_grant_privileges() {
    $dbname   = drush_get_option('aegir_db_prefix', 'site_') . 'tmp_test';
    $this->create_database($dbname);
    $user     = $dbname . '_user';
    $password = $dbname . '_password';
    $host     = $dbname . '_host';
    $status = $this->grant($dbname, $user, $password, $host);
    $this->revoke($dbname, $user, $host);
    $this->drop_database($dbname);
    return $status;
  }

  function grant($name, $username, $password, $host = '') {
    $host = '%';
    return $this->grant_privileges($name, $username, $password, $host);
  }

  function create_user($username, $host) {
    $statement = "CREATE USER IF NOT EXISTS `%s`@`%s`";
    return $this->query($statement, $username, $host);
  }

  function alter_user($username, $host, $password) {
    $statement = "ALTER USER `%s`@`%s` IDENTIFIED BY '%s'";
    return $this->query($statement, $username, $host, $password);
  }

  function grant_privileges($name, $username, $password, $host) {
    $user_created = $this->create_user($username, $host);
    $user_altered = $this->alter_user($username, $host, $password);
    if (!$user_created) {
      drush_log(dt("Failed to create db_user @name", array('@name' => $username)), 'error');
      return $user_created;
    }
    if (!$user_altered) {
      drush_log(dt("Failed to alter db_user @name", array('@name' => $username)), 'error');
      return $user_altered;
    }
    $statement = "GRANT ALL PRIVILEGES ON `%s`.* TO `%s`@`%s`";
    return $this->query($statement, $name, $username, $host);
  }

  function revoke($name, $username, $host = '') {
    $host = '%';
    drush_command_invoke_all_ref('provision_db_username_alter', $username, '', 'revoke');
    $success = $this->query("REVOKE ALL PRIVILEGES ON `%s`.* FROM `%s`@`%s`", $name, $username, $host);

    // check if there are any privileges left for the user
    $grants = $this->query("SHOW GRANTS FOR `%s`@`%s`", $username, $host);
    $grant_found = FALSE;
    if ($grants) {
      while ($grant = $grants->fetch()) {
        // those are empty grants: just the user line
        if (!preg_match("/^GRANT USAGE ON /", array_pop($grant))) {
          // real grant, we shouldn't remove the user
          $grant_found = TRUE;
          break;
        }
      }
    }
    if (!$grant_found) {
      $success = $this->query("DROP USER `%s`@`%s`", $username, $host) && $success;
    }

    if ($host != "127.0.0.1") {
      $extra_host = "127.0.0.1";
      $success_extra_host = $this->query("REVOKE ALL PRIVILEGES ON `%s`.* FROM `%s`@`%s`", $name, $username, $extra_host);

      // check if there are any privileges left for the user
      $grants = $this->query("SHOW GRANTS FOR `%s`@`%s`", $username, $extra_host);
      $grant_found = FALSE;
      if ($grants) {
        while ($grant = $grants->fetch()) {
          // those are empty grants: just the user line
          if (!preg_match("/^GRANT USAGE ON /", array_pop($grant))) {
            // real grant, we shouldn't remove the user
            $grant_found = TRUE;
            break;
          }
        }
      }
      if (!$grant_found) {
        $success_extra_host = $this->query("DROP USER `%s`@`%s`", $username, $extra_host) && $success_extra_host;
      }
    }

    return $success;
  }

  function import_dump($dump_file, $creds) {
    chdir(d()->site_path);
    $output = [];
    // This works on drush 12.5, otherwise we may need to check the core version for 'sql:cli'?
    // Before using drush, this function used to call mysql directly, with
    // named pipes (safe_shell_exec), and it would be difficult to debug.
    $cmd = 'drush --uri ' . d()->uri . ' sql-cli < ' . $dump_file;
    drush_log(sprintf("Importing database using command: %s", $cmd), 'info');
    $ret = exec($cmd, $output);
    if ($ret === FALSE) {
      drush_set_error('PROVISION_DB_IMPORT_FAILED', dt("Database import failed: %output", ['%output' => implode('; ', $output)]));
    }
  }

  function grant_host(Provision_Context_server $server) {
    // [ML] Dummy connection failed to fail. Either your MySQL permissions are too lax, or the response was not understood. See http://is.gd/Y6i4FO for more information. ERROR at line 1: Unknown command '\('.
    return $this->server->remote_host;

    $user = 'intntnllyInvalid';
    drush_command_invoke_all_ref('provision_db_username_alter', $user, $this->server->remote_host);

    $command = sprintf('mysql -u %s -h %s -P %s -e "SELECT VERSION()"',
      escapeshellarg($user),
      escapeshellarg($this->server->remote_host),
      escapeshellarg($this->server->db_port));

    $server->shell_exec($command);
    $output = implode('', drush_shell_exec_output());
    if (preg_match("/Access denied for user 'intntnllyInvalid'@'([^']*)'/", $output, $match)) {
      return $match[1];
    }
    elseif (preg_match("/Host '([^']*)' is not allowed to connect to/", $output, $match)) {
      return $match[1];
    }
    elseif (preg_match("/ERROR 2002 \(HY000\): Can't connect to local MySQL server through socket '([^']*)'/", $output, $match)) {
      return drush_set_error('PROVISION_DB_CONNECT_FAIL', dt('Local database server not running, or not accessible via socket (%socket): %msg', array('%socket' => $match[1], '%msg' => join("\n", drush_shell_exec_output()))));
    }
    elseif (preg_match("/ERROR 2003 \(HY000\): Can't connect to MySQL server on/", $output, $match)) {
      return drush_set_error('PROVISION_DB_CONNECT_FAIL', dt('Connection to database server failed: %msg', array('%msg' => join("\n", drush_shell_exec_output()))));
    }
    elseif (preg_match("/ERROR 2005 \(HY000\): Unknown MySQL server host '([^']*)'/", $output, $match)) {
      return drush_set_error('PROVISION_DB_CONNECT_FAIL', dt('Cannot resolve database server hostname (%host): %msg', array('%host' => $match[1], '%msg' => join("\n", drush_shell_exec_output()))));
    }
    else {
      drush_log("DEBUG GRANT: $command", 'ok');
      return drush_set_error('PROVISION_DB_CONNECT_FAIL', dt('Dummy connection failed to fail. Either your MySQL permissions are too lax, or the response was not understood. See http://is.gd/Y6i4FO for more information. %msg', array('%msg' => join("\n", drush_shell_exec_output()))));
    }
  }

  /**
   * Generate the contents of a mysql config file containing database
   * credentials.
   */
  function generate_mycnf($db_host = NULL, $db_user = NULL, $db_passwd = NULL, $db_port = NULL) {
    // Look up defaults, if no credentials are provided.
    if (is_null($db_host)) {
      $db_host = drush_get_option('db_host');
    }
    if (is_null($db_user)) {
      $db_user = urldecode(drush_get_option('db_user'));
    }
    drush_command_invoke_all_ref('provision_db_username_alter', $db_user, $db_host);
    if (is_null($db_passwd)) {
      $db_passwd = urldecode(drush_get_option('db_passwd'));
    }
    if (is_null($db_port)) {
      $db_port = $this->server->db_port;
    }

    $mycnf = sprintf('[client]
host=%s
user=%s
password="%s"
port=%s
', $db_host, $db_user, $db_passwd, $db_port);

    if ($this->server->utf8mb4_is_supported) {
      $mycnf .= "default-character-set=utf8mb4" . PHP_EOL;
    }

    return $mycnf;
  }

  /**
   * Generate the descriptors necessary to open a process with readable and
   * writeable pipes.
   */
  function generate_descriptorspec($stdin_file = NULL) {
    $stdin_spec = is_null($stdin_file) ? array("pipe", "r") : array("file", $stdin_file, "r");
    $descriptorspec = array(
      0 => $stdin_spec,         // stdin is a pipe that the child will read from
      1 => array("pipe", "w"),  // stdout is a pipe that the child will write to
      2 => array("pipe", "w"),  // stderr is a file to write to
      3 => array("pipe", "r"),  // fd3 is our special file descriptor where we pass credentials
    );
    return $descriptorspec;
  }

  /**
   * Return an array of regexes to filter lines of mysqldumps.
   */
  function get_regexes() {
    static $regexes = NULL;
    if (is_null($regexes)) {
      $regexes = array(
        // remove DEFINER entries
        '#/\*!50013 DEFINER=.*/#' => FALSE,
        // remove another kind of DEFINER line
        '#/\*!50017 DEFINER=`[^`]*`@`[^`]*`\s*\*/#' => '',
        // remove broken CREATE ALGORITHM entries
        '#/\*!50001 CREATE ALGORITHM=UNDEFINED \*/#' => "/*!50001 CREATE */",
      );

      // Allow regexes to be altered or appended to.
      drush_command_invoke_all_ref('provision_mysql_regex_alter', $regexes);
    }
    return $regexes;
  }

  function filter_line(&$line) {
    $regexes = $this->get_regexes();
    foreach ($regexes as $find => $replace) {
      if ($replace === FALSE) {
        if (preg_match($find, $line)) {
          // Remove this line entirely.
          $line = FALSE;
        }
      }
      else {
        $line = preg_replace($find, $replace, $line);
        if (is_null($line)) {
          // preg exploded in our face, oops.
          drush_set_error('PROVISION_BACKUP_FAILED', dt(
            "Error while running regular expression:\n Pattern: !find\n Replacement: !replace",
            array(
              '!find' => $find,
              '!replace' => $replace,
          )));
        }
      }
    }
  }

  /**
   * Generate a mysqldump for use in backups.
   */
  function generate_dump() {
    // Set the umask to 077 so that the dump itself is non-readable by the
    // webserver.
    umask(0077);
    // Allow writing to the site_path for the dump
    provision_file()->chmod(d()->site_path, 0755)
      ->succeed('Changed permissions of @path to @perm')
      ->fail('Could not change permissions of @path to @perm');

    // We chdir and use --uri because we have issues with D10 aliases
    chdir(d()->site_path);

    $dump_file = d()->site_path . '/database.sql';
    $cmd = 'drush --uri ' . d()->uri . ' sql-dump > ' . $dump_file;
    if (provision_get_drupal_core_major_version() < 8) {
      $cmd = 'drush sql-dump > ' . $dump_file;
    }
    $ret = provision_exec($cmd);

    if ($ret === FALSE) {
      drush_set_error('PROVISION_DB_BACKUP_FAILED', dt("Database backup failed: %output", ['%output' => implode('; ', $output)]));
    }

    $dump_size = filesize($dump_file);
    if ($dump_size < 1024 && !drush_get_option('force', FALSE)) {
      drush_set_error('PROVISION_BACKUP_FAILED', dt('Could not generate database backup from mysqldump. (filesize: %size)', array('%size' => $dump_size)));
    }

    // Reset the umask to normal permissions
    umask(0022);
    // Reset the permissions on the site directory
    provision_file()->chmod(d()->site_path, 0555)
      ->succeed('Changed permissions of @path to @perm')
      ->fail('Could not change permissions of @path to @perm');
  }

  function utf8mb4_is_supported() {
    // Avoid weird Aegir problems. It's 2024 and utf8mb4 is always supported.
    return TRUE;
  }
}
