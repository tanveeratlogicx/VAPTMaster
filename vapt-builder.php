<?php

/**
 * Plugin Name: VAPT Builder
 * Description: Ultimate VAPT and OWASP Security Plugin Builder.
 * Version: 1.1.0
 * Author: Tan Malik
 * Text Domain: vapt-builder
 */

if (! defined('ABSPATH')) {
  exit;
}

// Plugin Constants
define('VAPTM_VERSION', '1.1.0');
define('VAPTM_PATH', plugin_dir_path(__FILE__));
define('VAPTM_URL', plugin_dir_url(__FILE__));
define('VAPTM_SUPERADMIN_EMAIL', 'tanmalik786@gmail.com');
define('VAPTM_SUPERADMIN_USER', 'tanmalik786');

// Include core classes
require_once VAPTM_PATH . 'includes/class-vaptm-db.php';
require_once VAPTM_PATH . 'includes/class-vaptm-rest.php';
require_once VAPTM_PATH . 'includes/class-vaptm-auth.php';
require_once VAPTM_PATH . 'includes/class-vaptm-build.php';
// require_once VAPTM_PATH . 'includes/class-vaptm-admin.php';

/**
 * Activation Hook: Initialize Database Tables
 */
register_activation_hook(__FILE__, 'vaptm_activate_plugin');

function vaptm_activate_plugin()
{
  global $wpdb;
  $charset_collate = $wpdb->get_charset_collate();

  require_once ABSPATH . 'wp-admin/includes/upgrade.php';

  // Domains Table
  $table_domains = "CREATE TABLE {$wpdb->prefix}vaptm_domains (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        domain VARCHAR(255) NOT NULL,
        is_wildcard TINYINT(1) DEFAULT 0,
        license_id VARCHAR(100),
        license_type VARCHAR(50) DEFAULT 'standard',
        first_activated_at DATETIME DEFAULT NULL,
        manual_expiry_date DATETIME DEFAULT NULL,
        auto_renew TINYINT(1) DEFAULT 0,
        renewals_count INT DEFAULT 0,
        PRIMARY KEY  (id),
        UNIQUE KEY domain (domain)
    ) $charset_collate;";

  // Domain Features Table
  $table_features = "CREATE TABLE {$wpdb->prefix}vaptm_domain_features (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        domain_id BIGINT(20) UNSIGNED NOT NULL,
        feature_key VARCHAR(100) NOT NULL,
        enabled TINYINT(1) DEFAULT 0,
        PRIMARY KEY  (id),
        KEY domain_id (domain_id)
    ) $charset_collate;";

  // Feature Status Table
  $table_status = "CREATE TABLE {$wpdb->prefix}vaptm_feature_status (
        feature_key VARCHAR(100) NOT NULL,
        status ENUM('draft', 'develop', 'test', 'release') DEFAULT 'draft',
        implemented_at DATETIME DEFAULT NULL,
        PRIMARY KEY  (feature_key)
    ) $charset_collate;";

  // Feature Meta Table
  $table_meta = "CREATE TABLE {$wpdb->prefix}vaptm_feature_meta (
        feature_key VARCHAR(100) NOT NULL,
        category VARCHAR(100),
        test_method TEXT,
        verification_steps TEXT,
        include_test_method TINYINT(1) DEFAULT 0,
        include_verification TINYINT(1) DEFAULT 0,
        is_enforced TINYINT(1) DEFAULT 0,
        PRIMARY KEY  (feature_key)
    ) $charset_collate;";

  // Build History Table
  $table_builds = "CREATE TABLE {$wpdb->prefix}vaptm_domain_builds (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        domain VARCHAR(255) NOT NULL,
        version VARCHAR(50) NOT NULL,
        features TEXT NOT NULL,
        timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY domain (domain)
    ) $charset_collate;";

  dbDelta($table_domains);
  dbDelta($table_features);
  dbDelta($table_status);
  dbDelta($table_meta);
  dbDelta($table_builds);

  // Ensure data directory exists
  if (! file_exists(VAPTM_PATH . 'data')) {
    wp_mkdir_p(VAPTM_PATH . 'data');
  }

  // Ensure builds directory exists in uploads
  $upload_dir = wp_upload_dir();
  $target_dir = $upload_dir['basedir'] . '/vaptm-builds';
  if (! file_exists($target_dir)) {
    wp_mkdir_p($target_dir);
  }
}

/**
 * Manual DB Fix Trigger (Force Run)
 */
add_action('init', 'vaptm_manual_db_fix');
function vaptm_manual_db_fix()
{
  if (isset($_GET['vaptm_fix_db']) && current_user_can('manage_options')) {
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    global $wpdb;

    // 1. Run standard dbDelta
    vaptm_activate_plugin();

    // 2. Force add column just in case dbDelta missed it
    $table = $wpdb->prefix . 'vaptm_domains';
    $col = $wpdb->get_results("SHOW COLUMNS FROM $table LIKE 'manual_expiry_date'");
    if (empty($col)) {
      $wpdb->query("ALTER TABLE $table ADD COLUMN manual_expiry_date DATETIME DEFAULT NULL");
    }

    // 3. Force Modify Status Enum to new lifecycle
    $table_status = $wpdb->prefix . 'vaptm_feature_status';
    $wpdb->query("ALTER TABLE $table_status MODIFY COLUMN status ENUM('draft', 'develop', 'test', 'release') DEFAULT 'draft'");

    // 4. Force add is_enforced column
    $table_meta = $wpdb->prefix . 'vaptm_feature_meta';
    $col_enforced = $wpdb->get_results("SHOW COLUMNS FROM $table_meta LIKE 'is_enforced'");
    if (empty($col_enforced)) {
      $wpdb->query("ALTER TABLE $table_meta ADD COLUMN is_enforced TINYINT(1) DEFAULT 0");
    }

    $msg = "Database schema updated (is_enforced + Status Enum + Manual Expiry).";

    wp_die("<h1>VAPT Master Database Updated</h1><p>Schema refresh run. $msg</p><p>Please go back to the dashboard.</p>");
  }
}

/**
 * Detect Localhost Environment
 */
function is_vaptm_localhost()
{
  $whitelist = array('127.0.0.1', '::1', 'localhost');
  if (in_array($_SERVER['REMOTE_ADDR'], $whitelist) || strpos($_SERVER['HTTP_HOST'], '.local') !== false) {
    return true;
  }
  return false;
}

/**
 * Admin Menu Setup
 */
add_action('admin_menu', 'vaptm_add_admin_menu');
add_action('admin_notices', 'vaptm_localhost_admin_notice');

// Global to store hook suffixes for asset loading
$vaptm_hooks = array();

/**
 * Check Strict Permissions
 */
function vaptm_check_permissions()
{
  $current_user = wp_get_current_user();
  if ($current_user->user_login !== VAPTM_SUPERADMIN_USER) {
    wp_die(__('You do not have permission to access the VAPT Builder Dashboard.', 'vapt-builder'));
  }
}

function vaptm_add_admin_menu()
{
  global $vaptm_hooks;

  $current_user = wp_get_current_user();
  $is_superadmin = ($current_user->user_login === VAPTM_SUPERADMIN_USER);

  if ($is_superadmin) {
    // 1. Superadmin View: VAPT Builder Dashboard
    $vaptm_hooks['dashboard'] = add_menu_page(
      __('VAPT Builder', 'vapt-builder'),
      __('VAPT Builder', 'vapt-builder'),
      'manage_options',
      'vapt-builder', // Slug changed from vapt-master
      'vaptm_render_admin_page',
      'dashicons-shield',
      80
    );
  } else {
    // 2. Client View: Security Status Page
    $vaptm_hooks['status'] = add_menu_page(
      __('VAPT Client', 'vapt-builder'),
      __('VAPT Client', 'vapt-builder'),
      'manage_options',
      'vapt-client',
      'vaptm_render_client_status_page',
      'dashicons-shield',
      80
    );
  }
}

/**
 * Localhost Admin Notice
 */
function vaptm_localhost_admin_notice()
{
  // Notice shows ONLY on localhost
  if (!is_vaptm_localhost()) {
    return;
  }

  // Notice shows ONLY to NON-Superadmin administrators
  $current_user = wp_get_current_user();
  $is_superadmin = ($current_user->user_login === VAPTM_SUPERADMIN_USER);

  if ($is_superadmin || !current_user_can('manage_options')) {
    return;
  }

  $dashboard_url = admin_url('admin.php?page=vapt-builder');
?>
  <div class="notice notice-info is-dismissible">
    <p>
      <strong><?php _e('VAPT Builder:', 'vapt-builder'); ?></strong>
      <?php _e('Local environment detected. Test the Superadmin Dashboard here:', 'vapt-builder'); ?>
      <a href="<?php echo esc_url($dashboard_url); ?>"><?php echo esc_url($dashboard_url); ?></a>
    </p>
  </div>
<?php
}

/**
 * Render Client Status Page
 */
function vaptm_render_client_status_page()
{
?>
  <div class="wrap">
    <h1><?php _e('VAPT Builder - Security Status', 'vapt-builder'); ?></h1>
    <?php if (defined('VAPTM_DOMAIN_LOCKED')) : ?>
      <div class="notice notice-info">
        <p><?php printf(__('This build is locked to domain: %s', 'vapt-builder'), '<strong>' . esc_html(VAPTM_DOMAIN_LOCKED) . '</strong>'); ?></p>
        <p><?php printf(__('Build Version: %s', 'vapt-builder'), '<strong>' . esc_html(VAPTM_BUILD_VERSION) . '</strong>'); ?></p>
      </div>
    <?php else : ?>
      <div class="notice notice-warning">
        <p><?php _e('This is a development/unlocked build of VAPT Builder.', 'vapt-builder'); ?></p>
      </div>
    <?php endif; ?>

    <h3><?php _e('Active Security Modules', 'vapt-builder'); ?></h3>
    <ul>
      <?php
      $all_constants = get_defined_constants(true);
      $user_constants = isset($all_constants['user']) ? $all_constants['user'] : [];
      $found = false;
      foreach ($user_constants as $name => $value) {
        if (strpos($name, 'VAPTM_FEATURE_') === 0 && $value === true) {
          echo '<li><span class="dashicons dashicons-yes" style="color:green;"></span> ' . esc_html(str_replace('VAPTM_FEATURE_', '', $name)) . '</li>';
          $found = true;
        }
      }
      if (! $found) {
        echo '<li>' . __('No specific features enabled for this build yet.', 'vapt-builder') . '</li>';
      }
      ?>
    </ul>
  </div>
<?php
}

/**
 * Render Main Admin Page
 */
function vaptm_render_admin_page()
{
  // Strict Permission Check
  vaptm_check_permissions();

  if (! VAPTM_Auth::is_authenticated()) {
    // If not authenticated, send OTP if not already sent in this access
    if (! get_transient('vaptm_otp_' . VAPTM_SUPERADMIN_USER)) {
      VAPTM_Auth::send_otp();
    }
    VAPTM_Auth::render_otp_form();
    return;
  }
?>
  <div id="vaptm-admin-root" class="wrap">
    <h2><?php _e('VAPT Builder Dashboard Loading...', 'vapt-builder'); ?></h2>
  </div>
<?php
}

/**
 * Enqueue Admin Assets
 */
add_action('admin_enqueue_scripts', 'vaptm_enqueue_admin_assets');

function vaptm_enqueue_admin_assets($hook)
{
  global $vaptm_hooks;

  // Strict hook check to ensure scripts only load on the dashboard
  if (! isset($vaptm_hooks['dashboard']) || $hook !== $vaptm_hooks['dashboard']) {
    return;
  }

  if (! VAPTM_Auth::is_authenticated()) {
    return;
  }

  // We will use wp-element (React)
  wp_enqueue_script('vaptm-admin-js', VAPTM_URL . 'assets/js/admin.js', array('wp-element', 'wp-components', 'wp-i18n', 'wp-api-fetch'), VAPTM_VERSION, true);

  wp_localize_script('vaptm-admin-js', 'vaptmSettings', array(
    'pluginVersion' => VAPTM_VERSION,
    'root' => esc_url_raw(rest_url()),
    'nonce' => wp_create_nonce('wp_rest')
  ));
  wp_enqueue_style('vaptm-admin-css', VAPTM_URL . 'assets/css/admin.css', array('wp-components'), VAPTM_VERSION);

  // Localize data for React
  wp_localize_script('vaptm-admin-js', 'vaptmData', array(
    'apiUrl' => esc_url_raw(rest_url('vaptm/v1')),
    'nonce'  => wp_create_nonce('wp_rest'),
    'assetsUrl' => VAPTM_URL . 'assets/',
  ));
}
