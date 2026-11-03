<?php
/** Secure Files administration. All state-changing actions are nonce/capability checked. */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NELXSDST_Settings {
    const LIBRARY_CAP = 'nelxsdst_access_secure_files';
    const ALL_FILES_CAP = 'nelxsdst_access_all_secure_files';

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
        add_filter( 'parent_file', [ $this, 'hub_parent_file' ] );
        add_filter( 'submenu_file', [ $this, 'hub_submenu_file' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
        add_action( 'admin_print_styles', [ $this, 'dequeue_conflicting_datatables_assets' ], 999 );
        add_action( 'admin_print_scripts', [ $this, 'dequeue_conflicting_datatables_assets' ], 999 );
        add_action( 'admin_post_nelxsdst_save', [ $this, 'save' ] );
        add_action( 'wp_ajax_nelxsdst_save', [ $this, 'save' ] );
        add_action( 'wp_ajax_nelxsdst_files_table', [ $this, 'files_table' ] );
        add_action( 'admin_post_nelxsdst_upload', [ $this, 'upload' ] );
        add_action( 'wp_ajax_nelxsdst_upload', [ $this, 'upload' ] );
        add_action( 'wp_ajax_nelxsdst_shortcode_create', [ $this, 'shortcode_create' ] );
        add_action( 'wp_ajax_nelxsdst_shortcode_save', [ $this, 'shortcode_save' ] );
        add_action( 'wp_ajax_nelxsdst_shortcode_duplicate', [ $this, 'shortcode_duplicate' ] );
        add_action( 'wp_ajax_nelxsdst_shortcode_delete', [ $this, 'shortcode_delete' ] );
        add_action( 'admin_post_nelxsdst_delete', [ $this, 'delete' ] );
        add_action( 'admin_post_nelxsdst_recover', [ $this, 'recover' ] );
        if ( NELXSDST_PLUGIN_VERSION !== get_option( 'nelxsdst_capability_version', '' ) ) {
            $this->sync_capabilities( (array) get_option( 'nelxsdst_backend_roles', [] ) );
            delete_option( 'nelxsdst_enable_logging' );
            update_option( 'nelxsdst_capability_version', NELXSDST_PLUGIN_VERSION );
        }
    }

    public static function can_open_library() {
        return current_user_can( 'manage_options' ) || current_user_can( self::LIBRARY_CAP );
    }

    private function require_admin() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You cannot manage Secure Files settings.', 'nelx-secure-document-storage' ), '', [ 'response' => 403 ] );
        }
    }

    private function require_library() {
        if ( ! self::can_open_library() ) {
            wp_die( esc_html__( 'You cannot access the Secure Files library.', 'nelx-secure-document-storage' ), '', [ 'response' => 403 ] );
        }
    }

    public function sync_capabilities( $selected, $all = null ) {
        $all = null === $all ? (array) get_option( 'nelxsdst_backend_all_roles', [] ) : (array) $all;
        foreach ( wp_roles()->roles as $slug => $details ) {
            $role = get_role( $slug );
            if ( ! $role ) {
                continue;
            }
            if ( ! empty( $details['capabilities']['manage_options'] ) || in_array( $slug, $selected, true ) || in_array( $slug, $all, true ) ) {
                $role->add_cap( self::LIBRARY_CAP );
            } else {
                $role->remove_cap( self::LIBRARY_CAP );
            }
            if ( ! empty( $details['capabilities']['manage_options'] ) || in_array( $slug, $all, true ) ) {
                $role->add_cap( self::ALL_FILES_CAP );
            } else { $role->remove_cap( self::ALL_FILES_CAP ); }
        }
    }

    public function add_admin_menu() {
        $cap = current_user_can( 'manage_options' ) ? 'manage_options' : self::LIBRARY_CAP;

        if ( $this->using_hub_menu() ) {
            // The Hub owns the visible product menu entry. Register the native
            // Secure Files screens as hidden destinations so the product can
            // keep its existing internal Files / Access / Integrations / Donate UI.
            add_submenu_page( null, __( 'Nelx Secure Files', 'nelx-secure-document-storage' ), '', $cap, 'nelx-secure-files', [ $this, 'files_page' ] );
            foreach ( [ 'access', 'integrations', 'donate' ] as $area ) {
                $labels = $this->labels();
                add_submenu_page( null, $labels[ $area ], '', 'manage_options', 'nelx-secure-files-' . $area, [ $this, $area . '_page' ] );
            }
        } else {
            // Files-only backend users retain the dedicated top-level menu. The
            // Hub is deliberately admin-facing so these users do not gain access
            // to unrelated product or Hub settings screens.
            add_menu_page( __( 'Nelx Secure Files', 'nelx-secure-document-storage' ), __( 'Nelx Secure Files', 'nelx-secure-document-storage' ), $cap, 'nelx-secure-files', [ $this, 'files_page' ], 'dashicons-lock', 25 );
            add_submenu_page( 'nelx-secure-files', __( 'Files', 'nelx-secure-document-storage' ), __( 'Files', 'nelx-secure-document-storage' ), $cap, 'nelx-secure-files', [ $this, 'files_page' ] );
            foreach ( [ 'access', 'integrations', 'donate' ] as $area ) {
                $labels = $this->labels();
                add_submenu_page( 'nelx-secure-files', $labels[ $area ], $labels[ $area ], 'manage_options', 'nelx-secure-files-' . $area, [ $this, $area . '_page' ] );
            }
        }

        // The Hub entry and legacy plugin-settings link open Files by default.
        // Dedicated Access / Integrations / Donate links retain their own screens.
        add_submenu_page( null, __( 'Nelx Secure Files', 'nelx-secure-document-storage' ), '', 'manage_options', 'nelx-secure-document-storage', [ $this, 'files_page' ] );
    }

    /**
     * Whether the current administrator should reach Secure Files through the
     * shared Nelx Studio Hub rather than a separate top-level product menu.
     *
     * @return bool
     */
    private function using_hub_menu() {
        return current_user_can( 'manage_options' ) && function_exists( 'nelx_studio_register_product' );
    }

    /**
     * Keep the Nelx Studio parent highlighted while viewing native Secure Files pages.
     *
     * @param string $parent_file Current parent file.
     * @return string
     */
    public function hub_parent_file( $parent_file ) {
        if ( $this->using_hub_menu() && $this->is_secure_files_admin_screen() ) {
            return 'nelx-studio';
        }
        return $parent_file;
    }

    /**
     * Keep the Secure Documents Storage product entry highlighted in the Hub.
     *
     * @param string|null $submenu_file Current submenu file.
     * @return string|null
     */
    public function hub_submenu_file( $submenu_file ) {
        if ( $this->using_hub_menu() && $this->is_secure_files_admin_screen() ) {
            return 'admin.php?page=nelx-secure-document-storage';
        }
        return $submenu_file;
    }

    private function labels() {
        return [ 'files' => __( 'Files', 'nelx-secure-document-storage' ), 'access' => __( 'Access', 'nelx-secure-document-storage' ), 'integrations' => __( 'Integrations', 'nelx-secure-document-storage' ), 'donate' => __( 'Donate', 'nelx-secure-document-storage' ) ];
    }

    private function is_secure_files_admin_screen() {
        if ( ! is_admin() || ! function_exists( 'get_current_screen' ) ) {
            return false;
        }

        $screen = get_current_screen();
        if ( ! $screen || empty( $screen->id ) ) {
            return false;
        }

        return false !== strpos( $screen->id, 'nelx-secure-files' ) || false !== strpos( $screen->id, 'nelx-secure-document-storage' );
    }

    /**
     * Prevent another admin component's DataTables build from replacing the
     * bundled version on Nelx Secure Files screens.
     *
     * This is intentionally scoped to our admin pages and checks registered
     * asset paths rather than hard-coding or contacting any remote service.
     */
    public function dequeue_conflicting_datatables_assets() {
        if ( ! $this->is_secure_files_admin_screen() ) {
            return;
        }

        global $wp_styles, $wp_scripts;

        if ( $wp_styles && ! empty( $wp_styles->queue ) ) {
            foreach ( (array) $wp_styles->queue as $handle ) {
                if ( 'nelxsdst-datatables' === $handle || empty( $wp_styles->registered[ $handle ] ) ) {
                    continue;
                }
                $src = strtolower( (string) $wp_styles->registered[ $handle ]->src );
                if ( false !== strpos( $src, 'datatables' ) ) {
                    wp_dequeue_style( $handle );
                }
            }
        }

        if ( $wp_scripts && ! empty( $wp_scripts->queue ) ) {
            foreach ( (array) $wp_scripts->queue as $handle ) {
                if ( 'nelxsdst-datatables' === $handle || empty( $wp_scripts->registered[ $handle ] ) ) {
                    continue;
                }
                $src = strtolower( (string) $wp_scripts->registered[ $handle ]->src );
                if ( false !== strpos( $src, 'datatables' ) ) {
                    wp_dequeue_script( $handle );
                }
            }
        }
    }

    public function enqueue_admin_assets( $hook ) {
        if ( false === strpos( $hook, 'nelx-secure-files' ) && false === strpos( $hook, 'nelx-secure-document-storage' ) ) {
            return;
        }
        $style_dependencies = [];
        $dependencies = [ 'jquery', 'nelxsdst-select2' ];
        $is_files_page = false !== strpos( $hook, 'nelx-secure-files' )
            && false === strpos( $hook, 'nelx-secure-files-access' )
            && false === strpos( $hook, 'nelx-secure-files-integrations' )
            && false === strpos( $hook, 'nelx-secure-files-donate' );
        if ( $is_files_page ) {
            // Load vendor styles first so our admin stylesheet can intentionally override them.
            wp_enqueue_style( 'nelxsdst-datatables', NELXSDST_PLUGIN_URL . 'assets/vendor/datatables/dataTables.css', [], '2.3.4' );
            wp_enqueue_script( 'nelxsdst-datatables', NELXSDST_PLUGIN_URL . 'assets/vendor/datatables/dataTables.min.js', [ 'jquery' ], '2.3.4', true );
            $style_dependencies[] = 'nelxsdst-datatables';
            $dependencies[] = 'nelxsdst-datatables';
        }
        wp_enqueue_style( 'nelxsdst-admin', NELXSDST_PLUGIN_URL . 'assets/css/admin.css', $style_dependencies, NELXSDST_PLUGIN_VERSION );
        wp_enqueue_style( 'nelxsdst-library', NELXSDST_PLUGIN_URL . 'assets/css/library.css', [ 'nelxsdst-admin' ], NELXSDST_PLUGIN_VERSION );
        wp_enqueue_style( 'nelxsdst-select2', NELXSDST_PLUGIN_URL . 'assets/css/select2.min.css', [], '4.1.0' );
        wp_enqueue_script( 'nelxsdst-select2', NELXSDST_PLUGIN_URL . 'assets/js/select2.min.js', [ 'jquery' ], '4.1.0', true );
        wp_enqueue_script( 'nelxsdst-admin', NELXSDST_PLUGIN_URL . 'assets/js/admin.js', $dependencies, NELXSDST_PLUGIN_VERSION, true );
        wp_localize_script( 'nelxsdst-admin', 'nelxsdstAdmin', [
            'deleteConfirm' => __( 'Permanently delete this secure file and its attachment record?', 'nelx-secure-document-storage' ),
            'working' => __( 'Processing…', 'nelx-secure-document-storage' ),
            'copied' => __( 'Copied', 'nelx-secure-document-storage' ),
            'close' => __( 'Close', 'nelx-secure-document-storage' ),
            'copyFailed' => __( 'Select and copy the code manually.', 'nelx-secure-document-storage' ),
            'roles' => __( 'Select roles…', 'nelx-secure-document-storage' ),
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'tableNonce' => wp_create_nonce( 'nelxsdst_files_table' ),
            'saveFailed' => __( 'Unable to save settings. Please try again.', 'nelx-secure-document-storage' ),
            'uploadNonce' => wp_create_nonce( 'nelxsdst_upload' ),
            'shortcodeNonce' => wp_create_nonce( 'nelxsdst_shortcodes' ),
            'maxUploadSize' => wp_max_upload_size(),
            'upload' => [ 'drag' => __( 'Drag and drop files here', 'nelx-secure-document-storage' ), 'or' => __( 'or', 'nelx-secure-document-storage' ), 'browse' => __( 'Browse Files', 'nelx-secure-document-storage' ), 'preparing' => __( 'Preparing', 'nelx-secure-document-storage' ), 'uploading' => __( 'Uploading', 'nelx-secure-document-storage' ), 'uploaded' => __( 'Secured', 'nelx-secure-document-storage' ), 'failed' => __( 'Upload failed', 'nelx-secure-document-storage' ), 'tooLarge' => __( 'This file exceeds the WordPress upload limit.', 'nelx-secure-document-storage' ), 'cancel' => __( 'Cancel upload', 'nelx-secure-document-storage' ), 'dismiss' => __( 'Dismiss', 'nelx-secure-document-storage' ) ],
            'shortcodes' => [ 'deleteConfirm' => __( 'Delete this shortcode configuration? Existing copied shortcodes using this preset will fall back to their visible attributes and default styling.', 'nelx-secure-document-storage' ), 'created' => __( 'Shortcode created.', 'nelx-secure-document-storage' ), 'saved' => __( 'Shortcode saved.', 'nelx-secure-document-storage' ), 'deleted' => __( 'Shortcode deleted.', 'nelx-secure-document-storage' ), 'duplicated' => __( 'Shortcode duplicated.', 'nelx-secure-document-storage' ), 'empty' => __( 'No saved shortcode configurations yet.', 'nelx-secure-document-storage' ) ],
            'table' => [ 'search' => __( 'Search files:', 'nelx-secure-document-storage' ), 'emptyTable' => __( 'No accessible secure files found.', 'nelx-secure-document-storage' ), 'processing' => __( 'Loading files…', 'nelx-secure-document-storage' ), 'lengthMenu' => __( 'Show _MENU_ files', 'nelx-secure-document-storage' ), 'all' => __( 'All', 'nelx-secure-document-storage' ), 'info' => __( 'Showing _START_ to _END_ of _TOTAL_ files', 'nelx-secure-document-storage' ), 'infoEmpty' => __( 'No files', 'nelx-secure-document-storage' ), 'infoFiltered' => __( '(filtered from _MAX_ files)', 'nelx-secure-document-storage' ), 'zeroRecords' => __( 'No matching files.', 'nelx-secure-document-storage' ), 'paginate' => [ 'first' => __( 'First', 'nelx-secure-document-storage' ), 'last' => __( 'Last', 'nelx-secure-document-storage' ), 'next' => __( 'Next', 'nelx-secure-document-storage' ), 'previous' => __( 'Previous', 'nelx-secure-document-storage' ) ] ],
        ] );
    }

    private function header( $area, $description ) {
        $labels = $this->labels();
        ?>
        <div class="wrap nelxsdst-admin">
            <header class="nelxsdst-header"><div><h1><?php esc_html_e( 'Nelx Secure Files', 'nelx-secure-document-storage' ); ?> <span>v<?php echo esc_html( NELXSDST_PLUGIN_VERSION ); ?></span></h1><p><?php echo esc_html( $description ); ?></p></div>
            <?php if ( 'files' === $area && NELXSDST_Storage_Service::can_upload() ) : ?><button type="button" class="button button-primary" data-ns-upload><?php esc_html_e( 'Add Secure File', 'nelx-secure-document-storage' ); ?></button><?php endif; ?></header>
            <div id="nelxsdst-notification-container" aria-live="polite"></div>
            <div class="nelxsdst-shell"><nav class="nelxsdst-nav" aria-label="<?php esc_attr_e( 'Nelx Secure Files navigation', 'nelx-secure-document-storage' ); ?>">
                <?php foreach ( $labels as $key => $label ) : ?>
                    <?php if ( 'files' !== $key && ! current_user_can( 'manage_options' ) ) { continue; } ?>
                    <a class="<?php echo $area === $key ? 'is-active' : ''; ?>" <?php if ( $area === $key ) { echo 'aria-current="page"'; } ?> href="<?php echo esc_url( admin_url( 'admin.php?page=nelx-secure-files' . ( 'files' === $key ? '' : '-' . $key ) ) ); ?>"><span class="dashicons dashicons-<?php echo esc_attr( [ 'files' => 'media-document', 'access' => 'shield', 'integrations' => 'admin-plugins', 'donate' => 'heart' ][ $key ] ); ?>" aria-hidden="true"></span><?php echo esc_html( $label ); ?></a>
                <?php endforeach; ?>
            </nav><main class="nelxsdst-content">
            <?php
            $notice = get_transient( 'nelxsdst_notice_' . get_current_user_id() );
            if ( $notice ) {
                delete_transient( 'nelxsdst_notice_' . get_current_user_id() );
                echo '<div role="status" class="notice notice-' . ( $notice['error'] ? 'error' : 'success' ) . '"><p>' . esc_html( $notice['message'] ) . '</p></div>';
            }
    }

    private function footer() {
        echo '</main></div></div>';
    }

    private function form_start( $area, $action = 'nelxsdst_save' ) {
        $form_class = 'nelxsdst-action-form';
        if ( 'nelxsdst_save' === $action && in_array( $area, [ 'access', 'integrations' ], true ) ) {
            $form_class .= ' nelxsdst-sticky-save-form';
        }
        echo '<form' . ( 'integrations' === $area && 'nelxsdst_save' === $action ? ' id="nelxsdst-integrations-form"' : '' ) . ' method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="' . esc_attr( $form_class ) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '"><input type="hidden" name="area" value="' . esc_attr( $area ) . '">';
        wp_nonce_field( $action );
    }

    private function toggle( $name, $label, $description = '' ) {
        echo '<label class="nelxsdst-toggle-row"><span class="nelxsdst-switch"><input type="checkbox" name="' . esc_attr( $name ) . '" value="yes" ' . checked( get_option( $name, 'no' ), 'yes', false ) . '><span aria-hidden="true"></span></span><span>' . esc_html( $label ) . '</span></label>';
        if ( $description ) {
            echo '<p class="description">' . esc_html( $description ) . '</p>';
        }
    }

    private function role_select( $name, $selected ) {
        echo '<select class="nelxsdst-role-select" name="' . esc_attr( $name ) . '[]" multiple aria-label="' . esc_attr__( 'Allowed roles', 'nelx-secure-document-storage' ) . '">';
        foreach ( get_editable_roles() as $slug => $role ) {
            if ( ! empty( $role['capabilities']['manage_options'] ) ) { continue; }
            echo '<option value="' . esc_attr( $slug ) . '" ' . selected( in_array( $slug, (array) $selected, true ), true, false ) . '>' . esc_html( translate_user_role( $role['name'] ) ) . '</option>';
        }
        echo '</select>';
    }

    public function files_page() {
        $this->require_library();
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filters, per-file authorization follows.
        $page = isset( $_GET['nsf_page'] ) && is_scalar( $_GET['nsf_page'] ) ? max( 1, absint( $_GET['nsf_page'] ) ) : 1;
        $search = isset( $_GET['nsf_search'] ) && is_string( $_GET['nsf_search'] ) ? sanitize_text_field( wp_unslash( $_GET['nsf_search'] ) ) : '';
        $source = isset( $_GET['nsf_source'] ) && is_string( $_GET['nsf_source'] ) ? sanitize_key( $_GET['nsf_source'] ) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        if ( ! in_array( $source, [ 'manual', 'jetformbuilder', 'api', 'legacy' ], true ) ) { $source = ''; }
        $data = NELXSDST_Renderer::listing( $page, 20, $search, $source, true );
        $this->header(
            'files',
            current_user_can( 'manage_options' )
                ? __( 'Manage secure documents, uploads, and file access.', 'nelx-secure-document-storage' )
                : __( 'Browse and manage files in your secure library.', 'nelx-secure-document-storage' )
        );
        if ( NELXSDST_Storage_Service::can_upload() ) {
            ?>
            <details id="nelxsdst-upload" class="nelxsdst-panel"><summary><?php esc_html_e( 'Add Secure File', 'nelx-secure-document-storage' ); ?></summary>
                <div class="nelxsdst-admin-uploader" data-ns-uploader>
                    <input id="nelxsdst-file" class="nelxsdst-upload-input" type="file" multiple tabindex="-1" aria-hidden="true">
                    <div class="nelxsdst-dropzone" data-ns-dropzone tabindex="0" role="button" aria-controls="nelxsdst-file">
                        <span class="nelxsdst-dropzone-icon" aria-hidden="true"><span class="dashicons dashicons-cloud-upload"></span></span>
                        <strong><?php esc_html_e( 'Drag and drop files here', 'nelx-secure-document-storage' ); ?></strong>
                        <span><?php esc_html_e( 'or', 'nelx-secure-document-storage' ); ?></span>
                        <button type="button" class="button button-primary" data-ns-browse><?php esc_html_e( 'Browse Files', 'nelx-secure-document-storage' ); ?></button>
                        <small><?php /* translators: %s: formatted maximum upload size. */
                        echo esc_html( sprintf( __( 'Maximum %s per file. WordPress permitted file types apply. Uploaded files are secured immediately and owned by you.', 'nelx-secure-document-storage' ), size_format( wp_max_upload_size() ) ) ); ?></small>
                    </div>
                    <div class="nelxsdst-upload-error" data-ns-upload-error role="alert" hidden></div>
                    <div class="nelxsdst-upload-files" data-ns-upload-files></div>
                </div>
            </details>
            <?php
        }
        ?>
        <div class="nelxsdst-stats"><div><span><?php esc_html_e( 'Matching Secure Files', 'nelx-secure-document-storage' ); ?></span><strong data-ns-count><?php echo esc_html( number_format_i18n( $data['total'] ) ); ?></strong></div>
        <?php if ( current_user_can( 'manage_options' ) ) : ?><div><span><?php esc_html_e( 'Matching Storage Used', 'nelx-secure-document-storage' ); ?></span><strong data-ns-bytes><?php echo esc_html( size_format( $data['bytes'] ) ); ?></strong></div><div><span><?php esc_html_e( 'Pending Recovery', 'nelx-secure-document-storage' ); ?></span><strong><?php echo esc_html( number_format_i18n( NELXSDST_SecureStorage::get_instance()->get_pending_file_count() ) ); ?></strong><a href="<?php echo esc_url( admin_url( 'admin.php?page=nelx-secure-files#nelxsdst-recovery' ) ); ?>"><?php esc_html_e( 'Review pending files', 'nelx-secure-document-storage' ); ?></a></div><?php endif; ?></div>
        <section class="nelxsdst-panel"><div class="nelxsdst-toolbar">
            <label for="nsf-source"><?php esc_html_e( 'Source', 'nelx-secure-document-storage' ); ?></label>
            <select id="nsf-source"><option value=""><?php esc_html_e( 'All sources', 'nelx-secure-document-storage' ); ?></option>
                <?php foreach ( [ 'manual', 'jetformbuilder', 'api', 'legacy' ] as $value ) { echo '<option value="' . esc_attr( $value ) . '">' . esc_html( ucfirst( $value ) ) . '</option>'; } ?>
            </select></div>
            <div class="nelxsdst-table-scroll"><table id="nelxsdst-files-table" class="nelxsdst-native-table"><thead><tr>
                <?php foreach ( [ __( 'File', 'nelx-secure-document-storage' ), __( 'Type', 'nelx-secure-document-storage' ), __( 'Owner', 'nelx-secure-document-storage' ), __( 'Source', 'nelx-secure-document-storage' ), __( 'Date', 'nelx-secure-document-storage' ), __( 'Size', 'nelx-secure-document-storage' ), __( 'Actions', 'nelx-secure-document-storage' ) ] as $label ) { echo '<th scope="col">' . esc_html( $label ) . '</th>'; } ?>
            </tr></thead><tbody></tbody></table></div>
        </section>
        <?php if ( current_user_can( 'manage_options' ) ) { $this->recovery_panel(); } ?>
        <?php $this->footer();
    }

    public function access_page() {
        $this->require_admin();
        $this->header( 'access', __( 'Control file permissions and access to the backend library independently.', 'nelx-secure-document-storage' ) );
        $this->form_start( 'access' );
        ?>
        <section class="nelxsdst-panel"><h2><?php esc_html_e( 'Secure-file access', 'nelx-secure-document-storage' ); ?></h2><p><?php esc_html_e( 'Owners and administrators can access ordinary secured files by default. Selected roles below can view ordinary secured files across the site. Form-level grants provide additional access to files from specific forms. Files managed by an application-scoped policy must also pass that integration’s access check.', 'nelx-secure-document-storage' ); ?></p><label><?php esc_html_e( 'Roles allowed to access all secure files', 'nelx-secure-document-storage' ); ?></label><?php $this->role_select( 'nelxsdst_allowed_roles', get_option( 'nelxsdst_allowed_roles', [] ) ); ?>
        <?php $this->toggle( 'nelxsdst_hide_buttons_unauthorized', __( 'Hide file buttons from unauthorized users', 'nelx-secure-document-storage' ), __( 'This controls existing integration buttons. Native shortcodes always list only authorized files.', 'nelx-secure-document-storage' ) ); ?></section>
        <section class="nelxsdst-panel"><h2><?php esc_html_e( 'Backend library access', 'nelx-secure-document-storage' ); ?></h2>
            <p><?php esc_html_e( 'Administrators can open the Files screen by default. File owners can already view and download their ordinary secured files through permitted links or shortcodes; they do not need a backend role assignment. Select roles here only when users also need to browse files inside WordPress administration. Application-scoped files still follow their integration’s access policy.', 'nelx-secure-document-storage' ); ?></p>
            <p><label><?php esc_html_e( 'Roles allowed into Files — all secured files', 'nelx-secure-document-storage' ); ?></label></p>
            <p class="description"><?php esc_html_e( 'Grants backend library access and broad view/download permissions for ordinary secured files. Use only for trusted staff.', 'nelx-secure-document-storage' ); ?></p>
            <?php $this->role_select( 'nelxsdst_backend_all_roles', get_option( 'nelxsdst_backend_all_roles', [] ) ); ?>
            <p><label><?php esc_html_e( 'Roles allowed into Files — own uploads only', 'nelx-secure-document-storage' ); ?></label></p>
            <p class="description"><?php esc_html_e( 'Allows staff to open the backend Files list, which displays only files they own. This does not grant file ownership or change existing frontend access permissions.', 'nelx-secure-document-storage' ); ?></p>
            <?php $this->role_select( 'nelxsdst_backend_roles', get_option( 'nelxsdst_backend_roles', [] ) ); ?>
            <p class="description"><?php esc_html_e( 'When a role appears in both lists, all-files access takes priority. Uploading requires the WordPress upload_files capability; deletion requires permission to delete the attachment.', 'nelx-secure-document-storage' ); ?></p>
        </section>
        <?php $this->storage_panel(); ?>
        <div class="nelxsdst-form-actions nelxsdst-savebar" role="group" aria-label="<?php esc_attr_e( 'Save Access', 'nelx-secure-document-storage' ); ?>"><button type="submit" class="button button-primary"><?php esc_html_e( 'Save Access', 'nelx-secure-document-storage' ); ?></button></div></form>
        <?php $this->footer();
    }

    public static function integration_available( $name ) {
        if ( 'jetformbuilder' === $name ) { return function_exists( 'jet_form_builder' ) || defined( 'JET_FORM_BUILDER_VERSION' ) || post_type_exists( 'jet-form-builder' ); }
        if ( 'jetengine' === $name ) { return function_exists( 'jet_engine' ); }
        return did_action( 'elementor/loaded' ) || defined( 'ELEMENTOR_VERSION' );
    }

    public function integrations_page() {
        $this->require_admin();
        $this->header( 'integrations', __( 'Optional integrations and configurable builder-independent shortcodes.', 'nelx-secure-document-storage' ) );
        $this->shortcodes_panel();
        ?>
        <section class="nelxsdst-panel"><h2><?php esc_html_e( 'Optional integrations', 'nelx-secure-document-storage' ); ?></h2>
            <?php foreach ( [ 'jetformbuilder' => 'JetFormBuilder', 'jetengine' => 'JetEngine', 'elementor' => 'Elementor' ] as $key => $label ) : ?><div class="nelxsdst-integration"><strong><?php echo esc_html( $label ); ?></strong><span class="nelxsdst-badge"><?php echo esc_html( self::integration_available( $key ) ? __( 'Available', 'nelx-secure-document-storage' ) : __( 'Not active — optional', 'nelx-secure-document-storage' ) ); ?></span></div><?php endforeach; ?>
            <p><?php esc_html_e( 'JetEngine retains the Secure File Links Dynamic Field callback. Elementor retains its existing button, table and card styling controls when used with JetEngine.', 'nelx-secure-document-storage' ); ?></p>
        </section>
        <?php if ( self::integration_available( 'jetformbuilder' ) ) :
            $this->form_start( 'integrations' );
            $forms = get_posts( [ 'post_type' => 'jet-form-builder', 'post_status' => 'publish', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC' ] );
            $selected = array_map( 'absint', (array) get_option( 'nelxsdst_secure_forms', [] ) );
            $access = array_map( 'absint', (array) get_option( 'nelxsdst_form_level_access', [] ) );
            $roles = get_option( 'nelxsdst_form_level_roles', [] );
            ?>
            <section class="nelxsdst-panel"><h2><?php esc_html_e( 'JetFormBuilder uploads', 'nelx-secure-document-storage' ); ?></h2>
                <?php $this->toggle( 'nelxsdst_auto_secure_all', __( 'Automatically secure uploads from all forms', 'nelx-secure-document-storage' ) ); ?>
                <p class="description"><?php esc_html_e( 'When disabled, only selected forms are secured. Form-level grants remain available in either mode.', 'nelx-secure-document-storage' ); ?></p>
                <input type="search" class="nelxsdst-form-search" aria-label="<?php esc_attr_e( 'Find a form', 'nelx-secure-document-storage' ); ?>" placeholder="<?php esc_attr_e( 'Find a form…', 'nelx-secure-document-storage' ); ?>">
                <div class="nelxsdst-form-list">
                <?php foreach ( $forms as $form ) : ?>
                    <article class="nelxsdst-form-card" data-form-name="<?php echo esc_attr( strtolower( $form->post_title ) ); ?>"><h3><?php echo esc_html( $form->post_title ); ?> <small>#<?php echo esc_html( $form->ID ); ?></small></h3>
                        <label class="nelxsdst-toggle-row"><span class="nelxsdst-switch"><input type="checkbox" name="nelxsdst_secure_forms[]" value="<?php echo esc_attr( $form->ID ); ?>" <?php checked( in_array( $form->ID, $selected, true ) ); ?>><span aria-hidden="true"></span></span><?php esc_html_e( 'Secure uploads from this form', 'nelx-secure-document-storage' ); ?></label>
                        <div class="nelxsdst-form-access" <?php if ( ! in_array( $form->ID, $selected, true ) ) { echo 'hidden'; } ?>><label class="nelxsdst-toggle-row"><span class="nelxsdst-switch"><input type="checkbox" name="nelxsdst_form_level_access[]" value="<?php echo esc_attr( $form->ID ); ?>" <?php checked( in_array( $form->ID, $access, true ) ); ?>><span aria-hidden="true"></span></span><?php esc_html_e( 'Grant form-level role access', 'nelx-secure-document-storage' ); ?></label>
                        <div class="nelxsdst-form-roles" <?php if ( ! in_array( $form->ID, $access, true ) ) { echo 'hidden'; } ?>><?php $this->role_select( 'nelxsdst_form_level_roles[' . $form->ID . ']', $roles[ $form->ID ] ?? [] ); ?></div></div>
                    </article>
                <?php endforeach; ?></div>
                <?php if ( ! $forms ) : ?><p><?php esc_html_e( 'No published forms found.', 'nelx-secure-document-storage' ); ?></p><?php endif; ?>
                <div class="nelxsdst-form-pager"><button type="button" class="button" data-ns-prev><?php esc_html_e( 'Previous', 'nelx-secure-document-storage' ); ?></button><span aria-live="polite" data-ns-form-page></span><button type="button" class="button" data-ns-next><?php esc_html_e( 'Next', 'nelx-secure-document-storage' ); ?></button></div>
            </section>
            <section class="nelxsdst-panel"><h2><?php esc_html_e( 'Form processing', 'nelx-secure-document-storage' ); ?></h2>
                <?php $conflict = self::feedback_conflict(); ?>
                <label class="nelxsdst-toggle-row <?php echo $conflict ? 'is-disabled' : ''; ?>"><span class="nelxsdst-switch"><input type="checkbox" name="nelxsdst_enable_form_modal" value="yes" <?php checked( get_option( 'nelxsdst_enable_form_modal', 'no' ), 'yes' ); disabled( $conflict ); ?>><span aria-hidden="true"></span></span><?php esc_html_e( 'Show form processing modal', 'nelx-secure-document-storage' ); ?></label>
                <?php if ( $conflict ) : ?><p class="description"><?php esc_html_e( 'Nelx JFB Email Templates already provides form processing feedback.', 'nelx-secure-document-storage' ); ?></p><?php endif; ?>
            </section>
            </form>
            <div class="nelxsdst-form-actions nelxsdst-savebar nelxsdst-savebar--integrations" role="group" aria-label="<?php esc_attr_e( 'Save Integrations', 'nelx-secure-document-storage' ); ?>"><button type="submit" form="nelxsdst-integrations-form" class="button button-primary"><?php esc_html_e( 'Save Integrations', 'nelx-secure-document-storage' ); ?></button></div>
        <?php endif;
        $this->footer();
    }

    private function shortcodes_panel() {
        $presets = NELXSDST_Shortcode_Presets::all();
        ?>
        <section class="nelxsdst-panel nelxsdst-shortcodes-panel">
            <div class="nelxsdst-section-heading"><div><h2><?php esc_html_e( 'Shortcode Builder', 'nelx-secure-document-storage' ); ?></h2><p class="description"><?php esc_html_e( 'Create reusable context-aware shortcodes and style each output independently. Existing hand-written shortcodes remain supported.', 'nelx-secure-document-storage' ); ?></p></div></div>
            <div class="nelxsdst-shortcode-list" data-ns-shortcode-list>
                <?php if ( ! $presets ) : ?><div class="nelxsdst-shortcode-empty" data-ns-shortcode-empty><p><?php esc_html_e( 'No saved shortcode configurations yet.', 'nelx-secure-document-storage' ); ?></p></div><?php endif; ?>
                <?php foreach ( $presets as $id => $preset ) { $this->shortcode_card( $id, wp_parse_args( $preset, NELXSDST_Shortcode_Presets::defaults() ) ); } ?>
            </div>
            <div class="nelxsdst-shortcode-create-row"><button type="button" class="button button-primary nelxsdst-create-shortcode" data-ns-sc-create><span class="dashicons dashicons-plus" aria-hidden="true"></span><?php esc_html_e( 'Create New Shortcode', 'nelx-secure-document-storage' ); ?></button></div>
        </section>
        <?php
    }

    private function color_control( $name, $label, $value ) {
        echo '<label class="nelxsdst-style-field"><span>' . esc_html( $label ) . '</span><input type="color" name="preset[' . esc_attr( $name ) . ']" value="' . esc_attr( $value ) . '"></label>';
    }

    private function number_control( $name, $label, $value, $min = 0, $max = 100 ) {
        echo '<label class="nelxsdst-style-field"><span>' . esc_html( $label ) . '</span><input type="number" name="preset[' . esc_attr( $name ) . ']" value="' . esc_attr( $value ) . '" min="' . esc_attr( $min ) . '" max="' . esc_attr( $max ) . '"></label>';
    }

    private function shortcode_card( $id, $preset, $open = false ) {
        $preset = wp_parse_args( $preset, NELXSDST_Shortcode_Presets::defaults() );
        $code = NELXSDST_Shortcode_Presets::shortcode( $id, $preset );
        ?>
        <article class="nelxsdst-shortcode-card<?php echo $open ? ' is-open' : ''; ?>" data-preset-id="<?php echo esc_attr( $id ); ?>">
            <header class="nelxsdst-shortcode-card-header" data-ns-shortcode-toggle tabindex="0" role="button" aria-expanded="<?php echo $open ? 'true' : 'false'; ?>">
                <div class="nelxsdst-shortcode-card-title"><span class="dashicons dashicons-arrow-down-alt2 nelxsdst-shortcode-chevron" aria-hidden="true"></span><code data-ns-shortcode-title><?php echo esc_html( $code ); ?></code></div>
                <div class="nelxsdst-shortcode-card-actions">
                    <button type="button" class="button nelxsdst-icon-button" data-ns-sc-copy title="<?php esc_attr_e( 'Copy shortcode', 'nelx-secure-document-storage' ); ?>" aria-label="<?php esc_attr_e( 'Copy shortcode', 'nelx-secure-document-storage' ); ?>"><span class="dashicons dashicons-clipboard"></span></button>
                    <button type="button" class="button nelxsdst-icon-button" data-ns-sc-duplicate title="<?php esc_attr_e( 'Duplicate shortcode', 'nelx-secure-document-storage' ); ?>" aria-label="<?php esc_attr_e( 'Duplicate shortcode', 'nelx-secure-document-storage' ); ?>"><span class="dashicons dashicons-admin-page"></span></button>
                    <button type="button" class="button nelxsdst-icon-button" data-ns-sc-delete title="<?php esc_attr_e( 'Delete shortcode', 'nelx-secure-document-storage' ); ?>" aria-label="<?php esc_attr_e( 'Delete shortcode', 'nelx-secure-document-storage' ); ?>"><span class="dashicons dashicons-trash"></span></button>
                </div>
            </header>
            <div class="nelxsdst-shortcode-card-body" <?php if ( ! $open ) { echo 'hidden'; } ?>>
                <form class="nelxsdst-shortcode-form">
                    <input type="hidden" name="action" value="nelxsdst_shortcode_save">
                    <?php wp_nonce_field( 'nelxsdst_shortcodes', 'nonce', false ); ?>
                    <input type="hidden" name="preset_id" value="<?php echo esc_attr( $id ); ?>">
                    <div class="nelxsdst-builder-section"><h3><?php esc_html_e( 'Content & context', 'nelx-secure-document-storage' ); ?></h3><div class="nelxsdst-builder-grid">
                        <label><span><?php esc_html_e( 'Shortcode type', 'nelx-secure-document-storage' ); ?></span><select name="preset[type]"><option value="files" <?php selected( $preset['type'], 'files' ); ?>><?php esc_html_e( 'Secure files', 'nelx-secure-document-storage' ); ?></option><option value="file" <?php selected( $preset['type'], 'file' ); ?>><?php esc_html_e( 'Single secure file', 'nelx-secure-document-storage' ); ?></option></select></label>
                        <label data-ns-attachment><span><?php esc_html_e( 'Attachment ID', 'nelx-secure-document-storage' ); ?></span><input type="number" min="1" name="preset[attachment_id]" value="<?php echo esc_attr( $preset['attachment_id'] ); ?>" placeholder="123"></label>
                        <label><span><?php esc_html_e( 'Field / meta key', 'nelx-secure-document-storage' ); ?></span><input type="text" name="preset[field]" value="<?php echo esc_attr( $preset['field'] ); ?>" placeholder="qualifications"></label>
                        <label data-ns-contextual><span><?php esc_html_e( 'Context', 'nelx-secure-document-storage' ); ?></span><select name="preset[context]"><?php foreach ( [ 'auto' => __( 'Auto', 'nelx-secure-document-storage' ), 'post' => __( 'Post', 'nelx-secure-document-storage' ), 'user' => __( 'User', 'nelx-secure-document-storage' ), 'current_user' => __( 'Current user', 'nelx-secure-document-storage' ) ] as $value => $label ) { echo '<option value="' . esc_attr( $value ) . '" ' . selected( $preset['context'], $value, false ) . '>' . esc_html( $label ) . '</option>'; } ?></select></label>
                        <label data-ns-contextual><span><?php esc_html_e( 'Object ID (optional)', 'nelx-secure-document-storage' ); ?></span><input type="number" min="0" name="preset[object_id]" value="<?php echo esc_attr( $preset['object_id'] ); ?>"></label>
                        <label data-ns-per-page><span><?php esc_html_e( 'Files per page', 'nelx-secure-document-storage' ); ?></span><input type="number" min="1" max="100" name="preset[per_page]" value="<?php echo esc_attr( $preset['per_page'] ); ?>"></label>
                    </div>
                    <label class="nelxsdst-toggle-row" data-ns-repeater><span class="nelxsdst-switch"><input type="hidden" name="preset[repeater]" value="no"><input type="checkbox" name="preset[repeater]" value="yes" <?php checked( $preset['repeater'], 'yes' ); ?>><span aria-hidden="true"></span></span><span><?php esc_html_e( 'This field is a repeater', 'nelx-secure-document-storage' ); ?></span></label>
                    <div class="nelxsdst-builder-grid" data-ns-layout><label><span><?php esc_html_e( 'Repeater layout', 'nelx-secure-document-storage' ); ?></span><select name="preset[layout]"><option value="cards" <?php selected( $preset['layout'], 'cards' ); ?>><?php esc_html_e( 'Cards', 'nelx-secure-document-storage' ); ?></option><option value="table" <?php selected( $preset['layout'], 'table' ); ?>><?php esc_html_e( 'Table', 'nelx-secure-document-storage' ); ?></option></select></label></div></div>

                    <div class="nelxsdst-builder-section"><h3><?php esc_html_e( 'File actions', 'nelx-secure-document-storage' ); ?></h3><div class="nelxsdst-action-toggle-grid">
                        <div><input type="hidden" name="preset[show_view]" value="no"><label class="nelxsdst-toggle-row"><span class="nelxsdst-switch"><input type="checkbox" name="preset[show_view]" value="yes" <?php checked( $preset['show_view'], 'yes' ); ?>><span aria-hidden="true"></span></span><span><?php esc_html_e( 'Show View button', 'nelx-secure-document-storage' ); ?></span></label><input type="text" name="preset[view_text]" value="<?php echo esc_attr( $preset['view_text'] ); ?>" aria-label="<?php esc_attr_e( 'View button label', 'nelx-secure-document-storage' ); ?>"></div>
                        <div><input type="hidden" name="preset[show_download]" value="no"><label class="nelxsdst-toggle-row"><span class="nelxsdst-switch"><input type="checkbox" name="preset[show_download]" value="yes" <?php checked( $preset['show_download'], 'yes' ); ?>><span aria-hidden="true"></span></span><span><?php esc_html_e( 'Show Download button', 'nelx-secure-document-storage' ); ?></span></label><input type="text" name="preset[download_text]" value="<?php echo esc_attr( $preset['download_text'] ); ?>" aria-label="<?php esc_attr_e( 'Download button label', 'nelx-secure-document-storage' ); ?>"></div>
                    </div></div>

                    <div class="nelxsdst-builder-section"><h3><?php esc_html_e( 'Button styling', 'nelx-secure-document-storage' ); ?></h3>
                        <div class="nelxsdst-style-groups"><div><h4><?php esc_html_e( 'View — normal', 'nelx-secure-document-storage' ); ?></h4><div class="nelxsdst-style-grid"><?php $this->color_control( 'view_bg', __( 'Background', 'nelx-secure-document-storage' ), $preset['view_bg'] ); $this->color_control( 'view_color', __( 'Text', 'nelx-secure-document-storage' ), $preset['view_color'] ); $this->color_control( 'view_border', __( 'Border', 'nelx-secure-document-storage' ), $preset['view_border'] ); ?></div></div>
                        <div><h4><?php esc_html_e( 'View — hover', 'nelx-secure-document-storage' ); ?></h4><div class="nelxsdst-style-grid"><?php $this->color_control( 'view_hover_bg', __( 'Background', 'nelx-secure-document-storage' ), $preset['view_hover_bg'] ); $this->color_control( 'view_hover_color', __( 'Text', 'nelx-secure-document-storage' ), $preset['view_hover_color'] ); $this->color_control( 'view_hover_border', __( 'Border', 'nelx-secure-document-storage' ), $preset['view_hover_border'] ); ?></div></div>
                        <div><h4><?php esc_html_e( 'Download — normal', 'nelx-secure-document-storage' ); ?></h4><div class="nelxsdst-style-grid"><?php $this->color_control( 'download_bg', __( 'Background', 'nelx-secure-document-storage' ), $preset['download_bg'] ); $this->color_control( 'download_color', __( 'Text', 'nelx-secure-document-storage' ), $preset['download_color'] ); $this->color_control( 'download_border', __( 'Border', 'nelx-secure-document-storage' ), $preset['download_border'] ); ?></div></div>
                        <div><h4><?php esc_html_e( 'Download — hover', 'nelx-secure-document-storage' ); ?></h4><div class="nelxsdst-style-grid"><?php $this->color_control( 'download_hover_bg', __( 'Background', 'nelx-secure-document-storage' ), $preset['download_hover_bg'] ); $this->color_control( 'download_hover_color', __( 'Text', 'nelx-secure-document-storage' ), $preset['download_hover_color'] ); $this->color_control( 'download_hover_border', __( 'Border', 'nelx-secure-document-storage' ), $preset['download_hover_border'] ); ?></div></div></div>
                        <div class="nelxsdst-style-grid nelxsdst-style-grid-numbers"><?php $this->number_control( 'button_border_width', __( 'Border width', 'nelx-secure-document-storage' ), $preset['button_border_width'], 0, 12 ); $this->number_control( 'button_font_size', __( 'Font size', 'nelx-secure-document-storage' ), $preset['button_font_size'], 8, 40 ); $this->number_control( 'button_radius', __( 'Radius (px)', 'nelx-secure-document-storage' ), $preset['button_radius'], 0, 80 ); $this->number_control( 'button_padding_y', __( 'Vertical padding', 'nelx-secure-document-storage' ), $preset['button_padding_y'], 0, 40 ); $this->number_control( 'button_padding_x', __( 'Horizontal padding', 'nelx-secure-document-storage' ), $preset['button_padding_x'], 0, 80 ); $this->number_control( 'button_gap', __( 'Button gap', 'nelx-secure-document-storage' ), $preset['button_gap'], 0, 50 ); ?></div>
                    </div>

                    <div class="nelxsdst-builder-section" data-ns-style-cards><h3><?php esc_html_e( 'Card styling', 'nelx-secure-document-storage' ); ?></h3><div class="nelxsdst-style-grid"><?php $this->color_control( 'card_bg', __( 'Background', 'nelx-secure-document-storage' ), $preset['card_bg'] ); $this->color_control( 'card_hover_bg', __( 'Hover background', 'nelx-secure-document-storage' ), $preset['card_hover_bg'] ); $this->color_control( 'card_border', __( 'Border', 'nelx-secure-document-storage' ), $preset['card_border'] ); $this->color_control( 'card_hover_border', __( 'Hover border', 'nelx-secure-document-storage' ), $preset['card_hover_border'] ); $this->number_control( 'card_border_width', __( 'Border width', 'nelx-secure-document-storage' ), $preset['card_border_width'], 0, 12 ); $this->color_control( 'card_label_color', __( 'Label text', 'nelx-secure-document-storage' ), $preset['card_label_color'] ); $this->color_control( 'card_value_color', __( 'Value text', 'nelx-secure-document-storage' ), $preset['card_value_color'] ); $this->number_control( 'card_radius', __( 'Radius (px)', 'nelx-secure-document-storage' ), $preset['card_radius'], 0, 80 ); $this->number_control( 'card_padding', __( 'Padding (px)', 'nelx-secure-document-storage' ), $preset['card_padding'], 0, 80 ); $this->number_control( 'card_gap', __( 'Gap (px)', 'nelx-secure-document-storage' ), $preset['card_gap'], 0, 80 ); $this->number_control( 'card_columns', __( 'Columns', 'nelx-secure-document-storage' ), $preset['card_columns'], 1, 6 ); ?></div><div class="nelxsdst-builder-grid"><label><span><?php esc_html_e( 'Shadow', 'nelx-secure-document-storage' ); ?></span><input type="text" name="preset[card_shadow]" value="<?php echo esc_attr( $preset['card_shadow'] ); ?>"></label><label><span><?php esc_html_e( 'Hover shadow', 'nelx-secure-document-storage' ); ?></span><input type="text" name="preset[card_hover_shadow]" value="<?php echo esc_attr( $preset['card_hover_shadow'] ); ?>"></label></div></div>

                    <div class="nelxsdst-builder-section" data-ns-style-table><h3><?php esc_html_e( 'Table styling', 'nelx-secure-document-storage' ); ?></h3><div class="nelxsdst-style-grid"><?php $this->color_control( 'table_header_bg', __( 'Header background', 'nelx-secure-document-storage' ), $preset['table_header_bg'] ); $this->color_control( 'table_header_color', __( 'Header text', 'nelx-secure-document-storage' ), $preset['table_header_color'] ); $this->color_control( 'table_body_color', __( 'Body text', 'nelx-secure-document-storage' ), $preset['table_body_color'] ); $this->color_control( 'table_row_bg', __( 'Row background', 'nelx-secure-document-storage' ), $preset['table_row_bg'] ); $this->color_control( 'table_row_hover_bg', __( 'Row hover', 'nelx-secure-document-storage' ), $preset['table_row_hover_bg'] ); $this->color_control( 'table_border', __( 'Borders', 'nelx-secure-document-storage' ), $preset['table_border'] ); $this->number_control( 'table_border_width', __( 'Border width', 'nelx-secure-document-storage' ), $preset['table_border_width'], 0, 12 ); $this->number_control( 'table_header_font_size', __( 'Header font size', 'nelx-secure-document-storage' ), $preset['table_header_font_size'], 8, 40 ); $this->number_control( 'table_body_font_size', __( 'Body font size', 'nelx-secure-document-storage' ), $preset['table_body_font_size'], 8, 40 ); $this->number_control( 'table_cell_padding', __( 'Cell padding', 'nelx-secure-document-storage' ), $preset['table_cell_padding'], 0, 50 ); $this->number_control( 'table_radius', __( 'Radius (px)', 'nelx-secure-document-storage' ), $preset['table_radius'], 0, 80 ); ?></div></div>

                    <div class="nelxsdst-shortcode-output"><label><span><?php esc_html_e( 'Generated shortcode', 'nelx-secure-document-storage' ); ?></span><div class="nelxsdst-code"><code data-ns-shortcode-output><?php echo esc_html( $code ); ?></code><button type="button" class="button" data-ns-sc-copy><?php esc_html_e( 'Copy Shortcode', 'nelx-secure-document-storage' ); ?></button></div></label></div>
                    <div class="nelxsdst-form-actions"><button type="submit" class="button button-primary" data-ns-sc-save><?php esc_html_e( 'Save Shortcode', 'nelx-secure-document-storage' ); ?></button></div>
                </form>
            </div>
        </article>
        <?php
    }

    private function storage_panel() {
        $root = NELXSDST_SecureStorage::get_storage_root();
        $public_roots = [ ABSPATH, isset($_SERVER['DOCUMENT_ROOT']) && is_string($_SERVER['DOCUMENT_ROOT']) ? sanitize_text_field(wp_unslash($_SERVER['DOCUMENT_ROOT'])) : ABSPATH ];
        $private = (bool) $root;
        foreach ( $public_roots as $public ) {
            if ( realpath( $root ) === realpath( $public ) || NELXSDST_SecureStorage::path_is_within( $root, $public ) ) { $private = false; }
        }
        ?>
        <section class="nelxsdst-panel"><h2><?php esc_html_e( 'Storage', 'nelx-secure-document-storage' ); ?></h2>
            <p><span class="nelxsdst-badge"><?php echo esc_html( $private ? __( 'Private filesystem storage', 'nelx-secure-document-storage' ) : __( 'Protected uploads storage', 'nelx-secure-document-storage' ) ); ?></span></p>
            <details><summary><?php esc_html_e( 'New files location', 'nelx-secure-document-storage' ); ?></summary><p><code><?php echo esc_html( $root ?: __( 'Not configured', 'nelx-secure-document-storage' ) ); ?></code></p><p><strong><?php esc_html_e( 'Existing files location', 'nelx-secure-document-storage' ); ?></strong><br><code><?php echo esc_html( get_option( 'nelxsdst_secure_storage_path', '' ) ); ?></code></p></details><dl class="nelxsdst-storage-details"><dt><?php esc_html_e( 'Filesystem status', 'nelx-secure-document-storage' ); ?></dt><dd><?php echo esc_html( $root && is_dir( $root ) && NELXSDST_Storage_Service::filesystem()->is_writable( $root ) ? __( 'Directory is writable', 'nelx-secure-document-storage' ) : __( 'Directory is unavailable or not writable', 'nelx-secure-document-storage' ) ); ?></dd></dl>
        </section>
        <?php
    }

    private function recovery_panel() {
        $pending = NELXSDST_SecureStorage::get_instance()->get_pending_file_count();
        ?>
        <section id="nelxsdst-recovery" class="nelxsdst-panel"><h2><?php esc_html_e( 'Pending recovery', 'nelx-secure-document-storage' ); ?></h2><p><?php /* translators: %s: number of recoverable attachment records. */
        echo esc_html( sprintf( __( 'Recoverable pending attachments: %s', 'nelx-secure-document-storage' ), number_format_i18n( $pending ) ) ); ?></p><p class="description"><?php esc_html_e( 'Recovery checks exact paths and preserves the recorded file owner. Files without attachment records are not adopted or deleted automatically.', 'nelx-secure-document-storage' ); ?></p>
        <?php $this->form_start( 'files', 'nelxsdst_recover' ); ?><button type="submit" class="button button-primary" <?php disabled( 0, $pending ); ?>><?php esc_html_e( 'Recover Pending Files', 'nelx-secure-document-storage' ); ?></button></form></section>
        <?php
    }

    public static function feedback_conflict() {
        $options = get_option( 'njet_settings', [] );
        return class_exists( 'Nelx\\JFBEmailTemplate\\Plugin_Conflict_Handler' ) && isset( $options['form_submit_processing_enabled'] ) && 1 === (int) $options['form_submit_processing_enabled'];
    }

    public function donate_page() {
        $this->require_admin();
        $this->header( 'donate', __( 'Support continued plugin development.', 'nelx-secure-document-storage' ) );
        $qr_url = NELXSDST_PLUGIN_URL . 'assets/bqr/binance-qr.jpeg';
        ?>
        <section class="nelxsdst-panel nelxsdst-donate"><h2><?php esc_html_e( 'Love this Plugin?', 'nelx-secure-document-storage' ); ?></h2><p><?php esc_html_e( 'Show some Love', 'nelx-secure-document-storage' ); ?></p>
        <a href="<?php echo esc_url( $qr_url ); ?>" class="nelxsdst-qr-lightbox" aria-label="<?php esc_attr_e( 'Open Binance QR code', 'nelx-secure-document-storage' ); ?>"><img src="<?php echo esc_url( $qr_url ); ?>" alt="<?php esc_attr_e( 'Binance QR Code', 'nelx-secure-document-storage' ); ?>"></a>
        <p><a href="mailto:astariko@nelxstudio.com"><?php esc_html_e( 'Chat with the Author', 'nelx-secure-document-storage' ); ?></a></p></section>
        <div id="nelxsdst-lightbox" class="nelxsdst-lightbox" hidden role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'QR code preview', 'nelx-secure-document-storage' ); ?>">
            <div class="nelxsdst-lightbox-inner"><img id="nelxsdst-lightbox-img" src="" alt="<?php esc_attr_e( 'Binance QR Code', 'nelx-secure-document-storage' ); ?>"><button type="button" id="nelxsdst-lightbox-close" aria-label="<?php esc_attr_e( 'Close preview', 'nelx-secure-document-storage' ); ?>">&times;</button></div>
        </div>
        <?php $this->footer();
    }

    private function posted( $key ) {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- Handlers verify their action nonce before mutations; delete reads an ID to construct its nonce action.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Recursive scalar sanitization here; typed validation below.
        return isset( $_POST[ $key ] ) ? map_deep( wp_unslash( $_POST[ $key ] ), 'sanitize_text_field' ) : [];
        // phpcs:enable WordPress.Security.NonceVerification.Missing
    }

    public function sanitize_roles_array( $input ) {
        return is_array( $input ) ? array_values( array_intersect( array_map( 'sanitize_key', array_filter( $input, 'is_scalar' ) ), array_keys( get_editable_roles() ) ) ) : [];
    }

    public function save() {
        $this->require_admin();
        if ( wp_doing_ajax() ) { check_ajax_referer( 'nelxsdst_save', '_wpnonce' ); } else { check_admin_referer( 'nelxsdst_save' ); }
        $area = $this->posted( 'area' );
        if ( 'access' === $area ) {
            foreach ( [ 'nelxsdst_allowed_roles', 'nelxsdst_backend_roles', 'nelxsdst_backend_all_roles' ] as $key ) {
                update_option( $key, $this->sanitize_roles_array( $this->posted( $key ) ) );
            }
            update_option( 'nelxsdst_hide_buttons_unauthorized', 'yes' === $this->posted( 'nelxsdst_hide_buttons_unauthorized' ) ? 'yes' : 'no' );
            $this->sync_capabilities( get_option( 'nelxsdst_backend_roles', [] ) );
        } elseif ( 'integrations' === $area && self::integration_available( 'jetformbuilder' ) ) {
            foreach ( [ 'nelxsdst_secure_forms', 'nelxsdst_form_level_access' ] as $key ) {
                $ids = $this->posted( $key );
                update_option( $key, is_array( $ids ) ? array_values( array_filter( array_map( 'absint', array_filter( $ids, 'is_scalar' ) ) ) ) : [] );
            }
            $roles = [];
            foreach ( (array) $this->posted( 'nelxsdst_form_level_roles' ) as $id => $values ) {
                if ( absint( $id ) ) { $roles[ absint( $id ) ] = $this->sanitize_roles_array( $values ); }
            }
            update_option( 'nelxsdst_form_level_roles', $roles );
            foreach ( [ 'nelxsdst_auto_secure_all', 'nelxsdst_enable_form_modal' ] as $key ) {
                if ( 'nelxsdst_enable_form_modal' !== $key || ! self::feedback_conflict() ) { update_option( $key, 'yes' === $this->posted( $key ) ? 'yes' : 'no' ); }
            }
        } else {
            wp_die( esc_html__( 'Invalid settings area.', 'nelx-secure-document-storage' ), '', [ 'response' => 400 ] );
        }
        if ( wp_doing_ajax() ) { wp_send_json_success( [ 'message' => __( 'Settings saved.', 'nelx-secure-document-storage' ) ] ); }
        $this->redirect( $area, __( 'Settings saved.', 'nelx-secure-document-storage' ) );
    }


    private function shortcode_ajax_auth() {
        $this->require_admin();
        check_ajax_referer( 'nelxsdst_shortcodes', 'nonce' );
    }

    private function shortcode_card_html( $id, $preset, $open = true ) {
        ob_start();
        $this->shortcode_card( $id, $preset, $open );
        return ob_get_clean();
    }

    public function shortcode_create() {
        $this->shortcode_ajax_auth();
        $result = NELXSDST_Shortcode_Presets::create();
        wp_send_json_success( [
            'message' => __( 'Shortcode created.', 'nelx-secure-document-storage' ),
            'id' => $result['id'],
            'shortcode' => NELXSDST_Shortcode_Presets::shortcode( $result['id'], $result['preset'] ),
            'html' => $this->shortcode_card_html( $result['id'], $result['preset'], true ),
        ] );
    }

    public function shortcode_save() {
        $this->shortcode_ajax_auth();
        $id = $this->posted( 'preset_id' );
        $preset = $this->posted( 'preset' );
        $result = NELXSDST_Shortcode_Presets::save( is_scalar( $id ) ? $id : '', is_array( $preset ) ? $preset : [] );
        $saved = NELXSDST_Shortcode_Presets::get( $result['id'] );
        if ( ! $saved ) {
            wp_send_json_error( [ 'message' => __( 'The shortcode could not be read back after saving.', 'nelx-secure-document-storage' ) ], 500 );
        }
        wp_send_json_success( [
            'message' => __( 'Shortcode saved.', 'nelx-secure-document-storage' ),
            'id' => $result['id'],
            'shortcode' => NELXSDST_Shortcode_Presets::shortcode( $result['id'], $saved ),
            'html' => $this->shortcode_card_html( $result['id'], $saved, true ),
        ] );
    }

    public function shortcode_duplicate() {
        $this->shortcode_ajax_auth();
        $id = $this->posted( 'preset_id' );
        $result = NELXSDST_Shortcode_Presets::duplicate( is_scalar( $id ) ? $id : '' );
        if ( ! $result ) {
            wp_send_json_error( [ 'message' => __( 'Unable to duplicate this shortcode.', 'nelx-secure-document-storage' ) ], 404 );
        }
        wp_send_json_success( [
            'message' => __( 'Shortcode duplicated.', 'nelx-secure-document-storage' ),
            'id' => $result['id'],
            'shortcode' => NELXSDST_Shortcode_Presets::shortcode( $result['id'], $result['preset'] ),
            'html' => $this->shortcode_card_html( $result['id'], $result['preset'], true ),
        ] );
    }

    public function shortcode_delete() {
        $this->shortcode_ajax_auth();
        $id = $this->posted( 'preset_id' );
        if ( ! NELXSDST_Shortcode_Presets::delete( is_scalar( $id ) ? $id : '' ) ) {
            wp_send_json_error( [ 'message' => __( 'Unable to delete this shortcode.', 'nelx-secure-document-storage' ) ], 404 );
        }
        wp_send_json_success( [ 'message' => __( 'Shortcode deleted.', 'nelx-secure-document-storage' ) ] );
    }

    public function files_table() {
        $this->require_library();
        check_ajax_referer( 'nelxsdst_files_table', '_ajax_nonce' );
        $draw = $this->posted( 'draw' );
        $start = $this->posted( 'start' );
        $length = $this->posted( 'length' );
        $start = is_scalar( $start ) ? absint( $start ) : 0;
        $requested_length = is_scalar( $length ) ? intval( $length ) : 10;
        $length = -1 === $requested_length ? -1 : min( 100, max( 1, $requested_length ) );
        $search = $this->posted( 'search' );
        $search = is_array( $search ) && isset( $search['value'] ) && is_string( $search['value'] ) ? $search['value'] : '';
        $source = $this->posted( 'source' );
        $source = is_string( $source ) && in_array( $source, [ 'manual', 'jetformbuilder', 'api', 'legacy' ], true ) ? $source : '';
        $total = NELXSDST_Renderer::listing( 1, 20, '', '', true );
        $data = NELXSDST_Renderer::listing( 1, 100, $search, $source, true, true );
        $order = $this->posted( 'order' );
        $column = is_array( $order ) && isset( $order[0]['column'] ) && is_scalar( $order[0]['column'] ) ? absint( $order[0]['column'] ) : 4;
        $direction = is_array( $order ) && isset( $order[0]['dir'] ) && 'asc' === $order[0]['dir'] ? 1 : -1;
        $sort = [];
        foreach ( $data['ids'] as $id ) {
            $owner = get_userdata( absint( get_post_meta( $id, '_nelxsdst_secure_owner', true ) ) );
            $values = [ self::file_name( $id ), get_post_mime_type( $id ), $owner ? $owner->display_name : '', NELXSDST_Renderer::source( $id ), get_post_field( 'post_date', $id ), NELXSDST_Renderer::size( $id ) ];
            $sort[ $id ] = $values[ min( 5, $column ) ];
        }
        usort( $data['ids'], function ( $a, $b ) use ( $sort, $direction ) {
            return $direction * ( ( is_int( $sort[ $a ] ) && is_int( $sort[ $b ] ) ? $sort[ $a ] <=> $sort[ $b ] : strnatcasecmp( (string) $sort[ $a ], (string) $sort[ $b ] ) ) ?: ( $a <=> $b ) );
        } );
        $rows = [];
        $page_ids = -1 === $length ? array_slice( $data['ids'], $start ) : array_slice( $data['ids'], $start, $length );
        foreach ( $page_ids as $id ) {
            $owner = get_userdata( absint( get_post_meta( $id, '_nelxsdst_secure_owner', true ) ) );
            $actions = NELXSDST_Renderer::buttons( $id, [ 'view_text' => __( 'View', 'nelx-secure-document-storage' ), 'download_text' => __( 'Download', 'nelx-secure-document-storage' ) ] );
            if ( NELXSDST_Storage_Service::can_delete( $id ) ) {
                $actions .= '<form class="nelxsdst-delete-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="nelxsdst_delete"><input type="hidden" name="file_id" value="' . esc_attr( $id ) . '"><input type="hidden" name="_wpnonce" value="' . esc_attr( wp_create_nonce( 'nelxsdst_delete_' . $id ) ) . '"><button type="submit" class="button-link-delete">' . esc_html__( 'Delete', 'nelx-secure-document-storage' ) . '</button></form>';
            }
            $rows[] = [ '<strong>' . esc_html( self::file_name( $id ) ) . '</strong><small>#' . esc_html( $id ) . '</small>', esc_html( get_post_mime_type( $id ) ), esc_html( $owner ? $owner->display_name : __( 'Guest / unknown', 'nelx-secure-document-storage' ) ), esc_html( ucfirst( NELXSDST_Renderer::source( $id ) ) ), esc_html( get_the_date( '', $id ) ), esc_html( size_format( NELXSDST_Renderer::size( $id ) ) ), '<div class="nelxsdst-actions">' . $actions . '</div>' ];
        }
        wp_send_json( [ 'draw' => is_scalar( $draw ) ? absint( $draw ) : 0, 'recordsTotal' => $total['total'], 'recordsFiltered' => $data['total'], 'data' => $rows, 'bytes' => size_format( $data['bytes'] ) ] );
    }

    private static function file_name( $id ) { return NELXSDST_Renderer::filename( $id ); }

    private function upload_response( $message, $error = false, $data = [] ) {
        $message = sanitize_text_field( $message );
        if ( wp_doing_ajax() ) {
            $payload = array_merge( [ 'message' => $message ], $data );
            if ( $error ) {
                wp_send_json_error( $payload, 400 );
            }
            wp_send_json_success( $payload );
        }
        $this->redirect( 'files', $message, $error );
    }

    public function upload() {
        $this->require_library();
        if ( ! NELXSDST_Storage_Service::can_upload() ) {
            if ( wp_doing_ajax() ) { wp_send_json_error( [ 'message' => __( 'You cannot upload secure files.', 'nelx-secure-document-storage' ) ], 403 ); }
            wp_die( esc_html__( 'You cannot upload secure files.', 'nelx-secure-document-storage' ), '', [ 'response' => 403 ] );
        }
        if ( wp_doing_ajax() ) { check_ajax_referer( 'nelxsdst_upload', 'nonce' ); } else { check_admin_referer( 'nelxsdst_upload' ); }
        // WordPress validates the upload structure, temporary file, MIME and extension below.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $file = isset( $_FILES['secure_file'] ) ? $_FILES['secure_file'] : [];
        foreach ( [ 'name', 'tmp_name', 'type', 'size', 'error' ] as $key ) {
            if ( ! isset( $file[ $key ] ) || ! is_scalar( $file[ $key ] ) ) { $this->upload_response( __( 'Invalid upload.', 'nelx-secure-document-storage' ), true ); }
        }
        if ( UPLOAD_ERR_OK !== absint( $file['error'] ) ) { $this->upload_response( __( 'The upload could not be completed.', 'nelx-secure-document-storage' ), true ); }
        $name = sanitize_file_name( $file['name'] );
        $mimes = get_allowed_mime_types();
        // Executable/active web content is never accepted, even with unfiltered_upload.
        $ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
        if ( preg_match( '/^(php[0-9]*|phtml|phar|cgi|pl|py|sh|exe|com|bat|cmd|js|html?|svg|shtml|htaccess)$/', $ext ) || absint( $file['size'] ) > wp_max_upload_size() ) {
            $this->upload_response( __( 'The file type or size is not permitted.', 'nelx-secure-document-storage' ), true );
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        $checked = wp_check_filetype_and_ext( $file['tmp_name'], $name, $mimes );
        if ( ! $checked['ext'] || ! $checked['type'] ) { $this->upload_response( __( 'The file type is not permitted.', 'nelx-secure-document-storage' ), true ); }
        $storage = NELXSDST_SecureStorage::get_instance();
        $route = [ $storage, 'pending_upload_dir' ];
        add_filter( 'upload_dir', $route, 999 );
        try {
            $uploaded = wp_handle_upload( $file, [ 'test_form' => false, 'mimes' => $mimes ] );
        } finally {
            remove_filter( 'upload_dir', $route, 999 );
        }
        if ( isset( $uploaded['error'] ) ) { $this->upload_response( $uploaded['error'], true ); }
        $id = wp_insert_attachment( [ 'post_title' => pathinfo( $name, PATHINFO_FILENAME ), 'post_mime_type' => $uploaded['type'], 'post_status' => 'private', 'post_author' => get_current_user_id() ], $uploaded['file'], 0, true );
        if ( is_wp_error( $id ) ) {
            wp_delete_file( $uploaded['file'] );
            $this->upload_response( __( 'Unable to create an attachment record.', 'nelx-secure-document-storage' ), true );
        }
        update_post_meta( $id, '_nelxsdst_source', 'manual' );
        update_post_meta( $id, '_nelxsdst_secure_owner', get_current_user_id() );
        update_post_meta( $id, '_nelxsdst_original_filename', $name );
        $result = NELXSDST_Storage_Service::ingest_attachment( $id, [ 'source' => 'manual', 'owner' => get_current_user_id(), 'original_name' => $name ] );
        if ( is_wp_error( $result ) ) { $this->upload_response( $result->get_error_message(), true, [ 'file_id' => $id ] ); }
        $this->upload_response( __( 'Secure file uploaded.', 'nelx-secure-document-storage' ), false, [ 'file_id' => $id, 'name' => $name, 'size' => size_format( absint( $file['size'] ) ) ] );
    }

    public function delete() {
        $this->require_library();
        $id = $this->posted( 'file_id' );
        $id = is_scalar( $id ) ? absint( $id ) : 0;
        check_admin_referer( 'nelxsdst_delete_' . $id );
        $result = NELXSDST_Storage_Service::delete_attachment( $id );
        $this->redirect( 'files', is_wp_error( $result ) ? $result->get_error_message() : __( 'Secure file deleted.', 'nelx-secure-document-storage' ), is_wp_error( $result ) );
    }

    public function recover() {
        $this->require_admin();
        check_admin_referer( 'nelxsdst_recover' );
        $result = NELXSDST_SecureStorage::get_instance()->recover_pending_files();
        /* translators: %1$d: processed attachments, %2$d: failed attachments. */
        $this->redirect( 'files', sprintf( __( 'Recovery finished. Processed: %1$d. Failed: %2$d.', 'nelx-secure-document-storage' ), $result['processed'], $result['failed'] ), $result['failed'] > 0 );
    }

    private function redirect( $area, $message, $error = false ) {
        set_transient( 'nelxsdst_notice_' . get_current_user_id(), [ 'message' => sanitize_text_field( $message ), 'error' => (bool) $error ], MINUTE_IN_SECONDS );
        $area = in_array( $area, [ 'access', 'integrations', 'donate' ], true ) ? '-' . $area : '';
        wp_safe_redirect( admin_url( 'admin.php?page=nelx-secure-files' . $area ) );
        exit;
    }
}
