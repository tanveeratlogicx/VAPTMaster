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
define('VAPTM_VERSION', '1.2.6');
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
        status ENUM('Draft', 'Develop', 'Test', 'Release') DEFAULT 'Draft',
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
        wireframe_url TEXT DEFAULT NULL,
        generated_schema LONGTEXT DEFAULT NULL,
        implementation_data LONGTEXT DEFAULT NULL,
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

    // 3. Migrate Status ENUM to Title Case
    $status_table = $wpdb->prefix . 'vaptm_feature_status';
    $wpdb->query("ALTER TABLE $status_table MODIFY COLUMN status ENUM('Draft', 'Develop', 'Test', 'Release') DEFAULT 'Draft'");

    // 4. Update existing lowercase statuses to Title Case
    $wpdb->query("UPDATE $status_table SET status = 'Draft' WHERE status IN ('draft', 'available')");
    $wpdb->query("UPDATE $status_table SET status = 'Develop' WHERE status IN ('develop', 'in_progress')");
    $wpdb->query("UPDATE $status_table SET status = 'Test' WHERE status = 'test'");
    $wpdb->query("UPDATE $status_table SET status = 'Release' WHERE status IN ('release', 'implemented')");

    // 5. Ensure wireframe_url column exists
    $meta_table = $wpdb->prefix . 'vaptm_feature_meta';
    $meta_col = $wpdb->get_results("SHOW COLUMNS FROM $meta_table LIKE 'wireframe_url'");
    if (empty($meta_col)) {
      $wpdb->query("ALTER TABLE $meta_table ADD COLUMN wireframe_url TEXT DEFAULT NULL");
    }

    echo '<div class="notice notice-success"><p>Database migration complete. Statuses normalized to Draft, Develop, Test, Release.</p></div>';

    // 4. Force add is_enforced column
    $table_meta = $wpdb->prefix . 'vaptm_feature_meta';
    $col_enforced = $wpdb->get_results("SHOW COLUMNS FROM $table_meta LIKE 'is_enforced'");
    if (empty($col_enforced)) {
      $wpdb->query("ALTER TABLE $table_meta ADD COLUMN is_enforced TINYINT(1) DEFAULT 0");
    }

    // 5. Force add assigned_to column
    $col_assigned = $wpdb->get_results("SHOW COLUMNS FROM $status_table LIKE 'assigned_to'");
    if (empty($col_assigned)) {
      $wpdb->query("ALTER TABLE $status_table ADD COLUMN assigned_to BIGINT(20) UNSIGNED DEFAULT NULL");
    }

    // 3. Force add generated_schema column
    $meta_table = $wpdb->prefix . 'vaptm_feature_meta';
    $col_schema = $wpdb->get_results("SHOW COLUMNS FROM $meta_table LIKE 'generated_schema'");
    if (empty($col_schema)) {
      $wpdb->query("ALTER TABLE $meta_table ADD COLUMN generated_schema LONGTEXT DEFAULT NULL");
    }

    $col_data = $wpdb->get_results("SHOW COLUMNS FROM $meta_table LIKE 'implementation_data'");
    if (empty($col_data)) {
      $wpdb->query("ALTER TABLE $meta_table ADD COLUMN implementation_data LONGTEXT DEFAULT NULL");
    }

    $msg = "Database schema updated (History Table + assigned_to + is_enforced + Status Enum + Manual Expiry + Generated Schema + Implementation Data).";

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
    <h1 class="wp-heading-inline"><?php _e('VAPT Builder', 'vapt-builder'); ?></h1>
    <hr class="wp-header-end">

    <div id="vaptm-client-root">
      <div style="padding: 40px; text-align: center; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border-radius: 4px;">
        <span class="spinner is-active" style="float: none; margin: 0 auto;"></span>
        <p><?php _e('Loading Implementation Workbench...', 'vapt-builder'); ?></p>
      </div>
    </div>
  </div>
<?php
}

/**
 * Render Main Admin Page
 */
function vaptm_render_admin_page()
{
  vaptm_check_permissions();
  vaptm_master_dashboard_page();
}

function vaptm_master_dashboard_page()
{
  if (! VAPTM_Auth::is_authenticated()) {
    if (isset($_POST['vaptm_verify_otp'])) {
      VAPTM_Auth::verify_otp();
      // If verification successful, page will reload, so return for now
      if (VAPTM_Auth::is_authenticated()) {
        echo '<script>window.location.reload();</script>';
        return;
      }
    }

    if (! get_transient('vaptm_otp_email_' . VAPTM_SUPERADMIN_USER)) {
      VAPTM_Auth::send_otp();
    }
    VAPTM_Auth::render_otp_form();
    return;
  }
?>
  <div id="vaptm-admin-root" class="wrap">
    <h1><?php _e('VAPT Domain Admin', 'vapt-builder'); ?></h1>
    <div style="padding: 20px; text-align: center;">
      <span class="spinner is-active" style="float: none; margin: 0 auto;"></span>
      <p><?php _e('Loading VAPT Master...', 'vapt-builder'); ?></p>
    </div>
  </div>
<?php
}

/**
 * Enqueue Admin Assets
 */
add_action('admin_enqueue_scripts', 'vaptm_enqueue_admin_assets');

/**
 * Enqueue Assets for React App
 */
function vaptm_enqueue_admin_assets($hook)
{
  global $vaptm_hooks;
  $GLOBALS['vaptm_current_hook'] = $hook;

  $screen = get_current_screen();

  // Calculate is_superadmin for use in both blocks
  $current_user = wp_get_current_user();
  $user_login = $current_user->user_login;
  $user_email = $current_user->user_email;

  // Re-deriving strict superadmin status
  $is_superadmin = ($user_login === strtolower(VAPTM_SUPERADMIN_USER) || $user_email === strtolower(VAPTM_SUPERADMIN_EMAIL) || is_vaptm_localhost());

  if (!$screen) return;

  // Enqueue Shared Styles
  wp_enqueue_style('vaptm-admin-css', VAPTM_URL . 'assets/css/admin.css', array('wp-components'), VAPTM_VERSION);

  // 1. Superadmin Dashboard (admin.js)
  if ($screen->id === 'toplevel_page_vapt-domain-admin' || $screen->id === 'vapt-builder_page_vapt-domain-admin') {
    // Enqueue Auto-Interface Generator (Module)
    wp_enqueue_script(
      'vaptm-interface-generator',
      plugin_dir_url(__FILE__) . 'assets/js/modules/interface-generator.js',
      array(), // No deps, but strictly before admin.js
      VAPTM_VERSION,
      true
    );

    // Enqueue Generated Interface UI Component
    wp_enqueue_script(
      'vaptm-generated-interface-ui',
      plugin_dir_url(__FILE__) . 'assets/js/modules/generated-interface.js',
      array('wp-element', 'wp-components'),
      VAPTM_VERSION,
      true
    );

    // Enqueue Admin Dashboard Script
    wp_enqueue_script(
      'vaptm-admin-js',
      plugin_dir_url(__FILE__) . 'assets/js/admin.js',
      array('wp-element', 'wp-components', 'wp-api-fetch', 'vaptm-interface-generator', 'vaptm-generated-interface-ui'),
      '1.1.4',
      true
    );

    wp_localize_script('vaptm-admin-js', 'vaptmSettings', array(
      'root' => esc_url_raw(rest_url()),
      'nonce' => wp_create_nonce('wp_rest'),
      'pluginVersion' => VAPTM_VERSION
    ));
  }

  // 2. Client Dashboard (client.js) - "VAPT Builder" page
  if ($screen->id === 'toplevel_page_vapt-builder' || $screen->id === 'vapt-builder_page_vapt-builder') {

    // Enqueue Generated Interface UI Component (Shared)
    wp_enqueue_script(
      'vaptm-generated-interface-ui',
      plugin_dir_url(__FILE__) . 'assets/js/modules/generated-interface.js',
      array('wp-element', 'wp-components'),
      VAPTM_VERSION,
      true
    );

    wp_enqueue_script('vaptm-client-js', VAPTM_URL . 'assets/js/client.js', array('wp-element', 'wp-components', 'wp-i18n', 'wp-api-fetch', 'vaptm-generated-interface-ui'), VAPTM_VERSION, true);

    wp_localize_script('vaptm-client-js', 'vaptmSettings', array(
      'root' => esc_url_raw(rest_url()),
      'nonce' => wp_create_nonce('wp_rest'),
      'isSuper' => $is_superadmin,
      'pluginVersion' => VAPTM_VERSION // Version Info
    ));

    // Enqueue Styles
    wp_enqueue_style('wp-components');
  }
}
