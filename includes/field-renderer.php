<?php
/** Shared media/repeater rendering, independent of JetEngine and Elementor. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function nelxsdst_is_json_attachment($string) {
    if (!is_string($string) || empty($string)) {
        return false;
    }
    
    // Multiple detection methods
    if (strpos($string, '{"url":') === 0 || strpos($string, '{\"url\":') === 0) {
        return true;
    }
    
    if (strpos($string, '"id":') !== false && strpos($string, 'url') !== false) {
        return true;
    }
    
    // Even handle malformed cases if any
    if (strpos($string, '"url":false') !== false && strpos($string, '"id":') !== false) {
        return true;
    }
    
    return false;
}

/**
 * Robust JSON parsing for callback with malformed data handling
 */
function nelxsdst_parse_json_attachment($json_string) {
    // Multiple parsing attempts
    $decoded = json_decode($json_string, true);
    if (is_array($decoded) && isset($decoded['id'])) {
        return [
            'url' => $decoded['url'] ?? '',
            'id' => intval($decoded['id'])
        ];
    }
    
    $clean_json = stripslashes($json_string);
    $decoded = json_decode($clean_json, true);
    if (is_array($decoded) && isset($decoded['id'])) {
        return [
            'url' => $decoded['url'] ?? '',
            'id' => intval($decoded['id'])
        ];
    }
    
    // Handle malformed nested case
    $decoded = json_decode($json_string, true);
    if (is_array($decoded) && isset($decoded['id']) && is_string($decoded['id']) && strpos($decoded['id'], '{') === 0) {
        $inner_decoded = json_decode($decoded['id'], true);
        if (isset($inner_decoded['url'])) {
            $view_url = $inner_decoded['url'];
            $parsed_url = wp_parse_url($view_url); 
            parse_str($parsed_url['query'] ?? '', $query);
            $attachment_id = intval($query['file_id'] ?? 0);
            
            if ($attachment_id) {
                return [
                    'url' => $view_url,
                    'id' => $attachment_id
                ];
            }
        }
    }
    
    // Pattern 1 
    if (strpos($json_string, '"url":false') !== false && strpos($json_string, '"id":') !== false) {
        // Extract the file_id from the nested URL
        if (preg_match('/file_id=(\d+)/', $json_string, $matches)) {
            return [
                'url' => '',
                'id' => intval($matches[1])
            ];
        }
    }
    
    // NEW: Pattern 2
    if (strpos($json_string, '"id":"{') !== false && strpos($json_string, 'file_id=') !== false) {
        // Extract the inner JSON content from the id field
        if (preg_match('/"id"\s*:\s*"(\{.*?\})"/', $json_string, $matches)) {
            $inner_json = $matches[1];
            // Now extract file_id from the inner JSON
            if (preg_match('/file_id=(\d+)/', $inner_json, $file_matches)) {
                return [
                    'url' => '',
                    'id' => intval($file_matches[1])
                ];
            }
        }
        
        // Alternative extraction method if the above fails
        if (preg_match('/file_id=(\d+)/', $json_string, $matches)) {
            return [
                'url' => '',
                'id' => intval($matches[1])
            ];
        }
    }
    
    // NEW: Pattern 3: Double-nested malformed JSON
    // Example: "id":"{\"url\":\"https://...file_id=123...\"}"
    if (preg_match('/"id"\s*:\s*"\\\\"([^"]+)\\\\""/', $json_string, $matches)) {
        $escaped_content = $matches[1];
        if (preg_match('/file_id=(\d+)/', $escaped_content, $file_matches)) {
            return [
                'url' => '',
                'id' => intval($file_matches[1])
            ];
        }
    }
    
    if (preg_match('/file_id=(\d+)/', $json_string, $matches)) {
        return [
            'url' => '',
            'id' => intval($matches[1])
        ];
    }
    
    // NEW: Pattern 4: Simple numeric ID extraction as fallback
    if (preg_match('/"id"\s*:\s*"(\d+)"/', $json_string, $matches)) {
        return [
            'url' => '',
            'id' => intval($matches[1])
        ];
    }
    
    // Final fallback: Direct numeric extraction
    if (preg_match('/"id"\s*:\s*(\d+)/', $json_string, $matches)) {
        return [
            'url' => '',
            'id' => intval($matches[1])
        ];
    }
    
    return false;
}

/**
 * Enhanced: Get the *real* label from JetEngine meta-box configuration.
 * Scans all meta boxes → meta_fields → repeaters → subfields.
 * If $repeater_name empty, searches ALL repeaters (assumes consistent labels).
 * Filters by object_type if context 'user'/'post_type' etc.
 * Falls back to intelligent formatter.
 */
function nelxsdst_get_field_label($subfield_key, $repeater_name = '', $context_type = '', $context_id = '') {
    // Early return ONLY if subfield_key empty
    if (empty($subfield_key)) {
        return nelxsdst_format_field_label_intelligent($subfield_key);
    }
  
    if (!function_exists('jet_engine')) {
        return nelxsdst_format_field_label_intelligent($subfield_key);
    }
  
    // Determine filter object_type from context
    $filter_object_type = '';
    if ($context_type === 'user') {
        $filter_object_type = 'user';
    } elseif ($context_type === 'post_type' || $context_type === 'post' || $context_type === 'cct') {
        $filter_object_type = 'post';
    } elseif ($context_type === 'taxonomy') {
        $filter_object_type = 'term';
    }
  
    // Raw fetch and unserialize options (matches your DB dump)
    $all_meta_boxes_serial = get_option('jet_engine_meta_boxes', []);
    if (!is_array($all_meta_boxes_serial)) {
        $all_meta_boxes_serial = maybe_unserialize($all_meta_boxes_serial);
    }
    if (!is_array($all_meta_boxes_serial)) {
        return nelxsdst_format_field_label_intelligent($subfield_key);
    }
  
    // Search: meta_boxes → meta_fields → repeaters → subfields
    foreach ($all_meta_boxes_serial as $mb_id => $meta_box) {
        // Filter by object_type if specified
        if (!empty($filter_object_type) && isset($meta_box['args']['object_type']) && $meta_box['args']['object_type'] !== $filter_object_type) {
            continue;
        }
      
        if (isset($meta_box['meta_fields']) && is_array($meta_box['meta_fields'])) {
            foreach ($meta_box['meta_fields'] as $field_index => $field) {
                $is_target_repeater = empty($repeater_name) || (isset($field['name']) && $field['name'] === $repeater_name);
                if ($is_target_repeater && isset($field['type']) && $field['type'] === 'repeater') {
                    if (isset($field['repeater-fields']) && is_array($field['repeater-fields'])) {
                        foreach ($field['repeater-fields'] as $sub_index => $sub) {
                            if (isset($sub['name']) && $sub['name'] === $subfield_key) {
                                $label = $sub['title'] ?? null;
                                return $label ?: nelxsdst_format_field_label_intelligent($subfield_key);
                            }
                        }
                    }
                }
            }
        }
    }
  
    // No match found
    return nelxsdst_format_field_label_intelligent($subfield_key);
}

/**
 * Intelligent field label formatting without hardcoding.
 * Strips prefixes, skips single letters, maps common terms for readability.
 */
function nelxsdst_format_field_label_intelligent($field_key) {
    // Remove common prefixes (including single-letter ones like 'p_', 's_')
    $clean_key = preg_replace('/^(crk_s_|nelxsdst_|jet_|meta_|emrg_|em_|kin_|p_)/', '', $field_key);
  
    // Handle repeater sub-fields (e.g., strip [subkey])
    if (strpos($clean_key, '[') !== false && strpos($clean_key, ']') !== false) {
        preg_match('/\[([^\]]+)\]/', $clean_key, $matches);
        if (isset($matches[1])) {
            $clean_key = $matches[1];
        }
    }
  
    // Convert snake_case/camelCase to readable spaces
    $clean_key = preg_replace('/([a-z])([A-Z])/', '$1 $2', $clean_key);
    $clean_key = str_replace(['_', '-'], ' ', $clean_key);
  
    // Common word mappings (empty for junk like 'p', 's')
    $word_mappings = [
        'p' => '',
        'id' => 'ID',
        'url' => 'URL',
        'pdf' => 'PDF',
        'jpg' => 'JPG',
        'png' => 'PNG',
        'emrg' => 'Emergency',
        'kin' => '',
        'crk' => '',
        's' => '',
        'name' => 'Name',
        'relationship' => 'Relationship',
        'phone' => 'Phone',
        'email' => 'Email',
        'address' => 'Address',
        'title' => 'Title',
        'description' => 'Description',
        'date' => 'Date',
        'time' => 'Time',
        'number' => 'Number',
        'file' => 'File',
        'image' => 'Image',
        'upload' => 'Upload',
        'attachment' => 'Attachment',
        'certificate' => 'Certificate',
        'license' => 'License',
        'membership' => 'Membership',
        'qualification' => 'Qualification',
        'institution' => 'Institution',
        'awarding' => 'Awarding',
        'body' => 'Body',
        'authority' => 'Authority',
        'expertise' => 'Expertise',
        'experience' => 'Experience',
        'level' => 'Level',
        'duration' => 'Duration',
        'brief' => 'Brief',
    ];
  
    $words = explode(' ', $clean_key);
    $formatted_words = [];
  
    foreach ($words as $word) {
        $lower_word = strtolower(trim($word));
        if (isset($word_mappings[$lower_word]) && !empty($word_mappings[$lower_word])) {
            $formatted_words[] = $word_mappings[$lower_word];
        } elseif (!empty($word) && strlen($lower_word) > 1) {  // Skip single letters/junk
            $formatted_words[] = ucfirst($lower_word);
        }
    }
  
    $formatted = implode(' ', $formatted_words);
  
    // Handle specific multi-word patterns
    $patterns = [
        '/\bLicense Membership\b/i' => 'License / Membership',
        '/\bInstitution Awarding Body\b/i' => 'Institution / Awarding Body',
        '/\bCertification License\b/i' => 'Certification / License',
        '/\bExperience Level Duration\b/i' => 'Experience Level / Duration',
        '/\bAwarding Body\b/i' => 'Awarding Body',
        '/\bIssuing Authority\b/i' => 'Issuing Authority',
        '/\bArea Expertise\b/i' => 'Area of Expertise',
    ];
  
    foreach ($patterns as $pattern => $replacement) {
        $formatted = preg_replace($pattern, $replacement, $formatted);
    }
  
    // Clean up
    $formatted = preg_replace('/\s+\/\s+/', ' / ', $formatted);
    $formatted = preg_replace('/\s+/', ' ', $formatted);
    $formatted = trim($formatted);
  
    return $formatted ?: ucfirst(str_replace('_', ' ', $field_key));  // Ultra-fallback
}

/**
 * Fix date formats in repeater items
 */
function nelxsdst_fix_date_formats($repeater_items) {
    foreach ($repeater_items as &$item) {
        foreach ($item as $key => &$value) {
            // Check if this looks like a timestamp or date field
            if (is_numeric($value) && strlen($value) === 10) {
                // It's a timestamp, convert to readable date
                $value = gmdate('F j, Y', $value);
            } elseif (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
                // It's a MySQL date format, convert to readable date
                $value = gmdate('F j, Y', strtotime($value));
            }
        }
    }
    return $repeater_items;
}

/**
 * Display repeater field with all items
 */
function nelxsdst_display_repeater_field(
    $repeater_items, $layout,
    $show_view, $view_text, $show_download, $download_text,
    $view_button_class, $download_button_class,
    $post_type = '', $repeater_name = '',
    $hide_unauthorized = false
) {
    if (empty($repeater_items)) return '';
    $repeater_items = nelxsdst_fix_date_formats($repeater_items);

    switch ($layout) {
        case 'table':
            return nelxsdst_display_repeater_table($repeater_items, $show_view, $view_text, $show_download, $download_text, $view_button_class, $download_button_class, $post_type, $repeater_name, $hide_unauthorized);
        case 'cards':
            return nelxsdst_display_repeater_cards($repeater_items, $show_view, $view_text, $show_download, $download_text, $view_button_class, $download_button_class, $post_type, $repeater_name, $hide_unauthorized);
        default:
            return nelxsdst_display_repeater_table($repeater_items, $show_view, $view_text, $show_download, $download_text, $view_button_class, $download_button_class, $post_type, $repeater_name, $hide_unauthorized);
    }
}

/**
 * Display repeater as table
 */
function nelxsdst_display_repeater_table(
    $repeater_items, $show_view, $view_text, $show_download, $download_text,
    $view_button_class, $download_button_class,
    $post_type = '', $repeater_name = '',
    $hide_unauthorized = false
) {
    $first_item = reset($repeater_items);
    $output = '<div class="nelxsdst-repeater-table-wrapper">';
    $output .= '<table class="nelxsdst-repeater-table">';

    // Header
    $output .= '<thead><tr>';
    foreach ($first_item as $key => $value) {
        $label = nelxsdst_get_field_label($key, $repeater_name, $post_type);
        $output .= '<th>' . esc_html($label) . '</th>';
    }
    $output .= '</tr></thead>';

    // Body
    $output .= '<tbody>';
    $row_index = 0;
    foreach ($repeater_items as $item) {
        $output .= '<tr>';
        foreach ($item as $key => $value) {
            $output .= '<td>';
        
            // Handle JSON strings that contain secured files
            if (is_string($value) && nelxsdst_is_json_attachment($value)) {
                $attachment_data = nelxsdst_parse_json_attachment($value);
                if ($attachment_data && isset($attachment_data['id'])) {
                    $attachment_id = $attachment_data['id'];
                    if (get_post_meta($attachment_id, '_nelxsdst_secure_file', true)) {
                        // Check user access if hiding is enabled
                        if ($hide_unauthorized && !NELXSDST_File_Access::user_can_access_file($attachment_id)) {
                            $output .= '—'; // Show dash instead of empty cell
                            $output .= '</td>';
                            continue;
                        }
                        $file_output = nelxsdst_generate_secure_file_buttons($attachment_id, $show_view, $view_text, $show_download, $download_text, $view_button_class, $download_button_class);
                        $output .= '<div class="nelxsdst-file-buttons nelxsdst-table-buttons">' . $file_output . '</div>';
                        $output .= '</td>';
                        continue;
                    }
                    $value = $attachment_id;
                }
            }
        
            $attachment_ids = nelxsdst_normalize_attachment_ids($value);
            $has_secure_files = false;
            $has_accessible_files = false;
            $secure_file_output = '';
        
            foreach ($attachment_ids as $attachment_id) {
                if (get_post_meta($attachment_id, '_nelxsdst_secure_file', true)) {
                    $has_secure_files = true;
                    // Check user access if hiding is enabled
                    if ($hide_unauthorized && !NELXSDST_File_Access::user_can_access_file($attachment_id)) {
                        continue; // Skip this file
                    }
                    $has_accessible_files = true;
                    $secure_file_output .= nelxsdst_generate_secure_file_buttons($attachment_id, $show_view, $view_text, $show_download, $download_text, $view_button_class, $download_button_class);
                }
            }
        
            if ($has_secure_files) {
                if ($has_accessible_files && !empty($secure_file_output)) {
                    $output .= '<div class="nelxsdst-file-buttons nelxsdst-table-buttons">' . $secure_file_output . '</div>';
                } else if ($hide_unauthorized) {
                    $output .= '—'; // Show dash for unauthorized users
                } else {
                    $output .= nelxsdst_format_cell_content($value, $key);
                }
            } else {
                $output .= nelxsdst_format_cell_content($value, $key);
            }
        
            $output .= '</td>';
        }
        $output .= '</tr>';
        $row_index++;
    }
    $output .= '</tbody></table></div>';

    return $output;
}

/**
 * Display repeater as cards
 */
function nelxsdst_display_repeater_cards(
    $repeater_items, $show_view, $view_text, $show_download, $download_text,
    $view_button_class, $download_button_class,
    $post_type = '', $repeater_name = '',
    $hide_unauthorized = false
) {
    $output = '<div class="nelxsdst-repeater-cards">';

    foreach ($repeater_items as $item) {
        $output .= '<div class="nelxsdst-repeater-card">';
        foreach ($item as $key => $value) {
            $label = nelxsdst_get_field_label($key, $repeater_name, $post_type);
            $output .= '<div class="nelxsdst-card-row">';
            $output .= '<div class="nelxsdst-card-label">' . esc_html($label) . '</div>';
            $output .= '<div class="nelxsdst-card-value">';
        
            // Handle JSON strings that contain secured files
            if (is_string($value) && nelxsdst_is_json_attachment($value)) {
                $attachment_data = nelxsdst_parse_json_attachment($value);
                if ($attachment_data && isset($attachment_data['id'])) {
                    $attachment_id = $attachment_data['id'];
                    if (get_post_meta($attachment_id, '_nelxsdst_secure_file', true)) {
                        // Check user access if hiding is enabled
                        if ($hide_unauthorized && !NELXSDST_File_Access::user_can_access_file($attachment_id)) {
                            $output .= '—'; // Show dash instead
                            $output .= '</div></div>';
                            continue;
                        }
                        $file_output = nelxsdst_generate_secure_file_buttons($attachment_id, $show_view, $view_text, $show_download, $download_text, $view_button_class, $download_button_class);
                        $output .= '<div class="nelxsdst-file-buttons nelxsdst-card-buttons">' . $file_output . '</div>';
                        $output .= '</div></div>';
                        continue;
                    }
                    $value = $attachment_id;
                }
            }
        
            $attachment_ids = nelxsdst_normalize_attachment_ids($value);
            $has_secure_files = false;
            $has_accessible_files = false;
            $secure_file_output = '';
        
            foreach ($attachment_ids as $attachment_id) {
                if (get_post_meta($attachment_id, '_nelxsdst_secure_file', true)) {
                    $has_secure_files = true;
                    // Check user access if hiding is enabled
                    if ($hide_unauthorized && !NELXSDST_File_Access::user_can_access_file($attachment_id)) {
                        continue; // Skip this file
                    }
                    $has_accessible_files = true;
                    $secure_file_output .= nelxsdst_generate_secure_file_buttons($attachment_id, $show_view, $view_text, $show_download, $download_text, $view_button_class, $download_button_class);
                }
            }
        
            if ($has_secure_files) {
                if ($has_accessible_files && !empty($secure_file_output)) {
                    $output .= '<div class="nelxsdst-file-buttons nelxsdst-card-buttons">' . $secure_file_output . '</div>';
                } else if ($hide_unauthorized) {
                    $output .= '—'; // Show dash for unauthorized users
                } else {
                    $output .= is_array($value) ? esc_html(implode(', ', $value)) : esc_html($value);
                }
            } else {
                $output .= is_array($value) ? esc_html(implode(', ', $value)) : esc_html($value);
            }
        
            $output .= '</div></div>';
        }
        $output .= '</div>';
    }
    $output .= '</div>';

    return $output;
}

/**
 * Format cell content for better text handling
 */
function nelxsdst_format_cell_content($value, $field_key = '') {
    if (is_array($value)) {
        // Handle array values
        return esc_html(implode(', ', $value));
    } elseif (is_string($value)) {
        // Check if this is likely a long text field
        if (nelxsdst_is_long_text_field($value, $field_key)) {
            return '<div class="nelxsdst-long-text-cell">' . esc_html($value) . '</div>';
        } else {
            return esc_html($value);
        }
    } else {
        return esc_html($value);
    }
}

/**
 * Detect if a field likely contains long text
 */
function nelxsdst_is_long_text_field($value, $field_key = '') {
    // Check value length
    if (strlen($value) > 150) {
        return true;
    }
    
    // Check for field name patterns that suggest long text
    $long_text_patterns = [
        '/description/i',
        '/content/i',
        '/message/i',
        '/bio/i',
        '/about/i',
        '/notes/i',
        '/comments/i',
        '/summary/i',
        '/details/i',
        '/wysiwyg/i',
        '/textarea/i',
        '/editor/i'
    ];
    
    foreach ($long_text_patterns as $pattern) {
        if (preg_match($pattern, $field_key)) {
            return true;
        }
    }
    
    // Check for multiple sentences or line breaks
    if (substr_count($value, '.') > 2 || strpos($value, "\n") !== false) {
        return true;
    }
    
    return false;
}

/**
 * Helper function to generate table styles
 */
function nelxsdst_generate_table_styles($cell_padding, $border_color) {
    $styles = 'border-color: ' . esc_attr($border_color) . ';';
    
    if (!empty($cell_padding['top'])) {
        $styles .= '--nelxsdst-cell-padding: ' . $cell_padding['top'] . $cell_padding['unit'] . ' ' . $cell_padding['right'] . $cell_padding['unit'] . ' ' . $cell_padding['bottom'] . $cell_padding['unit'] . ' ' . $cell_padding['left'] . $cell_padding['unit'] . ';';
    }
    
    return $styles;
}

/**
 * Repeater-specific callback function
 */
function nelxsdst_normalize_attachment_ids( $value, $sub_field = '' ) {
    if ( $sub_field && is_array( $value ) ) {
        $ids = [];
        foreach ( $value as $row ) {
            if ( is_array( $row ) && isset( $row[ $sub_field ] ) ) {
                $ids = array_merge( $ids, nelxsdst_normalize_attachment_ids( $row[ $sub_field ] ) );
            }
        }
        return array_values( array_unique( $ids ) );
    }
    if ( is_array( $value ) ) {
        foreach ( [ 'id', 'ID', 'attachment_id' ] as $key ) {
            if ( isset( $value[ $key ] ) ) { return nelxsdst_normalize_attachment_ids( $value[ $key ] ); }
        }
        $ids = [];
        foreach ( $value as $item ) { $ids = array_merge( $ids, nelxsdst_normalize_attachment_ids( $item ) ); }
        return array_values( array_unique( $ids ) );
    }
    if ( is_string( $value ) ) {
        $decoded = json_decode( $value, true );
        if ( is_array( $decoded ) ) { return nelxsdst_normalize_attachment_ids( $decoded ); }
        if ( nelxsdst_is_json_attachment( $value ) ) {
            $parsed = nelxsdst_parse_json_attachment( $value );
            return ! empty( $parsed['id'] ) ? [ absint( $parsed['id'] ) ] : [];
        }
        if ( preg_match( '/^\s*\d+(?:\s*[,|]\s*\d+)*\s*$/', $value ) ) {
            return array_values( array_unique( array_filter( array_map( 'absint', preg_split( '/[,|]/', $value ) ) ) ) );
        }
    }
    return is_numeric( $value ) && absint( $value ) ? [ absint( $value ) ] : [];
}
