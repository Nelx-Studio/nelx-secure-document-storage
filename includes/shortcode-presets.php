<?php
/** Saved shortcode configurations and scoped frontend style variables. */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NELXSDST_Shortcode_Presets {
    const OPTION = 'nelxsdst_shortcode_presets';

    public static function defaults() {
        return [
            'type' => 'files',
            'attachment_id' => 0,
            'field' => '',
            'context' => 'auto',
            'object_id' => 0,
            'repeater' => 'no',
            'layout' => 'cards',
            'per_page' => 20,
            'show_view' => 'yes',
            'show_download' => 'yes',
            'view_text' => __( 'View File', 'nelx-secure-document-storage' ),
            'download_text' => __( 'Download File', 'nelx-secure-document-storage' ),
            'view_bg' => '#007cba',
            'view_color' => '#ffffff',
            'view_border' => '#007cba',
            'view_hover_bg' => '#005a87',
            'view_hover_color' => '#ffffff',
            'view_hover_border' => '#005a87',
            'download_bg' => '#28a745',
            'download_color' => '#ffffff',
            'download_border' => '#28a745',
            'download_hover_bg' => '#1e7e34',
            'download_hover_color' => '#ffffff',
            'download_hover_border' => '#1e7e34',
            'button_border_width' => 1,
            'button_font_size' => 13,
            'button_radius' => 5,
            'button_padding_y' => 8,
            'button_padding_x' => 12,
            'button_gap' => 8,
            'card_bg' => '#ffffff',
            'card_hover_bg' => '#ffffff',
            'card_border' => '#e0e0e0',
            'card_hover_border' => '#007cba',
            'card_border_width' => 1,
            'card_label_color' => '#333333',
            'card_value_color' => '#666666',
            'card_radius' => 8,
            'card_padding' => 20,
            'card_gap' => 20,
            'card_columns' => 3,
            'card_shadow' => 'none',
            'card_hover_shadow' => '0 4px 12px rgba(0,0,0,.10)',
            'table_header_bg' => '#f5f5f5',
            'table_header_color' => '#333333',
            'table_body_color' => '#333333',
            'table_row_bg' => '#ffffff',
            'table_row_hover_bg' => '#f8f9fa',
            'table_border' => '#e0e0e0',
            'table_border_width' => 1,
            'table_header_font_size' => 14,
            'table_body_font_size' => 13,
            'table_cell_padding' => 12,
            'table_radius' => 8,
        ];
    }

    public static function all() {
        $presets = get_option( self::OPTION, [] );
        return is_array( $presets ) ? $presets : [];
    }

    public static function get( $id ) {
        $id = self::sanitize_id( $id );
        $all = self::all();
        return $id && isset( $all[ $id ] ) && is_array( $all[ $id ] ) ? wp_parse_args( $all[ $id ], self::defaults() ) : [];
    }

    public static function sanitize_id( $id ) {
        return preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $id ) );
    }

    private static function color( $value, $fallback ) {
        $color = sanitize_hex_color( is_scalar( $value ) ? (string) $value : '' );
        return $color ?: $fallback;
    }

    private static function number( $value, $fallback, $min, $max ) {
        $value = is_scalar( $value ) && is_numeric( $value ) ? (int) $value : (int) $fallback;
        return min( $max, max( $min, $value ) );
    }

    public static function sanitize( $input ) {
        $input = is_array( $input ) ? $input : [];
        $defaults = self::defaults();
        $out = $defaults;
        $out['type'] = isset( $input['type'] ) && 'file' === $input['type'] ? 'file' : 'files';
        $out['attachment_id'] = isset( $input['attachment_id'] ) ? absint( $input['attachment_id'] ) : 0;
        $out['field'] = isset( $input['field'] ) && is_scalar( $input['field'] ) ? sanitize_text_field( (string) $input['field'] ) : '';
        $out['context'] = isset( $input['context'] ) && in_array( $input['context'], [ 'auto', 'post', 'user', 'current_user' ], true ) ? $input['context'] : 'auto';
        $out['object_id'] = isset( $input['object_id'] ) ? absint( $input['object_id'] ) : 0;
        $out['repeater'] = isset( $input['repeater'] ) && 'yes' === $input['repeater'] ? 'yes' : 'no';
        $out['layout'] = isset( $input['layout'] ) && 'table' === $input['layout'] ? 'table' : 'cards';
        $out['per_page'] = self::number( $input['per_page'] ?? $defaults['per_page'], $defaults['per_page'], 1, 100 );
        $out['show_view'] = isset( $input['show_view'] ) && 'no' === $input['show_view'] ? 'no' : 'yes';
        $out['show_download'] = isset( $input['show_download'] ) && 'no' === $input['show_download'] ? 'no' : 'yes';
        $out['view_text'] = isset( $input['view_text'] ) && is_scalar( $input['view_text'] ) ? sanitize_text_field( (string) $input['view_text'] ) : $defaults['view_text'];
        $out['download_text'] = isset( $input['download_text'] ) && is_scalar( $input['download_text'] ) ? sanitize_text_field( (string) $input['download_text'] ) : $defaults['download_text'];

        foreach ( [
            'view_bg', 'view_color', 'view_border', 'view_hover_bg', 'view_hover_color', 'view_hover_border',
            'download_bg', 'download_color', 'download_border', 'download_hover_bg', 'download_hover_color', 'download_hover_border',
            'card_bg', 'card_hover_bg', 'card_border', 'card_hover_border', 'card_label_color', 'card_value_color',
            'table_header_bg', 'table_header_color', 'table_body_color', 'table_row_bg', 'table_row_hover_bg', 'table_border',
        ] as $key ) {
            $out[ $key ] = self::color( $input[ $key ] ?? $defaults[ $key ], $defaults[ $key ] );
        }

        foreach ( [
            'button_border_width' => [ 0, 12 ], 'button_font_size' => [ 8, 40 ], 'button_radius' => [ 0, 80 ], 'button_padding_y' => [ 0, 40 ], 'button_padding_x' => [ 0, 80 ], 'button_gap' => [ 0, 50 ],
            'card_border_width' => [ 0, 12 ], 'card_radius' => [ 0, 80 ], 'card_padding' => [ 0, 80 ], 'card_gap' => [ 0, 80 ], 'card_columns' => [ 1, 6 ],
            'table_border_width' => [ 0, 12 ], 'table_header_font_size' => [ 8, 40 ], 'table_body_font_size' => [ 8, 40 ], 'table_cell_padding' => [ 0, 50 ], 'table_radius' => [ 0, 80 ],
        ] as $key => $range ) {
            $out[ $key ] = self::number( $input[ $key ] ?? $defaults[ $key ], $defaults[ $key ], $range[0], $range[1] );
        }

        foreach ( [ 'card_shadow', 'card_hover_shadow' ] as $key ) {
            $value = isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) ? sanitize_text_field( (string) $input[ $key ] ) : $defaults[ $key ];
            $out[ $key ] = preg_match( '/^[#(),.%\-\s0-9a-zA-Z]+$/', $value ) ? $value : $defaults[ $key ];
        }
        return $out;
    }

    public static function create( $preset = [] ) {
        $id = 'sc_' . strtolower( wp_generate_password( 8, false, false ) );
        return self::save( $id, wp_parse_args( $preset, self::defaults() ) );
    }

    public static function save( $id, $preset ) {
        $id = self::sanitize_id( $id );
        if ( ! $id ) {
            $id = 'sc_' . strtolower( wp_generate_password( 8, false, false ) );
        }
        $all = self::all();
        $all[ $id ] = self::sanitize( $preset );
        update_option( self::OPTION, $all, false );
        return [ 'id' => $id, 'preset' => $all[ $id ] ];
    }

    public static function delete( $id ) {
        $id = self::sanitize_id( $id );
        $all = self::all();
        if ( ! $id || ! isset( $all[ $id ] ) ) {
            return false;
        }
        unset( $all[ $id ] );
        update_option( self::OPTION, $all, false );
        return true;
    }

    public static function duplicate( $id ) {
        $preset = self::get( $id );
        return $preset ? self::create( $preset ) : false;
    }

    public static function shortcode( $id, $preset = [] ) {
        $id = self::sanitize_id( $id );
        $preset = $preset ? wp_parse_args( $preset, self::defaults() ) : self::get( $id );
        if ( ! $preset ) {
            return '';
        }
        $tag = 'file' === $preset['type'] ? 'nelx_secure_file' : 'nelx_secure_files';
        $attributes = [];
        if ( ! empty( $preset['field'] ) ) {
            $attributes[] = 'field="' . esc_attr( $preset['field'] ) . '"';
            if ( 'auto' !== $preset['context'] ) {
                $attributes[] = 'context="' . esc_attr( $preset['context'] ) . '"';
            }
            if ( ! empty( $preset['object_id'] ) ) {
                $attributes[] = 'object_id="' . absint( $preset['object_id'] ) . '"';
            }
            if ( 'files' === $preset['type'] ) {
                $attributes[] = 'repeater="' . esc_attr( $preset['repeater'] ) . '"';
                if ( 'yes' === $preset['repeater'] ) {
                    $attributes[] = 'layout="' . esc_attr( $preset['layout'] ) . '"';
                }
            }
        } elseif ( 'file' === $preset['type'] && ! empty( $preset['attachment_id'] ) ) {
            $attributes[] = 'id="' . absint( $preset['attachment_id'] ) . '"';
        } elseif ( 'files' === $preset['type'] && 20 !== absint( $preset['per_page'] ) ) {
            $attributes[] = 'per_page="' . absint( $preset['per_page'] ) . '"';
        }
        $attributes[] = 'preset="' . esc_attr( $id ) . '"';
        return '[' . $tag . ( $attributes ? ' ' . implode( ' ', $attributes ) : '' ) . ']';
    }

    public static function style_attr( $preset ) {
        $preset = wp_parse_args( $preset, self::defaults() );
        $vars = [
            '--nelxsdst-view-bg-color' => $preset['view_bg'],
            '--nelxsdst-view-text-color' => $preset['view_color'],
            '--nelxsdst-view-border-color' => $preset['view_border'],
            '--nelxsdst-view-hover-bg-color' => $preset['view_hover_bg'],
            '--nelxsdst-view-hover-text-color' => $preset['view_hover_color'],
            '--nelxsdst-view-hover-border-color' => $preset['view_hover_border'],
            '--nelxsdst-download-bg-color' => $preset['download_bg'],
            '--nelxsdst-download-text-color' => $preset['download_color'],
            '--nelxsdst-download-border-color' => $preset['download_border'],
            '--nelxsdst-download-hover-bg-color' => $preset['download_hover_bg'],
            '--nelxsdst-download-hover-text-color' => $preset['download_hover_color'],
            '--nelxsdst-download-hover-border-color' => $preset['download_hover_border'],
            '--nelxsdst-view-border-width' => $preset['button_border_width'] . 'px',
            '--nelxsdst-download-border-width' => $preset['button_border_width'] . 'px',
            '--nelxsdst-button-font-size' => $preset['button_font_size'] . 'px',
            '--nelxsdst-view-border-radius' => $preset['button_radius'] . 'px',
            '--nelxsdst-download-border-radius' => $preset['button_radius'] . 'px',
            '--nelxsdst-view-padding' => $preset['button_padding_y'] . 'px ' . $preset['button_padding_x'] . 'px',
            '--nelxsdst-download-padding' => $preset['button_padding_y'] . 'px ' . $preset['button_padding_x'] . 'px',
            '--nelxsdst-button-gap' => $preset['button_gap'] . 'px',
            '--nelxsdst-cards-background' => $preset['card_bg'],
            '--nelxsdst-cards-hover-background' => $preset['card_hover_bg'],
            '--nelxsdst-cards-border-color' => $preset['card_border'],
            '--nelxsdst-cards-hover-border-color' => $preset['card_hover_border'],
            '--nelxsdst-cards-border-width' => $preset['card_border_width'] . 'px',
            '--nelxsdst-cards-label-color' => $preset['card_label_color'],
            '--nelxsdst-cards-value-color' => $preset['card_value_color'],
            '--nelxsdst-cards-border-radius' => $preset['card_radius'] . 'px',
            '--nelxsdst-cards-padding' => $preset['card_padding'] . 'px',
            '--nelxsdst-gap' => $preset['card_gap'] . 'px',
            '--nelxsdst-cols' => (string) $preset['card_columns'],
            '--nelxsdst-cards-shadow' => $preset['card_shadow'],
            '--nelxsdst-cards-hover-shadow' => $preset['card_hover_shadow'],
            '--nelxsdst-table-header-bg' => $preset['table_header_bg'],
            '--nelxsdst-table-header-color' => $preset['table_header_color'],
            '--nelxsdst-table-body-color' => $preset['table_body_color'],
            '--nelxsdst-table-row-bg' => $preset['table_row_bg'],
            '--nelxsdst-table-row-hover-bg' => $preset['table_row_hover_bg'],
            '--nelxsdst-table-border-color' => $preset['table_border'],
            '--nelxsdst-table-border-width' => $preset['table_border_width'] . 'px',
            '--nelxsdst-table-header-font-size' => $preset['table_header_font_size'] . 'px',
            '--nelxsdst-table-body-font-size' => $preset['table_body_font_size'] . 'px',
            '--nelxsdst-table-cell-padding' => $preset['table_cell_padding'] . 'px',
            '--nelxsdst-table-radius' => $preset['table_radius'] . 'px',
        ];
        $parts = [];
        foreach ( $vars as $name => $value ) {
            $parts[] = $name . ':' . $value;
        }
        // Every value above is strictly sanitized; CSS custom properties are scoped to the preset wrapper.
        return implode( ';', $parts );
    }
}
