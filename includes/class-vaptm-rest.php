<?php

/**
 * REST API Handler for VAPT Master
 */

if (! defined('ABSPATH')) {
  exit;
}

class VAPTM_REST
{

  public function __construct()
  {
    add_action('rest_api_init', array($this, 'register_routes'));
  }

  public function register_routes()
  {
    register_rest_route('vaptm/v1', '/features', array(
      'methods'  => 'GET',
      'callback' => array($this, 'get_features'),
      'permission_callback' => array($this, 'check_permission'),
    ));

    register_rest_route('vaptm/v1', '/data-files', array(
      'methods'  => 'GET',
      'callback' => array($this, 'get_data_files'),
      'permission_callback' => array($this, 'check_permission'),
    ));

    register_rest_route('vaptm/v1', '/features/update', array(
      'methods'  => 'POST',
      'callback' => array($this, 'update_feature'),
      'permission_callback' => array($this, 'check_permission'),
    ));

    register_rest_route('vaptm/v1', '/upload-json', array(
      'methods'  => 'POST',
      'callback' => array($this, 'upload_json'),
      'permission_callback' => array($this, 'check_permission'),
    ));

    register_rest_route('vaptm/v1', '/domains', array(
      'methods'  => 'GET',
      'callback' => array($this, 'get_domains'),
      'permission_callback' => array($this, 'check_permission'),
    ));

    register_rest_route('vaptm/v1', '/domains/update', array(
      'methods'  => 'POST',
      'callback' => array($this, 'update_domain'),
      'permission_callback' => array($this, 'check_permission'),
    ));

    register_rest_route('vaptm/v1', '/domains/features', array(
      'methods'  => 'POST',
      'callback' => array($this, 'update_domain_features'),
      'permission_callback' => array($this, 'check_permission'),
    ));

    register_rest_route('vaptm/v1', '/build/generate', array(
      'methods'  => 'POST',
      'callback' => array($this, 'generate_build'),
      'permission_callback' => array($this, 'check_permission'),
    ));
  }

  public function check_permission()
  {
    $current_user = wp_get_current_user();
    $is_superadmin = ($current_user->user_login === VAPTM_SUPERADMIN_USER || $current_user->user_email === VAPTM_SUPERADMIN_EMAIL);

    // Allow all admins on localhost to facilitate testing (access is still OTP-protected)
    if ($is_superadmin || (is_vaptm_localhost() && current_user_can('manage_options'))) {
      return true;
    }

    return false;
  }

  public function get_features($request)
  {
    $file = $request->get_param('file') ?: 'features-with-test-methods.json';
    $json_path = VAPTM_PATH . 'data/' . sanitize_file_name($file);

    if (! file_exists($json_path)) {
      return new WP_REST_Response(array('error' => 'JSON file not found: ' . $file), 404);
    }

    $content = file_get_contents($json_path);
    $features = json_decode($content, true);

    if (! is_array($features)) {
      return new WP_REST_Response(array('error' => 'Invalid JSON format'), 400);
    }

    $statuses = VAPTM_DB::get_feature_statuses_full();
    $status_map = [];
    foreach ($statuses as $row) {
      $status_map[$row['feature_key']] = array(
        'status' => $row['status'],
        'implemented_at' => $row['implemented_at']
      );
    }

    // Merge with status and meta
    foreach ($features as &$feature) {
      // Correct mapping: 'name' from JSON is the display label.
      // Generate a stable 'key' by slugifying the name if not present.
      $feature['label'] = isset($feature['name']) ? $feature['name'] : __('Unnamed Feature', 'vapt-master');
      $key = isset($feature['key']) ? $feature['key'] : sanitize_title($feature['label']);
      $feature['key'] = $key;

      $st = isset($status_map[$key]) ? $status_map[$key] : array('status' => 'available', 'implemented_at' => null);

      $feature['status'] = $st['status'];
      $feature['implemented_at'] = $st['implemented_at'];

      $meta = VAPTM_DB::get_feature_meta($key);
      if ($meta) {
        $feature['include_test_method'] = (bool) $meta['include_test_method'];
        $feature['include_verification'] = (bool) $meta['include_verification'];
      } else {
        $feature['include_test_method'] = false;
        $feature['include_verification'] = false;
      }
    }

    return new WP_REST_Response($features, 200);
  }

  public function get_data_files()
  {
    $data_dir = VAPTM_PATH . 'data';
    $files = array_diff(scandir($data_dir), array('..', '.'));
    $json_files = [];

    foreach ($files as $file) {
      if (pathinfo($file, PATHINFO_EXTENSION) === 'json') {
        $json_files[] = array(
          'label' => $file,
          'value' => $file
        );
      }
    }

    return new WP_REST_Response($json_files, 200);
  }

  public function update_feature($request)
  {
    $key = $request->get_param('key');
    $status = $request->get_param('status');
    $include_test = $request->get_param('include_test_method');
    $include_verification = $request->get_param('include_verification');

    if ($status) {
      VAPTM_DB::update_feature_status($key, $status);
    }

    VAPTM_DB::update_feature_meta($key, array(
      'include_test_method'  => $include_test ? 1 : 0,
      'include_verification' => $include_verification ? 1 : 0,
    ));

    return new WP_REST_Response(array('success' => true), 200);
  }

  public function upload_json($request)
  {
    $files = $request->get_file_params();
    if (empty($files['file'])) {
      return new WP_REST_Response(array('error' => 'No file uploaded'), 400);
    }

    $file = $files['file'];
    $filename = sanitize_file_name($file['name']);
    $content = file_get_contents($file['tmp_name']);
    $data = json_decode($content, true);

    if (is_null($data)) {
      return new WP_REST_Response(array('error' => 'Invalid JSON'), 400);
    }

    $json_path = VAPTM_PATH . 'data/' . $filename;
    file_put_contents($json_path, $content);

    return new WP_REST_Response(array('success' => true, 'filename' => $filename), 200);
  }

  public function get_domains()
  {
    global $wpdb;
    $domains = VAPTM_DB::get_domains();

    foreach ($domains as &$domain) {
      $domain_id = $domain['id'];
      $feat_rows = $wpdb->get_results($wpdb->prepare("SELECT feature_key FROM {$wpdb->prefix}vaptm_domain_features WHERE domain_id = %d AND enabled = 1", $domain_id), ARRAY_N);
      $domain['features'] = array_column($feat_rows, 0);
    }

    return new WP_REST_Response($domains, 200);
  }

  public function update_domain($request)
  {
    $domain = $request->get_param('domain');
    $is_wildcard = $request->get_param('is_wildcard');
    $license_id = $request->get_param('license_id');

    VAPTM_DB::update_domain($domain, $is_wildcard ? 1 : 0, $license_id);

    return new WP_REST_Response(array('success' => true), 200);
  }

  public function update_domain_features($request)
  {
    global $wpdb;
    $domain_id = $request->get_param('domain_id');
    $features = $request->get_param('features'); // Array of keys

    if (! is_array($features)) {
      return new WP_REST_Response(array('error' => 'Invalid features format'), 400);
    }

    $table = $wpdb->prefix . 'vaptm_domain_features';

    // Reset and re-add
    $wpdb->delete($table, array('domain_id' => $domain_id), array('%d'));

    foreach ($features as $key) {
      $wpdb->insert($table, array(
        'domain_id'   => $domain_id,
        'feature_key' => $key,
        'enabled'     => 1
      ), array('%d', '%s', '%d'));
    }

    return new WP_REST_Response(array('success' => true), 200);
  }

  public function generate_build($request)
  {
    $data = $request->get_json_params();
    $zip_path = VAPTM_Build::generate($data);

    if (file_exists($zip_path)) {
      // In a real scenario, we would store this and provide a hashed download link.
      // For now, facilitating the download by returning the base64 or a redirect is tricky in REST.
      // I'll store the zip in the uploads directory temporarily.
      $upload_dir = wp_upload_dir();
      $target_dir = $upload_dir['basedir'] . '/vaptm-builds';
      wp_mkdir_p($target_dir);

      $file_name = basename($zip_path);
      copy($zip_path, $target_dir . '/' . $file_name);

      $download_url = $upload_dir['baseurl'] . '/vaptm-builds/' . $file_name;

      return new WP_REST_Response(array('success' => true, 'download_url' => $download_url), 200);
    }

    return new WP_REST_Response(array('error' => 'Build failed'), 500);
  }
}

new VAPTM_REST();
