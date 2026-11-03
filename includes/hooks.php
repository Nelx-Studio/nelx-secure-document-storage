<?php
if (!defined('ABSPATH')) exit;

class NELXSDST_SecureStorage {
  
    const BASE_DIR = 'nelx-secure-storage';
    const FILE_PERMISSION = 0640;
    const DIR_PERMISSION = 0750;
    const HTACCESS_PERMISSION = 0644;
  
    private static $storage_path = '';
    private static $processing_attachments = [];
    private static $cleaned_requests = [];

    private static $instance = null;
    private static $verified_jfb_request = false;
    private $is_recovering_pending = false;

    public static function get_instance(): self {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function __construct() {
        self::$instance = $this;
        $this->ensure_secure_dir();
        $this->init_custom_upload_handler();
       
        // Secure new attachments only
        add_action('add_attachment', [$this, 'secure_new_attachment'], 5, 1);
       
        // Intercept JFB form data BEFORE it gets saved to database
        // Handle both 1 and 2 argument versions
        add_filter('jet-form-builder/form-handler/request', [$this, 'clean_secured_file_data_before_save'], 10, 2);
        
        // Also intercept raw request data for comprehensive coverage
        // JFB's nonce/CSRF modules validate this filter at the default priority.
        // Run afterwards so reaching this callback is the trusted request boundary.
        add_filter('jet-form-builder/request-handler/request', [$this, 'clean_secured_file_request_data'], 99, 1);
   
        // Retain submission normalization and processing feedback timing.
        add_action('jet-form-builder/form-handler/after-send', [$this, 'handle_form_submissions'], 5, 2);
        add_filter('wp_generate_attachment_metadata', [$this, 'fix_metadata_path'], 10, 2);

        // Hide secured files from media library
        add_action('pre_get_posts', [$this, 'hide_secured_files_from_media_library']);

    }
    
    /**
     * Get filtered POST array
     */
    private function get_post_array(): array {
        if (!self::is_verified_jfb_request()) {
            return [];
        }

        // This data is read only after JFB's request validation has completed.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        return is_array($_POST) ? wp_unslash($_POST) : [];
    }

    /**
     * Whether the current request has passed JetFormBuilder's request pipeline.
     */
    public static function is_verified_jfb_request(): bool {
        return self::$verified_jfb_request;
    }

    /**
     * Clean secured file data from JFB form data before it gets saved
     */
    public function clean_secured_file_data_before_save($form_data, $handler = null) {
        // This internal JFB data filter only normalizes supplied hook data. It
        // does not authorize a request or perform file operations.
        
        // Avoid redundant processing - use request hash as identifier
        $request_hash = md5(serialize($form_data) . ($handler ? $handler->form_id : ''));
        
        if (isset(self::$cleaned_requests[$request_hash])) {
            
            return $form_data; 
        }
        

        $has_changes = false;
        
        // Process all form data fields
        foreach ($form_data as $field_name => $field_value) {
            $original_value = $field_value;
            $cleaned_value = $this->convert_url_objects_to_ids($field_value);
            
            if ($cleaned_value !== $original_value) {
                $form_data[$field_name] = $cleaned_value;
                $has_changes = true;

            }
        }
        
        // Mark this request as cleaned
        if ($has_changes) {
            self::$cleaned_requests[$request_hash] = true;
            
        }
        
        return $form_data;
    }

    /**
     * Clean secured file data from raw request data
     */
    public function clean_secured_file_request_data($request_data) {
        // JetFormBuilder validates its configured per-form nonce or CSRF token
        // before this late-priority callback. Invalid requests stop before here.
        self::$verified_jfb_request = true;
        
        // Avoid redundant processing
        $request_hash = md5(serialize($request_data));
        
        if (isset(self::$cleaned_requests[$request_hash])) {
            
            return $request_data;
        }

        $has_changes = false;
        
        // Process all request data fields
        foreach ($request_data as $field_name => $field_value) {
            $original_value = $field_value;
            $cleaned_value = $this->convert_url_objects_to_ids($field_value);
            
            if ($cleaned_value !== $original_value) {
                $request_data[$field_name] = $cleaned_value;
                $has_changes = true;

            }
        }
        
        // Mark this request as cleaned
        if ($has_changes) {
            self::$cleaned_requests[$request_hash] = true;
            
        }
        
        return $request_data;
    }

    /**
     * Clear cleaned requests cache to prevent memory issues
     */
    private function clear_cleaned_requests_cache() {
        // Keep only the last 10 cleaned requests to prevent memory issues
        if (count(self::$cleaned_requests) > 10) {
            self::$cleaned_requests = array_slice(self::$cleaned_requests, -10, 10, true);
            
        }
    }

    /**
     * Recursively convert URL objects to simple IDs in any data structure
     */
    private function convert_url_objects_to_ids($data) {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = $this->convert_url_objects_to_ids($value);
            }
            return $data;
        }
        
        if (is_string($data)) {
            // Handle JSON URL objects like {"url":"...","id":"5243"}
            if ($this->is_json_url_object($data)) {
                $attachment_id = $this->extract_id_from_json_url_object($data);
                if ($attachment_id) {
                    
                    return $attachment_id;
                }
            }
            
            // Handle corrupted nested JSON from previous resubmissions
            if ($this->is_corrupted_json_object($data)) {
                $attachment_id = $this->extract_id_from_corrupted_json($data);
                if ($attachment_id) {
                    
                    return $attachment_id;
                }
            }
        }
        
        return $data;
    }

    /**
     * Check if string is a JSON URL object
     */
    private function is_json_url_object($string) {
        if (!is_string($string)) return false;
        return (strpos($string, '{"url"') === 0 || strpos($string, '{\"url\"') === 0) && 
               strpos($string, '"id"') !== false;
    }

    /**
     * Check if string is corrupted JSON from previous resubmissions
     */
    private function is_corrupted_json_object($string) {
        if (!is_string($string)) return false;
        return strpos($string, '"url":false') !== false && strpos($string, '"id":') !== false;
    }

    /**
     * Extract attachment ID from JSON URL object
     */
    private function extract_id_from_json_url_object($json_string) {
        $decoded = json_decode($json_string, true);
        if (is_array($decoded) && isset($decoded['id']) && is_numeric($decoded['id'])) {
            return intval($decoded['id']);
        }

        return null;
    }

    /**
     * Extract attachment ID from corrupted JSON object
     */
    private function extract_id_from_corrupted_json($json_string) {
        // Pattern: {"url":false,"id":"{\"url\":\"https://...\"}"}
        if (preg_match('/"id":"(\d+)"/', $json_string, $matches)) {
            return intval($matches[1]);
        }
        
        // Pattern: {"url":false,"id":"{\"url\":\"https://...file_id=123...\"}"}
        if (preg_match('/file_id=(\d+)/', $json_string, $matches)) {
            return intval($matches[1]);
        }

        return null;
    }

    /**
     * Check secure-storage selection without treating settings as request authorization.
     *
     * This is intentionally safe to call from a separately authenticated live-upload
     * request. The caller is still responsible for validating its own nonce, field
     * permissions, file restrictions, and upload ownership.
     */
    public function is_form_secured($form_id): bool {
        $form_id = absint($form_id);

        if ('yes' === get_option('nelxsdst_auto_secure_all', 'no')) {
            return true;
        }

        if ($form_id <= 0) {
            return false;
        }

        $secure_forms = array_map('absint', (array) get_option('nelxsdst_secure_forms', []));

        return in_array($form_id, $secure_forms, true);
    }

    /**
     * Route a verified external/live upload into Secure Documents' protected pending
     * area. This method only prepares storage; it does not authorize an upload.
     */
    public function prepare_live_upload_dir(array $uploads, $form_id): array {
        return $this->is_form_secured($form_id) ? $this->pending_upload_dir($uploads, true) : $uploads;
    }

    /**
     * Finalize an attachment created by a trusted live-upload integration.
     *
     * The JFB request itself must already have crossed Secure Documents' verified
     * request boundary before this method is called.
     */
    public function finalize_live_attachment($attachment_id, $form_id, $field_name = '', $original_name = ''): bool {
        $attachment_id = absint($attachment_id);
        $form_id       = absint($form_id);
        $field_name    = sanitize_key($field_name);
        $original_name = sanitize_file_name($original_name);

        if (!self::is_verified_jfb_request() || !$attachment_id || 'attachment' !== get_post_type($attachment_id)) {
            return false;
        }

        if (!$this->is_form_secured($form_id)) {
            return false;
        }

        if ($original_name) {
            update_post_meta($attachment_id, '_nelxsdst_original_filename', $original_name);
        }
        if ($field_name) {
            update_post_meta($attachment_id, '_nelxsdst_original_field_name', $field_name);
        }
        if ($form_id) {
            update_post_meta($attachment_id, '_nelxsdst_form_id', $form_id);
        }

        $previous_form_id = $GLOBALS['nelxsdst_current_form_id'] ?? null;
        $GLOBALS['nelxsdst_current_form_id'] = $form_id;

        try {
            $this->secure_new_attachment($attachment_id);
        } finally {
            if (null === $previous_form_id) {
                unset($GLOBALS['nelxsdst_current_form_id']);
            } else {
                $GLOBALS['nelxsdst_current_form_id'] = $previous_form_id;
            }
        }

        // secure_new_attachment() necessarily renames the physical file. Reassert
        // the user-facing name so email/download consumers never inherit the hash.
        if ($original_name) {
            update_post_meta($attachment_id, '_nelxsdst_original_filename', $original_name);
        }
        if ($field_name) {
            update_post_meta($attachment_id, '_nelxsdst_original_field_name', $field_name);
        }

        return self::is_file_secured($attachment_id);
    }

    /**
     * Return the user-facing filename retained for a secured attachment.
     */
    public function get_original_filename($attachment_id): string {
        $attachment_id = absint($attachment_id);
        if (!$attachment_id) {
            return '';
        }

        $name = (string) get_post_meta($attachment_id, '_nelxsdst_original_filename', true);
        if ($name) {
            return sanitize_file_name($name);
        }

        $path = get_attached_file($attachment_id);
        return is_string($path) && $path ? sanitize_file_name(wp_basename($path)) : '';
    }

    /**
     * Check if a form should have its attachments secured
     */
    public function should_secure_form_attachments($form_id = 0) {
        // Pending recovery is a separate privileged admin operation protected
        // by its admin POST nonce and manage_options check.
        if ($this->is_recovering_pending) {
            return true;
        }

        // Settings select what to secure; they must never authenticate a request.
        if (!self::is_verified_jfb_request()) {
            return false;
        }

        $auto_secure_all = get_option('nelxsdst_auto_secure_all', 'no');

        if ($form_id > 0) {
            $is_secure = $this->is_form_secured($form_id);

            return $is_secure;
        }

        if ('yes' === $auto_secure_all) {
            return true;
        }
       
        // If we're processing a form but don't know which one, be conservative
        // and only secure if we can verify it's an enabled form
        if ($this->is_form_processing()) {
            $detected_form_id = $this->detect_current_form_id();
            if ($detected_form_id > 0) {
                $is_secure = $this->is_form_secured($detected_form_id);

                return $is_secure;
            }
        }

        return false;
    }
    
    /**
     * Detect current form ID from request data
     */
    private function detect_current_form_id() {
        if (!self::is_verified_jfb_request()) {
            return 0;
        }

        if (function_exists('jet_fb_handler')) {
            $handler = jet_fb_handler();
            if (is_object($handler) && method_exists($handler, 'get_form_id')) {
                return absint($handler->get_form_id());
            }
        }

        return 0;
    }
    
    public function init_custom_upload_handler() {
        new NELXSDST_JFB_Custom_Upload_Handler();
    }
  
    public function ensure_secure_dir() {
        $uploads = wp_upload_dir();
        $base = path_join($uploads['basedir'], self::BASE_DIR);
        $saved = get_option('nelxsdst_secure_storage_path', '');
        if (!$saved) {
            $saved = path_join($base, 'nelxsdst-' . substr(md5(wp_generate_password(32, true, true)), 0, 12));
            if (wp_mkdir_p($saved)) {
                update_option('nelxsdst_secure_storage_path', $saved);
            }
        }
        self::$storage_path = self::get_storage_root();
        if (self::$storage_path && wp_mkdir_p(self::$storage_path)) {
            self::add_protection_files(self::$storage_path);
        }
        // Existing pending files remain recoverable; nothing is relocated.
        if (is_dir($base)) {
            self::add_protection_files($base);
        }
    }

    public static function get_storage_root() {
        $path = defined('NELXSDST_STORAGE_PATH') ? NELXSDST_STORAGE_PATH : get_option('nelxsdst_secure_storage_path', '');
        $path = apply_filters('nelxsdst_storage_path', $path);
        // Configuration must be a local absolute filesystem directory.
        if (!is_string($path) || !$path || strpos($path, '://') !== false || !path_is_absolute($path)) {
            return '';
        }
        return untrailingslashit(wp_normalize_path($path));
    }

    public static function path_is_within($file, $root) {
        $file = realpath($file);
        $root = $root ? realpath($root) : false;
        return $file && $root && 0 === strpos(wp_normalize_path($file), trailingslashit(wp_normalize_path($root)));
    }

    public static function is_protected_path($file) {
        foreach (array_unique([self::get_storage_root(), get_option('nelxsdst_secure_storage_path', '')]) as $root) {
            if (self::path_is_within($file, $root)) {
                return true;
            }
        }
        return false;
    }

    public static function pending_roots() {
        $uploads = wp_upload_dir();
        return array_unique([
            path_join(self::get_storage_root(), 'pending'),
            path_join($uploads['basedir'], self::BASE_DIR . '/pending'),
        ]);
    }

    public function pending_upload_dir(array $uploads, $jfb = false): array {
        $root = self::get_storage_root();
        if (!$root) {
            $uploads['error'] = __('Secure storage is not configured.', 'nelx-secure-document-storage');
            return $uploads;
        }
        if ($jfb && $root === wp_normalize_path(get_option('nelxsdst_secure_storage_path', ''))) {
            $root = path_join($uploads['basedir'], self::BASE_DIR);
        }
        $path = path_join($root, 'pending/' . gmdate('Y/m'));
        if (!wp_mkdir_p($path) || !self::add_protection_files($path)) {
            $uploads['error'] = __('Unable to prepare protected storage.', 'nelx-secure-document-storage');
            return $uploads;
        }
        $uploads['path'] = $path;
        $normalized_base = trailingslashit(wp_normalize_path($uploads['basedir']));
        $uploads['url'] = 0 === strpos(wp_normalize_path($path), $normalized_base)
            ? trailingslashit($uploads['baseurl']) . substr(wp_normalize_path($path), strlen($normalized_base))
            : home_url('/nelx-secure-pending');
        $uploads['subdir'] = 0 === strpos(wp_normalize_path($path), $normalized_base) ? '/' . substr(wp_normalize_path($path), strlen($normalized_base)) : '/pending/' . gmdate('Y/m');
        return $uploads;
    }
  
    /**
     * Secure new attachment
     */
    public function secure_new_attachment($attachment_id) {
        if (!$this->is_form_processing() || !$this->should_secure_form_attachments()) {
            return;
        }
        $form_id = $this->detect_current_form_id() ?: absint($GLOBALS['nelxsdst_current_form_id'] ?? 0);
        NELXSDST_Storage_Service::ingest_attachment($attachment_id, [
            'source' => get_post_meta($attachment_id, '_nelxsdst_source', true) ?: 'jetformbuilder',
            'form_id' => $form_id,
            'owner' => $this->is_recovering_pending ? null : get_current_user_id(),
        ]);
    }
  
    public function handle_form_submissions($handler, $is_success) {

        // Clear the cleaned requests cache after form processing
        $this->clear_cleaned_requests_cache();
      
        $form_id = $this->get_form_id_from_handler($handler);
       
        static $processed_forms = [];
        
        // Get nonce from POST with proper sanitization
        $post = $this->get_post_array();
        $wpnonce = !empty($post['_wpnonce']) ? sanitize_text_field((string) $post['_wpnonce']) : '';
        $dedup_key = $form_id . '_' . $wpnonce;
       
        if (isset($processed_forms[$dedup_key])) {
            
            return;
        }
        $processed_forms[$dedup_key] = true;
      
        // Check if this form should be secured
        if (!$this->should_secure_form_attachments($form_id)) {
            
            return;
        }

        if (!$is_success) {
            
            return;
        }
        
        // Give WordPress a moment to finish processing the attachments
        // This ensures all files are fully secured before the email handler runs
        if ($this->has_pending_attachments($post)) {
            
            usleep(500000); // 0.5 second delay
        }
    }
    
    /**
     * Check if there are pending attachments that need processing
     */
    private function has_pending_attachments($post_data) {
        foreach ($post_data as $key => $value) {
            if (strpos($key, 'attachment') !== false || strpos($key, 'file') !== false) {
                if (is_array($value) && !empty($value)) {
                    return true;
                }
            }
        }
        return false;
    }
    
    public function hide_secured_files_from_media_library($query) {
        if (is_admin() && $query->is_main_query()) {
            $screen = function_exists('get_current_screen') ? get_current_screen() : null;
            if ($screen && $screen->id === 'upload') {
                $meta_query = $query->get('meta_query') ?: [];
                $meta_query[] = [
                    'key' => '_nelxsdst_secure_file',
                    'compare' => 'NOT EXISTS'
                ];
                $query->set('meta_query', $meta_query);
                
            }
        }
    }
  
    private function is_form_processing() {
        return self::is_verified_jfb_request() || $this->is_recovering_pending;
    }
  
    private function get_form_id_from_handler($handler) {
        if (!is_object($handler)) {
            
            return 0;
        }
      
        if (method_exists($handler, 'get_form_id')) {
            $form_id = $handler->get_form_id();
            
            return $form_id;
        }
        
        if (property_exists($handler, 'form_id')) {
            $form_id = $handler->form_id;
            
            return $form_id;
        }
        
        if (property_exists($handler, 'form') && is_object($handler->form) && property_exists($handler->form, 'ID')) {
            $form_id = $handler->form->ID;
            
            return $form_id;
        }

        return 0;
    }
    
    private function is_valid_attachment($id) {
        $post = get_post($id);
        $is_valid = $post && $post->post_type === 'attachment';

        return $is_valid;
    }
  
    public static function is_file_secured($attachment_id) {
        return 'attachment' === get_post_type($attachment_id) && (bool) get_post_meta($attachment_id, '_nelxsdst_secure_file', true);
    }
  
    public static function generate_secure_path($filename = '') {
        if (empty(self::$storage_path)) {
            // Simply call ensure_secure_dir through a new instance
            $instance = self::get_instance();
            $instance->ensure_secure_dir();
        }
      
        $hash1 = self::generate_directory_hash();
        $hash2 = self::generate_directory_hash();
        $year = gmdate('Y'); 
        $month = gmdate('m'); 
      
        $path = path_join(self::$storage_path, $hash1);
        if (!file_exists($path)) {
            wp_mkdir_p($path);
        }
      
        $path = path_join($path, $hash2);
        if (!file_exists($path)) {
            wp_mkdir_p($path);
        }
      
        $path = path_join($path, $year);
        if (!file_exists($path)) {
            wp_mkdir_p($path);
        }
      
        $path = path_join($path, $month);
        if (!file_exists($path)) {
            wp_mkdir_p($path);
            self::add_protection_files($path);
        }
      
        if (empty($filename)) {
            $filename = self::generate_secure_filename();
        }
      
        $result = [
            'full_path' => path_join($path, $filename),
            'relative_path' => path_join(
                self::BASE_DIR,
                path_join($hash1, path_join($hash2, path_join($year, path_join($month, $filename))))
            ),
            'filename' => $filename
        ];
        
        return $result;
    }
  
    private static function generate_directory_hash() {
        return substr(md5(wp_generate_password(32, true, true) . time()), 0, 16);
    }
  
    public static function generate_secure_filename($user_id = 0, $original_name = '') {
        $user_part = $user_id ? 'user' . intval($user_id) . '_' : '';
        $hash = sha1($original_name . time() . wp_generate_password(12, false));
        $ext = pathinfo($original_name, PATHINFO_EXTENSION);
        $filename = $user_part . $hash . (empty($ext) ? '' : '.' . $ext);
        
        return $filename;
    }
  
    /**
     * Ensure the secure storage directory cannot be browsed or served directly.
     *
     * The protection file is stored in uploads, not in the plugin directory.
     */
    public static function add_protection_files($path) {
        if (!is_dir($path) || !NELXSDST_Storage_Service::filesystem()->is_writable($path)) {
            return false;
        }
        $file = path_join($path, '.htaccess');
        $rules = "# Nelx Secure Document Storage - block direct access\nOptions -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n";
        if (is_file($file) && is_readable($file) && NELXSDST_Storage_Service::filesystem()->get_contents($file) === $rules) {
            return true;
        }
        if (file_exists($file) && !NELXSDST_Storage_Service::filesystem()->is_writable($file)) { return false; }
        return NELXSDST_Storage_Service::filesystem()->put_contents($file, $rules, self::HTACCESS_PERMISSION);
    }

    /**
     * Return attachment IDs represented by recoverable files in the pending directory.
     *
     * Intermediate image sizes are ignored because they do not have their own
     * attachment records. Matching is based on the original attachment filename.
     *
     * @return int[]
     */
    private function get_pending_attachment_ids(): array {
        $ids = get_posts([
            'post_type' => 'attachment', 'post_status' => 'any', 'posts_per_page' => -1,
            'fields' => 'ids',
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Recovery must identify attachments whose core attached-file metadata points into a pending directory.
            'meta_query' => [['key' => '_wp_attached_file', 'value' => '/pending/', 'compare' => 'LIKE']],
        ]);
        $pending = [];
        $roots = self::pending_roots();
        foreach ($ids as $id) {
            if (self::is_file_secured($id)) { continue; }
            $path = get_attached_file($id);
            foreach ($roots as $root) {
                if ($path && is_file($path) && self::path_is_within($path, $root)) {
                    $pending[] = $id;
                    break;
                }
            }
        }
        return $pending;
    }

    /**
     * Count recoverable attachments currently represented in secure storage pending.
     *
     * @return int
     */
    public function get_pending_file_count(): int {
        return count($this->get_pending_attachment_ids());
    }

    /**
     * Recover secured attachments left in the pending directory.
     *
     * Each attachment is processed once, regardless of how many intermediate
     * image sizes are present beside its original file.
     *
     * @return array{processed:int,skipped_no_attachment:int,failed:int}
     */
    public function recover_pending_files(): array {
        $attachment_ids = current_user_can('manage_options') ? $this->get_pending_attachment_ids() : [];
        $result = [
            'processed'             => 0,
            'skipped_no_attachment' => 0,
            'failed'                => 0,
        ];

        if (empty($attachment_ids)) {
            return $result;
        }

        $this->is_recovering_pending = true;

        try {
            foreach ($attachment_ids as $attachment_id) {
                try {
                    $this->secure_new_attachment($attachment_id);
                    if (self::is_file_secured($attachment_id)) {
                        $result['processed']++;
                    } else {
                        $result['failed']++;
                    }
                } catch (\Throwable $exception) {
                    $result['failed']++;
                    
                }
            }
        } finally {
            $this->is_recovering_pending = false;
        }

        return $result;
    }

    /**
     * Only fix metadata if it's completely missing AND file exists
     */
    public function fix_metadata_path($metadata, $attachment_id) {
        // Only fix if metadata is completely empty AND file exists
        if (empty($metadata)) {
            $file_path = get_attached_file($attachment_id);
            if ($file_path && file_exists($file_path) && self::is_file_secured($attachment_id) && self::is_protected_path($file_path)) {
                // Check if we're already processing this attachment to prevent loops
                if (!in_array($attachment_id, self::$processing_attachments)) {
                    
                    self::$processing_attachments[] = $attachment_id;
                    $new_metadata = wp_generate_attachment_metadata($attachment_id, $file_path);
                    array_pop(self::$processing_attachments);
                    return $new_metadata;
                }
            }
        }
        return $metadata;
    }
}

class NELXSDST_JFB_Custom_Upload_Handler {
  
    private $is_jfb_upload = false;
    private $current_form_id = 0;
  
    public function __construct() {
        $this->init_hooks();
    }
  
    private function init_hooks() {
        // JFB validates its configured nonce/CSRF protection at priority 10.
        // Enable routing only after that trusted request pipeline succeeds.
        add_filter('jet-form-builder/request-handler/request', [$this, 'setup_upload_filter'], 100, 1);
        add_action('jet-form-builder/form-handler/after-send', [$this, 'disable_custom_upload'], 999, 2);
        add_filter('jet-form-builder/mail/attachment-path', [$this, 'fix_email_attachment_path'], 10, 2);
    }
    
    public function setup_upload_filter($request_data) {
        if (!NELXSDST_SecureStorage::is_verified_jfb_request()) {
            return $request_data;
        }

        $this->enable_custom_upload();

        return $request_data;
    }
  
    public function maybe_setup_late_hooks() {
        // Retained for backward compatibility. Routing is now attached directly
        // to JFB's validated request filter in init_hooks().
    }
  
    public function enable_custom_upload() {
        if (!NELXSDST_SecureStorage::is_verified_jfb_request()) {
            return;
        }

        $form_id = $this->detect_current_form_id();
        $this->current_form_id = $form_id;
        $secure_storage = NELXSDST_SecureStorage::get_instance();

        if ($secure_storage->should_secure_form_attachments($form_id)) {
            $this->is_jfb_upload = true;
            add_filter('upload_dir', [$this, 'custom_upload_directory'], 999);
            
        }
    }
  
    public function disable_custom_upload($handler = null, $is_success = null) {

        $this->is_jfb_upload = false;
        $this->current_form_id = 0;
        remove_filter('upload_dir', [$this, 'custom_upload_directory'], 999);
    }
  
    public function custom_upload_directory($upload_data) {
        if (!$this->is_jfb_upload || !NELXSDST_SecureStorage::is_verified_jfb_request()) {
            return $upload_data;
        }
        $GLOBALS['nelxsdst_current_form_id'] = $this->current_form_id;
        return NELXSDST_SecureStorage::get_instance()->pending_upload_dir($upload_data, true);
    }
  
    private function detect_current_form_id() {
        if (!NELXSDST_SecureStorage::is_verified_jfb_request()) {
            return 0;
        }

        if (function_exists('jet_fb_handler')) {
            $handler = jet_fb_handler();
            if (is_object($handler) && method_exists($handler, 'get_form_id')) {
                $form_id = absint($handler->get_form_id());
                
                return $form_id;
            }
        }

        return 0;
    }
  
    private static function secure_email_path($id, $path) {
        return NELXSDST_SecureStorage::is_file_secured(absint($id)) && NELXSDST_SecureStorage::is_protected_path($path);
    }

    public function fix_email_attachment_path($attachment_path, $file_data) {

        if (file_exists($attachment_path)) {
            
            return $attachment_path;
        }
      
        if (isset($file_data['id'])) {
            $secure_file = get_attached_file($file_data['id']);
            if ($secure_file && file_exists($secure_file) && self::secure_email_path($file_data['id'], $secure_file)) {
                
                return $secure_file;
            }
        }

        return $attachment_path;
    }
}
