<?php
/** Builder-independent secure file rendering and authorized listings. */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NELXSDST_Renderer {
    private static function private_page() {
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Standard cache-plugin interoperability constant.
        if (!defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE', true); }
        if (!headers_sent()) { nocache_headers(); }
    }

    public static function assets() {
        if (is_admin()) { return ""; }
        wp_enqueue_style( 'nelxsdst-docs', NELXSDST_PLUGIN_URL . 'assets/css/ns-docs.min.css', [], NELXSDST_PLUGIN_VERSION );
        wp_enqueue_style( 'nelxsdst-library', NELXSDST_PLUGIN_URL . 'assets/css/library.css', [], NELXSDST_PLUGIN_VERSION );
        // Builders may invoke the renderer after wp_head. Print the registered styles once.
        if ( did_action( 'wp_head' ) && ! is_admin() ) {
            ob_start();
            wp_print_styles( [ 'nelxsdst-docs', 'nelxsdst-library' ] );
            return ob_get_clean();
        }
        return '';
    }

    public static function buttons( $id, $args = [] ) {
        if ( ! NELXSDST_SecureStorage::is_file_secured( $id ) ) {
            return '';
        }
        $args = wp_parse_args( $args, [
            'show_view' => true, 'show_download' => true,
            'view_text' => __( 'View File', 'nelx-secure-document-storage' ),
            'download_text' => __( 'Download File', 'nelx-secure-document-storage' ),
            'view_button_class' => 'nelxsdst-secure-view-btn',
            'download_button_class' => 'nelxsdst-secure-download-btn',
            'view_style' => '', 'download_style' => '',
        ] );
        if ( 'yes' === get_option( 'nelxsdst_hide_buttons_unauthorized', 'no' ) && ! NELXSDST_File_Access::user_can_access_file( $id ) ) {
            return '—';
        }
        $html = self::assets() . '<div class="nelxsdst-file-links"><div class="nelxsdst-file-buttons">';
        foreach ( [ 'view', 'download' ] as $action ) {
            if ( ! $args[ 'show_' . $action ] ) {
                continue;
            }
            $html .= sprintf( '<a href="%s" class="%s" style="%s"%s>%s</a>',
                esc_url( NELXSDST_File_Access::get_secure_file_url( $id, $action ) ),
                esc_attr( $args[ $action . '_button_class' ] ),
                esc_attr( safecss_filter_attr( $args[ $action . '_style' ] ) ),
                'view' === $action ? ' target="_blank" rel="noopener noreferrer"' : '',
                esc_html( $args[ $action . '_text' ] )
            );
        }
        return $html . '</div></div>';
    }

    public static function source( $id ) {
        $source = get_post_meta( $id, '_nelxsdst_source', true );
        if ( in_array( $source, [ 'manual', 'jetformbuilder', 'api', 'legacy' ], true ) ) {
            return $source;
        }
        return get_post_meta( $id, '_nelxsdst_form_id', true ) ? 'jetformbuilder' : 'legacy';
    }

    public static function filename( $id ) {
        return NELXSDST_SecureStorage::get_instance()->get_original_filename( $id );
    }

    public static function size( $id ) {
        $path = get_attached_file( $id );
        return $path && is_file( $path ) && NELXSDST_SecureStorage::is_protected_path( $path ) ? filesize( $path ) : 0;
    }

    /** Scan in bounded batches; use the single access predicate, including legacy form rules. */
    public static function listing( $page = 1, $per_page = 20, $search = '', $source = '', $backend = false, $all = false ) {
        $page = max( 1, absint( $page ) );
        $per_page = min( 100, max( 1, absint( $per_page ) ) );
        $result = [ 'ids' => [], 'total' => 0, 'pages' => 0, 'bytes' => 0 ];
        if ( ! is_user_logged_in() ) {
            return $result;
        }
        $offset = 0;
        do {
            $ids = get_posts( [
                'post_type' => 'attachment', 'post_status' => 'any', 'posts_per_page' => 100,
                'offset' => $offset, 'orderby' => 'ID', 'order' => 'DESC', 'fields' => 'ids',
                // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- The secure-file flag is the canonical index used to avoid scanning every media attachment.
                'meta_query' => [ [ 'key' => '_nelxsdst_secure_file', 'value' => '1' ] ],
                'update_post_meta_cache' => true,
            ] );
            // get_posts(fields=ids) does not automatically prime metadata.
            update_meta_cache( 'post', $ids );
            foreach ( $ids as $id ) {
                if ( ! NELXSDST_File_Access::user_can_access_file( $id ) || ( $backend && ! current_user_can( 'manage_options' ) && ! current_user_can( 'nelxsdst_access_all_secure_files' ) && absint( get_post_meta( $id, '_nelxsdst_secure_owner', true ) ) !== get_current_user_id() ) ) {
                    continue;
                }
                if ( $source && self::source( $id ) !== $source ) {
                    continue;
                }
                if ( $search && false === stripos( str_replace( [ '-', '_' ], ' ', self::filename( $id ) . ' ' . get_the_title( $id ) ), str_replace( [ '-', '_' ], ' ', $search ) ) ) {
                    continue;
                }
                if ( $all || ( $result['total'] >= ( $page - 1 ) * $per_page && count( $result['ids'] ) < $per_page ) ) {
                    $result['ids'][] = $id;
                }
                ++$result['total'];
                if ( current_user_can( 'manage_options' ) ) {
                    $result['bytes'] += self::size( $id );
                }
            }
            $offset += 100;
        } while ( count( $ids ) === 100 );
        $result['pages'] = (int) ceil( $result['total'] / $per_page );
        return $result;
    }

    /** Resolve explicit context, JetEngine listing object, WP loop or queried user. */
    public static function context_value( $atts ) {
        $field = sanitize_text_field( $atts['field'] );
        if ( ! $field || ! is_user_logged_in() ) { return [ '', '' ]; }
        $context = sanitize_key( $atts['context'] );
        $id = 'current_user' === $context ? 0 : absint( $atts['object_id'] );
        $object = null;
        if ( ! $id && 'current_user' === $context ) {
            $object = wp_get_current_user();
        } elseif ( ! $id && function_exists( 'jet_engine' ) && in_array( $context, [ 'auto', 'post', 'user' ], true ) ) {
            $engine = jet_engine();
            if ( isset( $engine->listings->data ) && is_callable( [ $engine->listings->data, 'get_current_object' ] ) ) {
                $candidate = $engine->listings->data->get_current_object();
                if ( 'auto' === $context || ( 'post' === $context && $candidate instanceof WP_Post ) || ( 'user' === $context && $candidate instanceof WP_User ) ) { $object = $candidate; }
            }
        }
        if ( $id ) {
            $object = in_array( $context, [ 'user', 'current_user' ], true ) ? get_userdata( $id ) : get_post( $id );
        }
        if ( $id && ! $object ) { return [ '', '' ]; }
        if ( ! $object ) {
            $queried = get_queried_object();
            if ( 'current_user' === $context ) { $object = wp_get_current_user(); }
            elseif ( 'user' === $context ) { $object = $queried instanceof WP_User ? $queried : wp_get_current_user(); }
            elseif ( 'auto' === $context && $queried instanceof WP_User && ! in_the_loop() ) { $object = $queried; }
            else { $object = get_post(); }
        }
        if ( $object instanceof WP_User ) { return [ get_user_meta( $object->ID, $field, true ), 'user' ]; }
        if ( $object instanceof WP_Post ) { return [ get_post_meta( $object->ID, $field, true ), $object->post_type ]; }
        // JetEngine CCT listing objects store fields on the current object.
        if ( is_object( $object ) && isset( $object->$field ) ) { return [ $object->$field, 'cct' ]; }
        if ( is_array( $object ) && isset( $object[ $field ] ) ) { return [ $object[ $field ], 'cct' ]; }
        return [ '', '' ];
    }

    private static function resolve_shortcode_atts( $raw, $tag ) {
        $raw = is_array( $raw ) ? $raw : [];
        $preset_id = isset( $raw['preset'] ) ? NELXSDST_Shortcode_Presets::sanitize_id( $raw['preset'] ) : '';
        $preset = $preset_id ? NELXSDST_Shortcode_Presets::get( $preset_id ) : [];
        $mapped = [];
        if ( $preset ) {
            $mapped = [
                'id' => $preset['attachment_id'], 'field' => $preset['field'], 'context' => $preset['context'],
                'object_id' => $preset['object_id'], 'repeater' => $preset['repeater'], 'layout' => $preset['layout'],
                'show_view' => $preset['show_view'], 'show_download' => $preset['show_download'],
                'view_text' => $preset['view_text'], 'download_text' => $preset['download_text'], 'per_page' => $preset['per_page'],
                'preset' => $preset_id,
            ];
        }
        return shortcode_atts( self::shortcode_defaults(), array_merge( $mapped, $raw ), $tag );
    }

    private static function preset_wrap( $html, $atts ) {
        $preset_id = ! empty( $atts['preset'] ) ? NELXSDST_Shortcode_Presets::sanitize_id( $atts['preset'] ) : '';
        $preset = $preset_id ? NELXSDST_Shortcode_Presets::get( $preset_id ) : [];
        if ( ! $preset ) {
            return $html;
        }
        return '<div class="nelxsdst-shortcode-preset nelxsdst-shortcode-preset-' . esc_attr( $preset_id ) . '" style="' . esc_attr( NELXSDST_Shortcode_Presets::style_attr( $preset ) ) . '">' . $html . '</div>';
    }

    public static function field_shortcode( $atts ) {
        list( $value, $type ) = self::context_value( $atts );
        if ( is_string( $value ) ) {
            $decoded = json_decode( $value, true );
            if ( is_array( $decoded ) ) { $value = $decoded; }
        }
        if ( ! $value ) { return ''; }
        $repeater = 'yes' === $atts['repeater'];
        if ( 'auto' === $atts['repeater'] && is_array( $value ) ) {
            $first = reset( $value );
            $repeater = is_array( $first ) && ! isset( $first['id'] ) && ! isset( $first['ID'] ) && ! isset( $first['attachment_id'] );
        }
        if ( $repeater && is_array( $value ) ) {
            $rows = array_filter( $value, 'is_array' );
            if ( ! $rows ) { return ''; }
            $html = self::assets() . nelxsdst_display_repeater_field( $rows, 'cards' === $atts['layout'] ? 'cards' : 'table',
                $atts['show_view'], $atts['view_text'], $atts['show_download'], $atts['download_text'],
                'nelxsdst-secure-view-btn', 'nelxsdst-secure-download-btn', $type, sanitize_text_field( $atts['field'] ), true );
            return self::preset_wrap( $html, $atts );
        }
        $html = '';
        foreach ( nelxsdst_normalize_attachment_ids( $value ) as $id ) {
            if ( NELXSDST_File_Access::user_can_access_file( $id ) ) {
                $html .= '<div class="nelxsdst-single-file"><span>' . esc_html( self::filename( $id ) ) . '</span>' . self::buttons( $id, [
                    'show_view' => 'yes' === $atts['show_view'], 'show_download' => 'yes' === $atts['show_download'],
                    'view_text' => $atts['view_text'] ?: __( 'View File', 'nelx-secure-document-storage' ),
                    'download_text' => $atts['download_text'] ?: __( 'Download File', 'nelx-secure-document-storage' ),
                ] ) . '</div>';
            }
        }
        return self::preset_wrap( $html, $atts );
    }

    private static function shortcode_defaults() {
        return [ 'id' => 0, 'field' => '', 'context' => 'auto', 'object_id' => 0, 'repeater' => 'auto', 'layout' => 'table',
            'show_view' => 'yes', 'show_download' => 'yes', 'view_text' => '', 'download_text' => '', 'per_page' => 20, 'preset' => '' ];
    }

    public static function single_shortcode( $atts ) {
        self::private_page();
        $atts = self::resolve_shortcode_atts( $atts, 'nelx_secure_file' );
        if ( $atts['field'] ) { return self::field_shortcode( $atts ); }
        $id = absint( $atts['id'] );
        if ( ! NELXSDST_File_Access::user_can_access_file( $id ) ) { return ''; }
        $html = '<div class="nelxsdst-single-file"><span>' . esc_html( self::filename( $id ) ) . '</span>' . self::buttons( $id, [
            'show_view' => 'yes' === $atts['show_view'], 'show_download' => 'yes' === $atts['show_download'],
            'view_text' => $atts['view_text'] ?: __( 'View File', 'nelx-secure-document-storage' ),
            'download_text' => $atts['download_text'] ?: __( 'Download File', 'nelx-secure-document-storage' ),
        ] ) . '</div>';
        return self::preset_wrap( $html, $atts );
    }

    public static function table_shortcode( $atts ) {
        self::private_page();
        if ( ! is_user_logged_in() ) {
            return '<p>' . esc_html__( 'Please log in to view your secure files.', 'nelx-secure-document-storage' ) . '</p>';
        }
        $atts = self::resolve_shortcode_atts( $atts, 'nelx_secure_files' );
        if ( $atts['field'] ) { return self::field_shortcode( $atts ); }
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only authorized listing.
        $page = isset( $_GET['nsf_page'] ) && is_scalar( $_GET['nsf_page'] ) ? absint( $_GET['nsf_page'] ) : 1;
        $search = isset( $_GET['nsf_search'] ) && is_string( $_GET['nsf_search'] ) ? sanitize_text_field( wp_unslash( $_GET['nsf_search'] ) ) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        $data = self::listing( $page, $atts['per_page'], $search );
        $base = remove_query_arg( [ 'nsf_page', 'nsf_search' ] );
        ob_start();
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Registered styles.
        echo self::assets();
        ?>
        <div class="nelxsdst-library">
            <form method="get" action="<?php echo esc_url( $base ); ?>" class="nelxsdst-search">
                <?php self::query_fields( [ 'nsf_page', 'nsf_search' ] ); ?>
                <label><?php esc_html_e( 'Search files', 'nelx-secure-document-storage' ); ?> <input type="search" name="nsf_search" value="<?php echo esc_attr( $search ); ?>"></label>
                <button type="submit"><?php esc_html_e( 'Search', 'nelx-secure-document-storage' ); ?></button>
            </form>
            <div class="nelxsdst-table-scroll"><table class="nelxsdst-native-table">
                <thead><tr><?php foreach ( [ __( 'File', 'nelx-secure-document-storage' ), __( 'Type', 'nelx-secure-document-storage' ), __( 'Date', 'nelx-secure-document-storage' ), __( 'Size', 'nelx-secure-document-storage' ), __( 'Actions', 'nelx-secure-document-storage' ) ] as $label ) { echo '<th scope="col">' . esc_html( $label ) . '</th>'; } ?></tr></thead>
                <tbody><?php foreach ( $data['ids'] as $id ) : ?>
                    <tr><td><?php echo esc_html( self::filename( $id ) ); ?></td><td><?php echo esc_html( get_post_mime_type( $id ) ); ?></td><td><?php echo esc_html( get_the_date( '', $id ) ); ?></td><td><?php echo esc_html( size_format( self::size( $id ) ) ); ?></td><td><?php echo wp_kses_post( self::buttons( $id, [ 'show_view' => 'yes' === $atts['show_view'], 'show_download' => 'yes' === $atts['show_download'], 'view_text' => $atts['view_text'] ?: __( 'View File', 'nelx-secure-document-storage' ), 'download_text' => $atts['download_text'] ?: __( 'Download File', 'nelx-secure-document-storage' ) ] ) ); ?></td></tr>
                <?php endforeach; ?>
                <?php if ( ! $data['ids'] ) : ?><tr><td colspan="5"><?php esc_html_e( 'No accessible secure files found.', 'nelx-secure-document-storage' ); ?></td></tr><?php endif; ?>
                </tbody></table></div>
            <?php self::pagination( $page, $data['pages'], add_query_arg( 'nsf_search', $search, $base ), 'nsf_page' ); ?>
        </div>
        <?php
        return self::preset_wrap( ob_get_clean(), $atts );
    }

    public static function pagination( $page, $pages, $base, $key ) {
        if ( $pages > 1 ) {
            echo '<nav class="nelxsdst-pagination" aria-label="' . esc_attr__( 'File pages', 'nelx-secure-document-storage' ) . '">';
            echo wp_kses_post( paginate_links( [ 'base' => add_query_arg( $key, '%#%', $base ), 'format' => '', 'current' => max( 1, $page ), 'total' => $pages ] ) );
            echo '</nav>';
        }
    }

    public static function query_fields( $exclude ) {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Preserve scalar routing parameters in read-only search.
        foreach ( $_GET as $key => $value ) {
            if ( is_scalar( $value ) && ! in_array( $key, $exclude, true ) ) {
                echo '<input type="hidden" name="' . esc_attr( sanitize_key( $key ) ) . '" value="' . esc_attr( sanitize_text_field( wp_unslash( (string) $value ) ) ) . '">';
            }
        }
    }
}

add_shortcode( 'nelx_secure_file', [ 'NELXSDST_Renderer', 'single_shortcode' ] );
add_shortcode( 'nelx_secure_files', [ 'NELXSDST_Renderer', 'table_shortcode' ] );

// Preserve modal behaviour, loading only when a form is rendered or its script is present.
function nelxsdst_enqueue_form_feedback() {
    if ( 'yes' !== get_option( 'nelxsdst_enable_form_modal', 'no' ) ) {
        return;
    }
    if ( NELXSDST_Settings::feedback_conflict() ) {
        return;
    }
    wp_enqueue_style( 'nelxsdst-feedback', NELXSDST_PLUGIN_URL . 'assets/css/ns-feedback.min.css', [], NELXSDST_PLUGIN_VERSION );
    wp_enqueue_script( 'nelxsdst-feedback', NELXSDST_PLUGIN_URL . 'assets/js/ns-feedback.min.js', [ 'jquery' ], NELXSDST_PLUGIN_VERSION, true );
    wp_localize_script( 'nelxsdst-feedback', 'nelxsdst_feedback', [ 'modal_enabled' => true, 'ajax_url' => admin_url( 'admin-ajax.php' ) ] );
}
add_filter( 'render_block', function ( $html, $block ) {
    if ( 0 === strpos( $block['blockName'] ?? '', 'jet-forms/' ) || false !== strpos( $html, 'class="jet-form-builder' ) ) {
        nelxsdst_enqueue_form_feedback();
    }
    return $html;
}, 10, 2 );
add_filter( 'do_shortcode_tag', function ( $html, $tag ) {
    if ( 'jet_fb_form' === $tag ) {
        nelxsdst_enqueue_form_feedback();
    }
    return $html;
}, 10, 2 );
add_action( 'wp_footer', function () {
    foreach ( wp_scripts()->queue as $handle ) {
        if ( preg_match( '/jet[-_](?:form|fb)/', $handle ) ) {
            nelxsdst_enqueue_form_feedback();
            break;
        }
    }
    wp_print_styles( [ 'nelxsdst-docs', 'nelxsdst-library', 'nelxsdst-feedback' ] );
}, 5 );

// Secure attachments should never advertise a raw URL through WordPress media APIs.
add_filter( 'wp_get_attachment_url', function ( $url, $id ) {
    if ( NELXSDST_SecureStorage::is_file_secured( $id ) ) {
        return NELXSDST_File_Access::user_can_access_file( $id ) ? NELXSDST_File_Access::get_secure_file_url( $id ) : '';
    }
    return $url;
}, 20, 2 );
add_filter( 'ajax_query_attachments_args', function ( $args ) {
    $args['meta_query'][] = [ 'key' => '_nelxsdst_secure_file', 'compare' => 'NOT EXISTS' ];
    return $args;
} );
add_filter( 'rest_attachment_query', function ( $args ) {
    $args['meta_query'][] = [ 'key' => '_nelxsdst_secure_file', 'compare' => 'NOT EXISTS' ];
    return $args;
} );
add_filter( 'rest_prepare_attachment', function ( $response, $post ) {
    if ( NELXSDST_SecureStorage::is_file_secured( $post->ID ) && ! NELXSDST_File_Access::user_can_access_file( $post->ID ) ) {
        return new WP_Error( 'rest_forbidden', __( 'You cannot access this secure file.', 'nelx-secure-document-storage' ), [ 'status' => 403 ] );
    }
    return $response;
}, 10, 2 );

/** Compatibility wrapper for existing JetEngine and PHP consumers. */
function nelxsdst_generate_secure_file_buttons($attachment_id, $show_view, $view_text, $show_download, $download_text, $view_button_class, $download_button_class,
    $view_button_styles = '', $download_button_styles = '') {
    return NELXSDST_Renderer::buttons($attachment_id, [
        'show_view' => 'yes' === $show_view, 'show_download' => 'yes' === $show_download,
        'view_text' => $view_text ?: __('View File', 'nelx-secure-document-storage'),
        'download_text' => $download_text ?: __('Download File', 'nelx-secure-document-storage'),
        'view_button_class' => $view_button_class ?: 'nelxsdst-secure-view-btn',
        'download_button_class' => $download_button_class ?: 'nelxsdst-secure-download-btn',
        'view_style' => $view_button_styles, 'download_style' => $download_button_styles,
    ]);
}


add_filter( 'map_meta_cap', function ( $caps, $cap, $user_id, $args ) {
    if ( in_array( $cap, [ 'read_post', 'edit_post', 'delete_post' ], true ) && ! empty( $args[0] ) && NELXSDST_SecureStorage::is_file_secured( $args[0] ) ) {
        if ( ! NELXSDST_File_Access::user_can_access_file( $args[0], $user_id ) ) {
            return [ 'do_not_allow' ];
        }
    }
    return $caps;
}, 10, 4 );
