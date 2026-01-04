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

    register_rest_route('vaptm/v1', '/data-files/all', array(
      'methods' => 'GET',
      'callback' => array($this, 'get_all_data_files'),
      'permission_callback' => array($this, 'check_permission'),
    ));

    register_rest_route('vaptm/v1', '/data-files', array(
      'methods'  => 'GET',
      'callback' => array($this, 'get_data_files'),
      'permission_callback' => array($this, 'check_permission'),
    ));

    register_rest_route('vaptm/v1', '/update-hidden-files', array(
      'methods' => 'POST',
      'callback' => array($this, 'update_hidden_files'),
      'permission_callback' => array($this, 'check_permission'),
    ));


    register_rest_route('vaptm/v1', '/features/update', array(
      'methods'  => 'POST',
      'callback' => array($this, 'update_feature'),
      'permission_callback' => array($this, 'check_permission'),
    ));

    register_rest_route('vaptm/v1', '/features/transition', array(
      'methods'  => 'POST',
      'callback' => array($this, 'transition_feature'),
      'permission_callback' => array($this, 'check_permission'),
    ));

    register_rest_route('vaptm/v1', '/features/(?P<key>[a-zA-Z0-9_-]+)/history', array(
      'methods'  => 'GET',
      'callback' => array($this, 'get_feature_history'),
      'permission_callback' => array($this, 'check_permission'),
    ));

    register_rest_route('vaptm/v1', '/assignees', array(
      'methods'  => 'GET',
      'callback' => array($this, 'get_assignees'),
      'permission_callback' => array($this, 'check_permission'),
    ));

    register_rest_route('vaptm/v1', '/features/assign', array(
      'methods'  => 'POST',
      'callback' => array($this, 'update_assignment'),
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

    register_rest_route('vaptm/v1', '/upload-media', array(
      'methods'  => 'POST',
      'callback' => array($this, 'upload_media'),
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
        'implemented_at' => $row['implemented_at'],
        'assigned_to' => $row['assigned_to']
      );
    }

    // Security/Scope Check
    $scope = $request->get_param('scope');
    $is_superadmin = current_user_can('manage_options'); // Simplified check based on permission_callback

    // Batch fetch history counts to avoid N+1 queries
    global $wpdb;
    $history_table = $wpdb->prefix . 'vaptm_feature_history';
    $history_counts = $wpdb->get_results("SELECT feature_key, COUNT(*) as count FROM $history_table GROUP BY feature_key", OBJECT_K);

    // Merge with status and meta
    foreach ($features as &$feature) {
      // Correct mapping: 'name' from JSON is the display label.
      // Generate a stable 'key' by slugifying the name if not present.
      $feature['label'] = isset($feature['name']) ? $feature['name'] : __('Unnamed Feature', 'vapt-master');
      $key = isset($feature['key']) ? $feature['key'] : sanitize_title($feature['label']);
      $feature['key'] = $key;

      $st = isset($status_map[$key]) ? $status_map[$key] : array('status' => 'Draft', 'implemented_at' => null, 'assigned_to' => null);

      $feature['status'] = $st['status'];
      $feature['implemented_at'] = $st['implemented_at'];
      $feature['assigned_to'] = $st['assigned_to'];

      $meta = VAPTM_DB::get_feature_meta($key);
      if ($meta) {
        $feature['include_test_method'] = (bool) $meta['include_test_method'];
        $feature['include_verification'] = (bool) $meta['include_verification'];
        $feature['is_enforced'] = (bool) $meta['is_enforced'];
        $feature['wireframe_url'] = $meta['wireframe_url'];
        $feature['generated_schema'] = $meta['generated_schema'] ? json_decode($meta['generated_schema']) : null;
        $feature['implementation_data'] = $meta['implementation_data'] ? json_decode($meta['implementation_data']) : new stdClass();
      }

      $feature['has_history'] = isset($history_counts[$key]) && $history_counts[$key]->count > 0;
    }

    // Filter for Client Scope
    if ($scope === 'client') {
      $features = array_values(array_filter($features, function ($f) use ($is_superadmin) {
        // If Superadmin, return EVERYTHING (Drafts/Available are needed for 'Develop' tab)
        if ($is_superadmin) {
          return true;
        }
        // If Client/Standard User, return ONLY Release
        return $f['status'] === 'Release';
      }));
    }

    return new WP_REST_Response($features, 200);
  }

  public function get_data_files()
  {
    $data_dir = VAPTM_PATH . 'data';
    $files = array_diff(scandir($data_dir), array('..', '.'));
    $json_files = [];

    $hidden_files = get_option('vaptm_hidden_json_files', array());

    foreach ($files as $file) {
      if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'json') {
        // Only include if NOT hidden
        if (!in_array($file, $hidden_files)) {
          $json_files[] = array(
            'label' => $file,
            'value' => $file
          );
        }
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
    $is_enforced = $request->get_param('is_enforced');
    $wireframe_url = $request->get_param('wireframe_url');
    $generated_schema = $request->get_param('generated_schema');
    $implementation_data = $request->get_param('implementation_data');

    if ($status) {
      $note = $request->get_param('history_note') ?: ($request->get_param('transition_note') ?: '');
      $result = VAPTM_Workflow::transition_feature($key, $status, $note);
      if (is_wp_error($result)) {
        return new WP_REST_Response($result, 400);
      }
    }

    // DEBUG LOGGING
    if ($generated_schema !== null) {
      error_log('VAPTM UPDATE: Received generated_schema: ' . print_r($generated_schema, true));
      error_log('VAPTM UPDATE: Type: ' . gettype($generated_schema));
    }

    $meta_updates = array();
    if ($include_test !== null) $meta_updates['include_test_method'] = $include_test ? 1 : 0;
    if ($include_verification !== null) $meta_updates['include_verification'] = $include_verification ? 1 : 0;
    if ($is_enforced !== null) $meta_updates['is_enforced'] = $is_enforced ? 1 : 0;
    if ($wireframe_url !== null) $meta_updates['wireframe_url'] = $wireframe_url;
    if ($generated_schema !== null) {
      // Robustly handle both Arrays and Objects (stdClass) from JSON body
      $meta_updates['generated_schema'] = (is_array($generated_schema) || is_object($generated_schema))
        ? json_encode($generated_schema)
        : $generated_schema;
    }
    if ($implementation_data !== null) $meta_updates['implementation_data'] = is_array($implementation_data) ? json_encode($implementation_data) : $implementation_data;

    if (! empty($meta_updates)) {
      VAPTM_DB::update_feature_meta($key, $meta_updates);
    }

    return new WP_REST_Response(array('success' => true), 200);
  }

  /**
   * Dedicated Transition Endpoint
   */
  public function transition_feature($request)
  {
    $key = $request->get_param('key');
    $status = $request->get_param('status');
    $note = $request->get_param('note') ?: '';

    $result = VAPTM_Workflow::transition_feature($key, $status, $note);

    if (is_wp_error($result)) {
      return new WP_REST_Response($result, 400);
    }

    return new WP_REST_Response(array('success' => true), 200);
  }

  /**
   * Get Audit History for a Feature
   */
  public function get_feature_history($request)
  {
    $key = $request['key'];
    $history = VAPTM_Workflow::get_history($key);

    return new WP_REST_Response($history, 200);
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

  /**
   * Update Hidden JSON Files List
   */
  public function update_hidden_files($request)
  {
    $hidden_files = $request->get_param('hidden_files');
    if (!is_array($hidden_files)) {
      $hidden_files = array();
    }

    // Sanitize
    $hidden_files = array_map('sanitize_file_name', $hidden_files);

    update_option('vaptm_hidden_json_files', $hidden_files);

    return new WP_REST_Response(array('success' => true, 'hidden_files' => $hidden_files), 200);
  }

  /**
   * Get All JSON files (including hidden ones, for management UI)
   */
  public function get_all_data_files()
  {
    $data_dir = VAPTM_PATH . 'data';
    $files = array_diff(scandir($data_dir), array('..', '.'));
    $json_files = [];
    $hidden_files = get_option('vaptm_hidden_json_files', array());

    foreach ($files as $file) {
      if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'json') {
        $json_files[] = array(
          'filename' => $file,
          'isHidden' => in_array($file, $hidden_files)
        );
      }
    }

    return new WP_REST_Response($json_files, 200);
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

  /**
   * Get list of users who can be assigned to features
   */
  public function get_assignees()
  {
    $users = get_users(array('role' => 'administrator'));
    $assignees = array_map(function ($u) {
      return array('id' => $u->ID, 'name' => $u->display_name);
    }, $users);

    return new WP_REST_Response($assignees, 200);
  }

  /**
   * Update feature assignment
   */
  public function update_assignment($request)
  {
    global $wpdb;
    $key = $request->get_param('key');
    $user_id = $request->get_param('user_id');
    $table_status = $wpdb->prefix . 'vaptm_feature_status';
    $wpdb->update($table_status, array('assigned_to' => $user_id ? $user_id : null), array('feature_key' => $key));

    return new WP_REST_Response(array('success' => true), 200);
  }

  /**
   * Handle Media Upload (for Pasted Images / Wireframes)
   */
  public function upload_media($request)
  {
    if (empty($_FILES['file'])) {
      return new WP_Error('no_file', 'No file uploaded', array('status' => 400));
    }

    require_once(ABSPATH . 'wp-admin/includes/file.php');
    require_once(ABSPATH . 'wp-admin/includes/media.php');
    require_once(ABSPATH . 'wp-admin/includes/image.php');

    // Filter to change upload directory
    $upload_dir_filter = function ($uploads) {
      $subdir = '/vaptm-wireframes';
      $uploads['subdir'] = $subdir;
      $uploads['path']   = $uploads['basedir'] . $subdir;
      $uploads['url']    = $uploads['baseurl'] . $subdir;

      if (! file_exists($uploads['path'])) {
        wp_mkdir_p($uploads['path']);
      }
      return $uploads;
    };

    add_filter('upload_dir', $upload_dir_filter);

    $file = $_FILES['file'];
    $upload_overrides = array('test_form' => false);

    $movefile = wp_handle_upload($file, $upload_overrides);

    remove_filter('upload_dir', $upload_dir_filter);

    if ($movefile && ! isset($movefile['error'])) {
      // Create an attachment for the Media Library
      $filename = $movefile['file'];
      $attachment = array(
        'guid'           => $movefile['url'],
        'post_mime_type' => $movefile['type'],
        'post_title'     => preg_replace('/\.[^.]+$/', '', basename($filename)),
        'post_content'   => '',
        'post_status'    => 'inherit'
      );

      $attach_id = wp_insert_attachment($attachment, $filename);
      $attach_data = wp_generate_attachment_metadata($attach_id, $filename);
      wp_update_attachment_metadata($attach_id, $attach_data);

      return new WP_REST_Response(array(
        'success' => true,
        'url'     => $movefile['url'],
        'id'      => $attach_id
      ), 200);
    } else {
      return new WP_Error('upload_error', $movefile['error'], array('status' => 500));
    }
  }
}

new VAPTM_REST();
