<?php
/**
 * phpBB3 Class.
 *
 * This class is used to interact with phpBB3 forum
 *
 * @package classes
 * @copyright Copyright 2012-2015, Vinos de Frutas Tropicales (lat9): phpBB Notifier-Hook Integration v1.3.0
 * @copyright Copyright 2003-2009 Zen Cart Development Team
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 * @version $Id: class.phpbb.php 14689 2009-10-26 17:06:43Z drbyte $
 *
 * - - - - - - - - - - - - - - 
 * lat9 Changes:
 * - Modified to act as a Zen Cart observer class; used in conjunction with class.bb.php
 * - Add defines for PHPBB_DEBUG and PHPBB_DEBUG_IP, if not already defined
 * - Restructured internal variables
 * - Convert debug output to a class function (debug_output)
 * - Removed phpBB v2.x support
 * - Removed change_nick function -- it's being used as the "key" to the phpBB database
 */

if (!defined('IS_ADMIN_FLAG')) {
  die('Illegal Access');
}

define('FILENAME_PHPBB_INDEX', 'index.php');

if (!defined('PHPBB_DEBUG')) define('PHPBB_DEBUG', 'false');  // Either 'true' or 'false'
if (!defined('PHPBB_DEBUG_MODE')) define('PHPBB_DEBUG_MODE', 'log'); // Either 'screen', 'notify', 'log' or 'variable'
if (!defined('PHPBB_DEBUG_IP')) define('PHPBB_DEBUG_IP', '1');

  class phpbb_observer extends base {
      var $debug;
      var $debug_info;      // Accumulates debug information if PHPBB_DEBUG_MODE is set to 'variable'
      var $installed;       // Indicates whether or not this module is installed/usable
      var $db_connect;      // Indicates whether or not the phpBB database has been connected
      var $db_installed;    // Indicates whether or not all the required database tables are available
      var $files_installed;
      var $config_ok;       // Indicates whether or not the phpBB database configuration is properly set
      var $bb_version;      // Fixed-up base bb version (v1.0.0 error) v1.2.0a

      var $phpbb_path;      // The file-system path to the phpBB installation
      var $phpbb_url;
      var $dir_phpbb;
      
      
      var $db_phpbb;        // Contains the phpBB database object
      var $config;          // Array of values from the phpBB /config.php file, set by get_phpBB_info
      var $constants;       // Array of values from the phpBB /includes/constants.php file, set by get_phpBB_info
      var $groupId = 2;
      
      const STATUS_DUPLICATE_NICK  = 'duplicate_nick';
      const STATUS_DUPLICATE_EMAIL = 'duplicate_email';
      const STATUS_MISSING_INPUTS  = 'missing_inputs';
      const STATUS_UNKNOWN_ERROR   = 'unknown_error';
      const STATUS_NICK_NOT_FOUND  = 'nick_not_found';
      
    function __construct () {
      $this->installed = false;
      $this->debug_info = array();
      $this->debug = (defined('PHPBB_DEBUG') && PHPBB_DEBUG == 'true') ? true : false;
      $this->attach($this, array('ZEN_BB_INSTANTIATE', 'ZEN_BB_CREATE_ACCOUNT', 'ZEN_BB_CHECK_NICK_NOT_USED', 'ZEN_BB_CHECK_EMAIL_NOT_USED', 'ZEN_BB_CHANGE_PASSWORD', 'ZEN_BB_CHANGE_EMAIL'));
    }
    
    function update(&$class, $eventID, $paramsArray) {
      if (!$this->installed) {
        if ($eventID == 'ZEN_BB_INSTANTIATE') {
//-bof-a-v1.1.0
          $this->debug_output('PHPBB_INSTANTIATE: zc_bb version (' . $class->get_version() . '), zc_bb enabled (' . $class->is_enabled() . ')');  /*v1.2.1c*/
//-bof-a-v1.2.0
          $this->bb_version = $class->get_version();
          if ($this->bb_version === 'BB_VERSION_MAJOR.BB_VERSION_MINOR') {
            $this->bb_version = '1.0.0';
          }
//-eof-a-v1.2.0
          if ($this->bb_version != '1.0.0' && $class->is_enabled()) {  /*v1.2.0c*/
            $this->debug_output('PHPBB_INSTANTIATE_FAILED.  Another bulletin board (' . $class->get_bb_name() . ') is already attached.');
            
          } else {
//-eof-a-v1.1.0
            $this->phpbb_instantiate();
            if ($this->installed) {
              $class->set_bb_name('phpBB');
              $class->set_bb_url($this->phpbb_url);
//-bof-c-v1.1.0
              if ($class->get_version() == 'BB_VERSION_MAJOR.BB_VERSION_MINOR') {
                $class->return_status = bb::STATUS_OK;
              } else {
                $class->set_enabled();
              }
//-eof-c-v1.1.0
            }
          }
        }
      } else {
        switch ($eventID) {
          case 'ZEN_BB_CREATE_ACCOUNT': {
            $status = $this->phpbb_create_account($paramsArray['nick'], $paramsArray['pwd'], $paramsArray['email']);
            break;
          }
          case 'ZEN_BB_CHECK_NICK_NOT_USED': {
            $status = $this->phpbb_check_nick_not_used($paramsArray['nick']);
            break;
          }
          case 'ZEN_BB_CHECK_EMAIL_NOT_USED': {
            $status = $this->phpbb_check_email_not_used($paramsArray['email'], $paramsArray['nick']);
            break;
          }
          case 'ZEN_BB_CHANGE_PASSWORD': {
            $status = $this->phpbb_change_password($paramsArray['nick'], $paramsArray['pwd']);
            break;
          }
          case 'ZEN_BB_CHANGE_EMAIL': {
            $status = $this->phpbb_change_email($paramsArray['nick'], $paramsArray['email']);
            break;
          }
          default: {
            $status = self::STATUS_UNKNOWN_ERROR;
            break;
          }
        }
        
        $class->error_status  = $status;
        $class->return_status = ($status == bb::STATUS_OK) ? bb::STATUS_OK : bb::STATUS_ERROR;
        
      }
    }
    
    function phpbb_instantiate() {
      $this->db_connect = false;
      $this->db_installed = false;
      $this->files_installed = false;
      $this->config_ok = false;

      /////
      // If disabled in the Zen Cart admin, we're finished -- the module is not installed.
      //
      if ($this->is_enabled_in_zen_database()) {
        $this->get_phpBB_info();

        $this->db_phpbb = new queryFactory();
        $this->db_connect = $this->db_phpbb->connect($this->config['dbhost'], $this->config['dbuser'], $this->config['dbpasswd'], $this->config['dbname'], USE_PCONNECT, false);
        
        if (!($this->db_connect)) {
          $this->debug_output('Failure: Could not connect to phpBB database.');
          
        } else {
          $this->db_installed = true;
          foreach ($this->constants as $table_name) {
            if (!$this->table_exists($table_name)) {
              $this->debug_output("Failure: $table_name table not found in phpBB database.");
              $this->db_installed = false;
            }
          }
          
          if ($this->db_installed) {
            $this->config_ok = true;
            if ($this->get_phpbb_config_value('allow_namechange') !== '0') {
              $this->config_ok = false;
              $this->debug_output('phpBB connection disabled; set "User Registration Settings->General Options->Allow user name changes" to "No"');
            }
            
            if ($this->get_phpbb_config_value('allow_emailreuse') !== '0') {
              $this->config_ok = false;
              $this->debug_output('phpBB connection disabled; set "User Registration Settings->General Options->Allow e-mail address re-use" to "No"');
            }
          }
        }
        
       //calculate the path from root of server for absolute path info
        $script_filename = (isset($_SERVER['PATH_TRANSLATED'])) ? $_SERVER['PATH_TRANSLATED'] : '';  /*v1.2.1c*/
        if (empty($script_filename)) $script_filename = $_SERVER['SCRIPT_FILENAME'];
        $script_filename = str_replace(array('\\', '//'), '/', $script_filename);  //convert slashes

        if ($this->db_installed && $this->files_installed && $this->config_ok) {
          $this->phpbb_url = str_replace(array($_SERVER['DOCUMENT_ROOT'], substr($script_filename, 0, strpos($script_filename, $_SERVER['PHP_SELF']))), '', $this->phpbb_path) . FILENAME_PHPBB_INDEX;
          $this->installed = true;
          $this->debug_output('phpBB Integration activated, phpBB URL: ' . $this->phpbb_url);
        }
        
        if (!$this->installed) $this->debug_output('Failure: phpBB NOT activated');
        
      }
      
      if (PHPBB_DEBUG_MODE == 'screen') { 
        $this->debug_output('YOU CAN IGNORE THE FOLLOWING "Cannot send session cache limited - headers already sent..." errors, as they are a result of the above debug output. A debug*.log file has been generated.');
      }

    }
    
    function is_enabled_in_zen_database() {
      $is_enabled = false;
      /////
      // If disabled in the Zen Cart admin, we're finished -- the module is not installed.  Otherwise, make sure
      // that each of the required fields (email address, nickname and password) have non-zero minimum lengths
      // set in the Zen Cart database.
      //
      if (PHPBB_LINKS_ENABLED != 'true') {
        $this->debug_output('phpBB connection disabled; set "My Store->Enable phpBB Linkage" to true.');
        
      } elseif (((int)ENTRY_EMAIL_ADDRESS_MIN_LENGTH) < 1) {
        $this->debug_output('phpBB connection disabled; set "Minimum Values->E-Mail Address" to a value > 0.');
        
      } elseif ($this->bb_version < '1.2.0' && ((int)ENTRY_NICK_MIN_LENGTH) < 1) {  /*-v1.2.0-c*/
        $this->debug_output('phpBB connection disabled; set "Minimum Values->Nick Name" to a value > 0.');
        
      } elseif (((int)ENTRY_PASSWORD_MIN_LENGTH) < 1) {
        $this->debug_output('phpBB connection disabled; set "Minimum Values->Password" to a value > 0.');
        
      } else {
        $is_enabled = true;
        
      }
      
      return $is_enabled;
      
    }
    
    function get_phpBB_info() {
      $this->phpbb_path = '';
      $this->phpbb_url = '';
      $this->config = array();
      $this->constants = array();

      $this->dir_phpbb = str_replace(array('\\', '//'), '/', DIR_WS_PHPBB ); // convert slashes

      if (substr($this->dir_phpbb,-1) != '/') $this->dir_phpbb .= '/'; // ensure has a trailing slash
      $this->debug_output('phpBB directory = ' . $this->dir_phpbb);

      //check if file exists
      if ($_SERVER['HTTP_HOST'] == 'localhost' && @file_exists($this->dir_phpbb . 'config_local.php')) {
        $config_file = 'config_local.php';
      } else {
        $config_file = 'config.php';
      }

      if (!(@file_exists($this->dir_phpbb . $config_file))) {
        $this->debug_output('Failure: ' . $this->dir_phpbb . $config_file . ' does not exist.');
        
      } else {
        // if exists, also store it for future use
        $this->phpbb_path = $this->dir_phpbb;
        $this->debug_output('Found phpBB configuration file: ' . $this->dir_phpbb . $config_file);

       // find phpbb table prefix without including file:
        $lines = @file($this->phpbb_path . $config_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
          $this->debug_output('Failure: Error reading ' . $this->dir_phpbb . $config_file);
          
        } else {
          $define_list = array('dbhost', 'dbname', 'dbuser', 'dbpasswd', 'table_prefix');
          $num_items = sizeof($define_list);
          $found_items = 0;
          foreach($lines as $line) { // read the config.php file for specific variables
            // First, strip all spaces, tabs, and single- and double-quotes
            $line = str_replace(array(' ', "'", "\t", '"'), '', $line);

            if (strpos($line, '$') === 0) {
              $current_var = explode('=', substr($line, 1, strpos($line, ';', 2) - 1));
              $this->debug_output('Processing variable: ' . print_r($current_var, true));
              if (in_array($current_var[0], $define_list)) {
                $varname = strtolower($current_var[0]);
                $this->config[$varname] = $current_var[1];
                $found_items++;
              }
            }
          }
          
          // Continue to find the phpBB table names, *only* if the config.php file was successfully processed.
          if ($num_items == $found_items) {
            if (!(@file_exists($this->phpbb_path . 'includes/constants.php'))) {
              $this->debug_output('Failure: The file ' . $this->phpbb_path . 'includes/constants.php could not be found.');
              
             } else {
              $lines = @file($this->phpbb_path. 'includes/constants.php', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
              if ($lines === false) {
                $this->debug_output('Failure: Error reading ' . $this->phpbb_path. 'includes/constants.php');
                
              } else {
                $this->debug_output('Successfully read ' . $this->phpbb_path. 'includes/constants.php');
                $define_list = array('USERS_TABLE', 'USER_GROUP_TABLE', 'GROUPS_TABLE', 'CONFIG_TABLE');
                $num_items = sizeof($define_list);
                $found_items = 0;
                $table_prefix = $this->config['table_prefix'];
                foreach($lines as $line) {
                  $line = str_replace(array(' ', '\'', "\t", '"'), '', $line);
                  $line = str_replace('$table_prefix.', $table_prefix, $line);
                  if (strpos($line, 'define(') === 0 && strpos($line, ')', 8) !== false) {
                    $current_define = explode(',', substr($line, 7, strpos($line, ')', 8) - 7));
                    if (in_array($current_define[0], $define_list)) {
                      $this->debug_output('Processing define: ' . print_r($current_define, true));
                      $varname = strtolower($current_define[0]);
                      $this->constants[$varname] = $current_define[1];
                      $found_items++;
                    }
                  }
                }
                if ($num_items == $found_items) $this->files_installed = true;
                
              }  // constants.php file successfully read
            }  // constants.php file exists
          }  // Found all config.php required items
          
          $this->debug_output('Finished processing phpBB configuration:<br />' . print_r($this, true));  /*v1.2.1c*/
          
        }  // config.php file successfully read
      }  // config.php file exists
    }  // END function get_phpBB_info

    function table_exists($table_name) {
      $found_table = false;
    // Check to see if the requested phpBB table exists
      $sql = "SHOW TABLES like '$table_name'";
      $tables = $this->db_phpbb->Execute($sql);
      if ($tables->RecordCount() > 0) {
        $found_table = true;
      }
      $this->debug_output("table_exists($table_name), returning ($found_table): " . print_r($tables, true));
      return $found_table;
    }
    
    function get_phpbb_config_value($fieldname) {
      $sql = "SELECT config_value FROM " . $this->constants['config_table'] . " WHERE config_name = '$fieldname'";
      $config = $this->db_phpbb->Execute($sql);
      
      return ($config->EOF) ? false : $config->fields['config_value'];
    }

    function phpbb_create_account($nick, $password, $email_address) {
      $this->debug_output ("phpbb_create_account ($nick, '', $email_address)");
      if (!zen_not_null($password) || !zen_not_null($email_address) || !zen_not_null($nick)) {
        $status = self::STATUS_MISSING_INPUTS;
      } else {
        $status = $this->phpbb_check_email_not_used($email_address);
        if ($status == bb::STATUS_OK) {
          $status = $this->phpbb_check_nick_not_used($nick);
          if ($status == bb::STATUS_OK) {
            $user_dateformat = $this->get_phpbb_config_value ('default_dateformat');
            $user_lang = $this->get_phpbb_config_value ('default_lang');
            $user_timezone = $this->get_phpbb_config_value ('board_timezone');
            $sql = "INSERT INTO " . $this->constants['users_table'] . "
                    (group_id, username, username_clean, user_password, user_email, user_email_hash, user_regdate, user_permissions, user_sig, user_dateformat, user_lang, user_timezone)
                    values
                    (" . $this->groupId . ", '" . $nick . "', '" . strtolower($nick) . "', '" . md5($password) . "', '" . $email_address . "', '" . crc32(strtolower($email_address)) . strlen($email_address) . "', '" . time() ."', '', '', '$user_dateformat', '$user_lang', '$user_timezone' )";
            $this->db_phpbb->Execute($sql);
            $user_id = $this->db_phpbb->insert_ID ();
            $this->debug_output ("phpbb_create_account, created user_id ($user_id), insert sql ($sql)");
            $sql = "UPDATE " . $this->constants['config_table'] . " SET config_value = '{$user_id}' WHERE config_name = 'newest_user_id'";
            $this->db_phpbb->Execute($sql);
            $sql = "UPDATE " . $this->constants['config_table'] . " SET config_value = '{$nick}' WHERE config_name = 'newest_username'";
            $this->db_phpbb->Execute($sql);
            $sql = "UPDATE " . $this->constants['config_table'] . " SET config_value = config_value + 1 WHERE config_name = 'num_users'";
            $this->db_phpbb->Execute($sql);
            $sql = "INSERT INTO " . $this->constants['user_group_table'] . " (user_id, group_id, user_pending)
                    VALUES ($user_id, $this->groupId, 0)";
            $this->db_phpbb->Execute($sql);
          }
        }
      }
      $this->debug_output ("phpbb_create_account, returning: $status");
      return $status;
    }

    function phpbb_check_nick_not_used($nick) {
      if (!zen_not_null($nick) || $nick == '') {
        $status = self::STATUS_INVALID_INPUTS;
      } else {
        $sql = "select * from " . $this->constants['users_table'] . " where username = '" . $nick . "'";
        $phpbb_users = $this->db_phpbb->Execute($sql);
        $status = ($phpbb_users->RecordCount() > 0 ) ? self::STATUS_DUPLICATE_NICK : bb::STATUS_OK;
      }
      return $status;
    }

    function phpbb_check_email_not_used($email_address, $nick='') {
      if (!zen_not_null($email_address) || $email_address == '') {
        $status = self::STATUS_INVALID_INPUTS;
      } else {
        $check_nick = ($nick == '') ? '' : " AND username != '" . $nick . "'";
        $sql = "select * from " . $this->constants['users_table'] . " where user_email = '" . $email_address . "'" . $check_nick;
        $phpbb_users = $this->db_phpbb->Execute($sql);
        $status = ($phpbb_users->RecordCount() > 0 ) ? self::STATUS_DUPLICATE_EMAIL : bb::STATUS_OK;
      }
      return $status;
    }

    function phpbb_change_password($nick, $newpassword) {
      if (!zen_not_null($nick) || $nick == '') {
        $status = self::STATUS_MISSING_INPUTS;
        
      } elseif ($this->phpbb_check_nick_not_used($nick) != self::STATUS_DUPLICATE_NICK) {
        $status = self::STATUS_NICK_NOT_FOUND;
        
      } else {
        $status = bb::STATUS_OK;
        $sql = "update " . $this->constants['users_table'] . " set user_password='" . MD5($newpassword) . "'
                where username = '" . $nick . "'";
        $this->db_phpbb->Execute($sql);
      }
      return $status;
    }

    function phpbb_change_email($nick, $email_address) {
      if (!zen_not_null($nick) || $nick == '' || !zen_not_null($email_address) || $email_address == '') {
        $status = self::STATUS_MISSING_INPUTS;
        
      } elseif ($this->phpbb_check_email_not_used($email_address) != bb::STATUS_OK) {
        $status = self::STATUS_DUPLICATE_EMAIL;
        
      } elseif ($this->phpbb_check_nick_not_used($nick) != self::STATUS_DUPLICATE_NICK) {
        $status = self::STATUS_NICK_NOT_FOUND;
        
      } else {
        $status = bb::STATUS_OK;
        $sql = "update " . $this->constants['users_table'] . " 
                set user_email='" . $email_address . "', user_email_hash = '" . crc32(strtolower($email_address)) . strlen($email_address) . "'
                where username = '" . $nick . "'";
        $this->db_phpbb->Execute($sql);
      }
      return $status;
    }

    function debug_output($outputString) {
      if ($this->debug) {
        switch (PHPBB_DEBUG_MODE) {
          case 'notify': {
            $this->notify('PHPBB_OBSERVER_DEBUG', $outputString);
            break;
          }
          case 'variable': {
            $this->debug_info[] = $outputString;
            break;
          }
          case 'log': {
            error_log ('PHPBB_OBSERVER_DEBUG: ' . $outputString);
            break;
          }
          default: {
            if (defined('PHPBB_DEBUG_IP') && (PHPBB_DEBUG_IP == '' || PHPBB_DEBUG_IP == $_SERVER['REMOTE_ADDR'] || strstr(EXCLUDE_ADMIN_IP_FOR_MAINTENANCE, $_SERVER['REMOTE_ADDR']))) {
              echo $outputString . '<br />';
            }
            break;
          }
        }
      }
    }

  }