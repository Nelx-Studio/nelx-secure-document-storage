<?php
if (!defined('ABSPATH')) exit;

add_action('init', [ 'NELXSDST_File_Access', 'handle_file_access' ], 1);

class NELXSDST_File_Access {

    public function __construct() {
        // Constructor kept for future hooks if needed
    }

    /**
     * Handle secure file requests (view/download)
     */
    public static function handle_file_access() {
        if (!isset($_GET['nelxsdst_file_action']) || !isset($_GET['file_id'])) {
            return;
        }

        // Validate and sanitize GET parameters
        $action = isset($_GET['nelxsdst_file_action']) ? sanitize_text_field(wp_unslash($_GET['nelxsdst_file_action'])) : '';
        $file_id = isset($_GET['file_id']) ? absint(wp_unslash($_GET['file_id'])) : 0;

        if (!in_array($action, ['view', 'download'], true) || $file_id <= 0) {
            return;
        }

        if (!isset($_GET['nelxsdst_nonce'])) {
            self::access_denied(__('Security check failed.', 'nelx-secure-document-storage'));
        }
        
        $nonce = sanitize_text_field(wp_unslash($_GET['nelxsdst_nonce']));
        if (!wp_verify_nonce($nonce, 'nelxsdst_view_file_' . $file_id)) {
            self::access_denied(__('Security check failed.', 'nelx-secure-document-storage'));
        }

        // Must be logged in
        if (!is_user_logged_in()) {
            self::access_denied(__('You must be logged in to access this file.', 'nelx-secure-document-storage'));
        }

        // Must be a secured file
        if (!get_post_meta($file_id, '_nelxsdst_secure_file', true)) {
            self::not_found(__('File not found or not secured.', 'nelx-secure-document-storage'));
        }

        $file_path = get_attached_file($file_id);
        if (!$file_path || !is_file($file_path) || !is_readable($file_path)) {
            self::not_found(__('File not found on server.', 'nelx-secure-document-storage'));
        }

        if (!self::is_path_in_secure_storage($file_path)) {
            self::access_denied(__('The file path is outside secure storage.', 'nelx-secure-document-storage'));
        }

        if (!self::user_can_access_file($file_id)) {
            self::access_denied(__('You do not have permission to access this file.', 'nelx-secure-document-storage'));
        }

        $original_name = get_post_meta($file_id, '_nelxsdst_original_filename', true) ?: basename($file_path);
         $mime_type = get_post_mime_type($file_id) ?: 'application/octet-stream';
        $inline_types = ['application/pdf', 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'text/plain'];
        if (!in_array($mime_type, $inline_types, true)) {
            $action = 'download';
        }

        // Remove every removable output buffer so nested cache/compression layers do not accumulate the file.
        while (ob_get_level()) {
            $buffer = ob_get_status();
            if (empty($buffer['flags']) || !($buffer['flags'] & PHP_OUTPUT_HANDLER_REMOVABLE)) {
                wp_die(esc_html__('Unable to stream this file because an active output buffer cannot be closed.', 'nelx-secure-document-storage'), '', ['response' => 503]);
            }
            ob_end_clean();
        }

        header('Content-Type: ' . esc_attr($mime_type));
        header('Content-Length: ' . absint(filesize($file_path)));
        header('Content-Disposition: ' . ($action === 'download' ? 'attachment' : 'inline') . '; filename="' . esc_attr(sanitize_file_name($original_name)) . '"');
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: sandbox; default-src 'none'");
        header('Cache-Control: private, no-store, max-age=0');
        header('Pragma: private');
        header('Expires: 0');

        if (!self::serve_file_with_filesystem($file_path)) {
            self::not_found(__('Error serving file.', 'nelx-secure-document-storage'));
        }
        
        exit;
    }

    /**
     * Stream an authorized local file without buffering the full document.
     */
    private static function serve_file_with_filesystem($file_path) {
        // Stream local bytes in bounded chunks; WP_Filesystem::get_contents buffers the entire file.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $stream = fopen($file_path, 'rb');
        if (!$stream) {
            return false;
        }
        while (!feof($stream)) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
            $chunk = fread($stream, 1024 * 1024);
            if (false === $chunk) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close authorized streaming resource.
                fclose($stream);
                return false;
            }
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Authorized binary delivery.
            echo $chunk;
            if (connection_aborted()) {
                break;
            }
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Close authorized streaming resource.
        fclose($stream);
        return true;
    }

    /**
     * Confirm that a resolved attachment path stays inside secure storage.
     */
    private static function is_path_in_secure_storage($file_path) {
        return NELXSDST_SecureStorage::is_protected_path($file_path);
    }

    /**
     * Display access denied error
     */
    private static function access_denied($message = '') {
        $title = esc_html__('Access Denied - Error', 'nelx-secure-document-storage');
        $message = $message ? esc_html($message) : esc_html__('You do not have permission to access this resource.', 'nelx-secure-document-storage');
        
        // Clear any previous output
        if (ob_get_level()) {
            ob_end_clean();
        }
        
        wp_die(wp_kses_post($message), esc_html($title), ['response' => 403]);
    }

    /**
     * Display not found error
     */
    private static function not_found($message = '') {
        $title = esc_html__('Not Found - Error', 'nelx-secure-document-storage');
        $message = $message ? esc_html($message) : esc_html__('The requested resource was not found.', 'nelx-secure-document-storage');
        
        // Clear any previous output
        if (ob_get_level()) {
            ob_end_clean();
        }
        
        wp_die(wp_kses_post($message), esc_html($title), ['response' => 404]);
    }

    /**
     * Check if current user can access the file
     */
    public static function user_can_access_file($attachment_id, $requested_user_id = null) {
        $user_id = null === $requested_user_id ? get_current_user_id() : absint($requested_user_id);
        if (!$user_id || !NELXSDST_SecureStorage::is_file_secured($attachment_id)) {
            return false;
        }
        $user = get_userdata($user_id);
        if (!$user) { return false; }

        /**
         * Context-scoped files are always evaluated by the integration that
         * registered their policy. Fail closed when that integration is inactive
         * or returns anything other than an explicit true. Do not let global,
         * form or owner access bypass an application's record-level policy.
         * Other Secure Documents behaviour stays unchanged.
         */
        $scoped_context = sanitize_key( (string) get_post_meta( $attachment_id, '_nelxsdst_policy_context', true ) );
        if ( $scoped_context ) {
            $scoped_result = apply_filters( 'nelxsdst_scoped_file_access', null, absint( $attachment_id ), absint( $user_id ), $scoped_context );
            return true === $scoped_result;
        }

        // Admin always has access
        if (user_can($user, 'manage_options') || user_can($user, 'nelxsdst_access_all_secure_files')) {
            return true;
        }
    
        // Owner has access
        $owner = get_post_meta($attachment_id, '_nelxsdst_secure_owner', true);
        if ($owner && $owner == $user_id) {
            return true;
        }
    
        // Role-based access (global)
        $allowed_roles = get_option('nelxsdst_allowed_roles', []);
        
        if (!empty(array_intersect($user->roles, (array)$allowed_roles))) {
            return true;
        }
    
        // Form-level role access
        // Get the form ID directly from the attachment meta
        $form_id = get_post_meta($attachment_id, '_nelxsdst_form_id', true);
        
        // If not found, try the old detection method as fallback
        if (!$form_id) {
            $attachment_post = get_post($attachment_id);
            if ($attachment_post && $attachment_post->post_parent) {
                $parent_post = get_post($attachment_post->post_parent);
                if ($parent_post) {
                    $form_id = get_post_meta($parent_post->ID, '_jet_engine_booking_form_id', true);
                    if (!$form_id) {
                        $form_id = get_post_meta($parent_post->ID, '_jfb_form_id', true);
                    }
                    if (!$form_id) {
                        $form_id = get_post_meta($parent_post->ID, 'form_id', true);
                    }
                    if (!$form_id && $parent_post->post_type === 'jet-form-builder-submission') {
                        $form_id = get_post_meta($parent_post->ID, '_form_id', true);
                    }
                }
            }
            
            // If still no form ID, try attachment meta
            if (!$form_id) {
                $form_id = get_post_meta($attachment_id, '_jet_engine_booking_form_id', true);
            }
        }
        
        if ($form_id) {
            // Check if this form has form-level access enabled
            $form_level_access = get_option('nelxsdst_form_level_access', []);
            if (in_array($form_id, (array)$form_level_access)) {
                $form_level_roles = get_option('nelxsdst_form_level_roles', []);
                $form_roles = isset($form_level_roles[$form_id]) ? (array)$form_level_roles[$form_id] : [];
                
                if (!empty(array_intersect($user->roles, $form_roles))) {
                    return true;
                }
            }
        }
    
        return false;
    }

    /**
     * Generate secure file URL with nonce
     */
    public static function get_secure_file_url($attachment_id, $action = 'view') {
        // Build base URL with action and file ID
        $url = add_query_arg([
            'nelxsdst_file_action' => sanitize_text_field($action),
            'file_id'        => absint($attachment_id)
        ], home_url('/'));
        
        // Add nonce
        $nonce = wp_create_nonce('nelxsdst_view_file_' . $attachment_id);
        $url_with_nonce = add_query_arg('nelxsdst_nonce', $nonce, $url);
        
        // Return a raw URL; renderers escape at the HTML output boundary.
        return esc_url_raw($url_with_nonce);
    }
}
