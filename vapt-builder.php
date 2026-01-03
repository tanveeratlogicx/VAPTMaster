<?php

/**
 * Plugin Name: VAPT Builder
 * Description: Ultimate VAPT and OWASP Security Plugin Builder.
 * Version: 1.1.2
 * Author: Tan Malik
 * Text Domain: vapt-builder
 */

if (! defined('ABSPATH')) {
  exit;
}

// Plugin Constants
define('VAPTM_VERSION', '1.1.2');
define('VAPTM_PATH', plugin_dir_path(__FILE__));
define('VAPTM_URL', plugin_dir_url(__FILE__));
define('VAPTM_SUPERADMIN_EMAIL', 'tanmalik786@gmail.com');
define('VAPTM_SUPERADMIN_USER', 'tanmalik786');

// Include core classes
require_once VAPTM_PATH . 'includes/class-vaptm-db.php';
require_once VAPTM_PATH . 'includes/class-vaptm-rest.php';
require_once VAPTM_PATH . 'includes/class-vaptm-auth.php';
require_once VAPTM_PATH . 'includes/class-vaptm-workflow.php';
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
        assigned_to BIGINT(20) UNSIGNED DEFAULT NULL,
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

  // Feature History/Audit Table
  $table_history = "CREATE TABLE {$wpdb->prefix}vaptm_feature_history (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        feature_key VARCHAR(100) NOT NULL,
        old_status VARCHAR(50),
        new_status VARCHAR(50),
        user_id BIGINT(20) UNSIGNED,
        note TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY feature_key (feature_key)
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
  dbDelta($table_history);
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

    // 5. Force add assigned_to column
    $col_assigned = $wpdb->get_results("SHOW COLUMNS FROM $table_status LIKE 'assigned_to'");
    if (empty($col_assigned)) {
      $wpdb->query("ALTER TABLE $table_status ADD COLUMN assigned_to BIGINT(20) UNSIGNED DEFAULT NULL");
    }

    $msg = "Database schema updated (History Table + assigned_to + is_enforced + Status Enum + Manual Expiry).";

    wp_die("<h1>VAPT Builder Database Updated</h1><p>Schema refresh run. $msg</p><p>Please go back to the dashboard.</p>");
  }
}

/**
 * Detect Localhost Environment
 */
function is_vaptm_localhost()
{
  $whitelist = array('127.0.0.1', '::1', 'localhost');
  $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
  $addr = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';

  if (in_array($addr, $whitelist) || in_array($host, $whitelist)) {
    return true;
  }

  // Common dev suffixes
  $dev_suffixes = array('.local', '.test', '.dev', '.wp', '.site');
  foreach ($dev_suffixes as $suffix) {
    if (strpos($host, $suffix) !== false) {
      return true;
    }
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
  $login = strtolower($current_user->user_login);
  $email = strtolower($current_user->user_email);
  $is_superadmin = (
    $login === strtolower(VAPTM_SUPERADMIN_USER) ||
    $email === strtolower(VAPTM_SUPERADMIN_EMAIL) ||
    is_vaptm_localhost()
  );
  if (! $is_superadmin) {
    wp_die(__('You do not have permission to access the VAPT Builder Dashboard.', 'vapt-builder'));
  }
}

function vaptm_add_admin_menu()
{
  $current_user = wp_get_current_user();
  $login = strtolower($current_user->user_login);
  $email = strtolower($current_user->user_email);
  $is_superadmin = (
    $login === strtolower(VAPTM_SUPERADMIN_USER) ||
    $email === strtolower(VAPTM_SUPERADMIN_EMAIL) ||
    is_vaptm_localhost()
  );

  // 1. Parent Menu
  add_menu_page(
    __('VAPT Builder', 'vapt-builder'),
    __('VAPT Builder', 'vapt-builder'),
    'manage_options',
    'vapt-builder',
    'vaptm_render_client_status_page',
    'dashicons-shield',
    80
  );

  // 2. Sub-menu 1: Status
  add_submenu_page(
    'vapt-builder',
    __('VAPT Builder', 'vapt-builder'),
    __('VAPT Builder', 'vapt-builder'),
    'manage_options',
    'vapt-builder',
    'vaptm_render_client_status_page'
  );

  // 3. Sub-menu 2: Domain Admin (Superadmin Only)
  if ($is_superadmin) {
    add_submenu_page(
      'vapt-builder',
      __('VAPT Domain Admin', 'vapt-builder'),
      __('VAPT Domain Admin', 'vapt-builder'),
      'manage_options',
      'vapt-domain-admin',
      'vaptm_render_admin_page'
    );
  }
}

/**
 * Handle Legacy Slug Redirects
 */
add_action('admin_init', 'vaptm_handle_legacy_redirects');
function vaptm_handle_legacy_redirects()
{
  if (!isset($_GET['page'])) return;

  $legacy_slugs = array('vapt-builder-main', 'vapt-builder-status', 'vapt-builder-domain-build', 'vapt-client');
  if (in_array($_GET['page'], $legacy_slugs)) {
    wp_safe_redirect(admin_url('admin.php?page=vapt-builder'));
    exit;
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
  $login = strtolower($current_user->user_login);
  $email = strtolower($current_user->user_email);
  $is_superadmin = ($login === strtolower(VAPTM_SUPERADMIN_USER) || $email === strtolower(VAPTM_SUPERADMIN_EMAIL) || is_vaptm_localhost());

  if ($is_superadmin || !current_user_can('manage_options')) {
    return;
  }

  $dashboard_url = admin_url('admin.php?page=vapt-domain-admin');
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
    <?php endif; ?>

    <?php if (isset($_GET['vaptm_debug']) && current_user_can('manage_options')) : ?>
      <div class="notice notice-warning">
        <p><strong>Debug Info:</strong></p>
        <p>Current User Login: <code><?php echo esc_html(wp_get_current_user()->user_login); ?></code></p>
        <p>Current User Email: <code><?php echo esc_html(wp_get_current_user()->user_email); ?></code></p>
        <p>Expected Superadmin: <code><?php echo esc_html(VAPTM_SUPERADMIN_USER); ?></code></p>
        <p>Is Superadmin: <code><?php echo (strtolower(wp_get_current_user()->user_login) === strtolower(VAPTM_SUPERADMIN_USER) || strtolower(wp_get_current_user()->user_email) === strtolower(VAPTM_SUPERADMIN_EMAIL) || is_vaptm_localhost()) ? 'YES' : 'NO'; ?></code></p>
      </div>
    <?php endif; ?>

    <?php if (!defined('VAPTM_DOMAIN_LOCKED')) : ?>
      <div class="notice notice-warning">
        <p><?php _e('This is a development/unlocked build of VAPT Builder.', 'vapt-builder'); ?> (Localhost/Superadmin Bypass Active)</p>
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
    if (! get_transient('vaptm_otp_email_' . VAPTM_SUPERADMIN_USER)) {
      VAPTM_Auth::send_otp();
    }
    VAPTM_Auth::render_otp_form();
    return;
  }
?>
  <div id="vaptm-admin-root" class="wrap">
    <h1><?php _e('VAPT Domain Admin', 'vapt-builder'); ?></h1>
    <div id="vaptm-loading-notice" class="notice notice-info">
      <p><?php _e('Initializing Master Dashboard...', 'vapt-builder'); ?></p>
      <ul style="margin: 5px 0 0 20px; font-size: 11px; opacity: 0.8;">
        <li>Asset URL: <a href="<?php echo esc_url(VAPTM_URL . 'assets/js/admin.js?ver=' . VAPTM_VERSION); ?>" target="_blank"><?php _e('Click to test script accessibility', 'vapt-builder'); ?></a></li>
        <li>WP Enqueue Status: <code><?php echo wp_script_is('vaptm-admin-js', 'enqueued') ? 'ENQUEUED' : 'NOT ENQUEUED'; ?></code></li>
        <li>WP Dependencies: <code>element:<?php echo wp_script_is('wp-element', 'registered') ? 'YES' : 'NO'; ?></code>, <code>comp:<?php echo wp_script_is('wp-components', 'registered') ? 'YES' : 'NO'; ?></code></li>
        <li>Current Hook: <code><?php echo esc_html(isset($GLOBALS['vaptm_current_hook']) ? $GLOBALS['vaptm_current_hook'] : 'unknown'); ?></code></li>
      </ul>
      <p id="vaptm-tag-check" style="font-size: 11px; margin-top: 5px;"></p>
    </div>
    <div id="vaptm-manual-mount" style="display:none; margin-top: 20px;">
      <p>Still not loading? <button class="button button-primary" onclick="if(window.vaptmInit) window.vaptmInit(); else alert('Dashboard script not detected in memory. Check console for 404 or Blocked errors.');">Force Dashboard Start</button></p>
    </div>
  </div>
  <script>
    (function() {
      setTimeout(function() {
        var root = document.getElementById('vaptm-admin-root');
        var manual = document.getElementById('vaptm-manual-mount');
        var tagCheck = document.getElementById('vaptm-tag-check');

        // 1. Check if script tag is even in the DOM
        var scriptTag = document.querySelector('script[src*="admin.js"]');
        if (tagCheck) {
          tagCheck.innerHTML = scriptTag ? '<span style="color:green;">✔ Script tag found in DOM</span>' : '<span style="color:red;">✘ Script tag MISSING from DOM</span>';
        }

        if (root && root.querySelector('.notice-info')) {
          if (manual) manual.style.display = 'block';
          if (!window.vaptmScriptLoaded) {
            console.error('VAPT Builder: Script load verification FAILED.');
            // Add a more visible error if the script handle is totally missing
            root.innerHTML += '<div class="notice notice-error"><p><strong>Diagnostic:</strong> Main JS bundle failed to execute. ' + (scriptTag ? 'The tag exists, but the browser could not execute it (Syntax error or Blocked).' : 'WordPress failed to print the script tag (Likely a hook or dependency issue).') + '</p></div>';
          }
        }
      }, 5000);
    })();
  </script>
<?php
}

/**
 * Enqueue Admin Assets
 */
add_action('admin_enqueue_scripts', 'vaptm_enqueue_admin_assets');

function vaptm_enqueue_admin_assets($hook)
{
  global $vaptm_hooks;
  $GLOBALS['vaptm_current_hook'] = $hook;

  // Relaxed hook check
  $is_our_page = (isset($_GET['page']) && $_GET['page'] === 'vapt-domain-admin') || strpos($hook, 'vapt-domain-admin') !== false;

  if (! $is_our_page) {
    return;
  }

  // No auth check here - redundant as the page itself is protected
  // and we want to ensure the script enqueues even if timing is tight.

  // We will use wp-element (React) - Restoring all dependencies
  wp_enqueue_script('vaptm-admin-js', VAPTM_URL . 'assets/js/admin.js', array('wp-element', 'wp-components', 'wp-i18n', 'wp-api-fetch'), VAPTM_VERSION, true);

  // Diagnostic: Log what we think the dependencies are
  if (isset($_GET['vaptm_debug'])) {
    error_log('VAPT Builder: Enqueuing admin.js with version ' . VAPTM_VERSION);
  }

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
