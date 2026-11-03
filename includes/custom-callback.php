<?php
if (!defined('ABSPATH')) exit;
/**
 * JetEngine Custom Callback for Secure File Links
 */
// Register callback name with JetEngine
add_filter('jet-engine/listings/allowed-callbacks', function($callbacks) {
    $callbacks['nelxsdst_secure_file_links'] = __('Secure File Links', 'nelx-secure-document-storage');
    return $callbacks;
});

/*
* CALLBACK-STYLE CONTROLS
*/
add_filter('jet-engine/listings/allowed-callbacks-args', function( $args ) {
    $base = [
        'dynamic_field_filter' => 'yes',
        'filter_callback'     => 'nelxsdst_secure_file_links',
    ];

    $args['nelxsdst_show_view_button'] = [
        'label'     => __( 'Show View Button', 'nelx-secure-document-storage' ),
        'type'      => 'switcher',
        'default'   => 'yes',
        'condition' => $base,
    ];
    $args['nelxsdst_view_button_text'] = [
        'label'     => __( 'View Button Text', 'nelx-secure-document-storage' ),
        'type'      => 'text',
        'default'   => __( 'View File', 'nelx-secure-document-storage' ),
        'condition' => [ 'nelxsdst_show_view_button' => 'yes' ] + $base,
    ];
    $args['nelxsdst_show_download_button'] = [
        'label'     => __( 'Show Download Button', 'nelx-secure-document-storage' ),
        'type'      => 'switcher',
        'default'   => 'yes',
        'condition' => $base,
    ];
    $args['nelxsdst_download_button_text'] = [
        'label'     => __( 'Download Button Text', 'nelx-secure-document-storage' ),
        'type'      => 'text',
        'default'   => __( 'Download File', 'nelx-secure-document-storage' ),
        'condition' => [ 'nelxsdst_show_download_button' => 'yes' ] + $base,
    ];
    $args['nelxsdst_view_button_class'] = [
        'label'     => __( 'View Button CSS Class', 'nelx-secure-document-storage' ),
        'type'      => 'text',
        'default'   => 'nelxsdst-secure-view-btn',
        'condition' => $base,
    ];
    $args['nelxsdst_download_button_class'] = [
        'label'     => __( 'Download Button CSS Class', 'nelx-secure-document-storage' ),
        'type'      => 'text',
        'default'   => 'nelxsdst-secure-download-btn',
        'condition' => $base,
    ];

    $args['nelxsdst_is_repeater_field'] = [
        'label'     => __( 'This is a Repeater Field', 'nelx-secure-document-storage' ),
        'type'      => 'switcher',
        'default'   => 'no',
        'separator' => 'before',
        'condition' => $base,
    ];
    $args['nelxsdst_repeater_layout'] = [
        'label'     => __( 'Repeater Layout', 'nelx-secure-document-storage' ),
        'type'      => 'select',
        'default'   => 'table',
        'options'   => [
            'table' => __( 'Table', 'nelx-secure-document-storage' ),
            'cards' => __( 'Cards', 'nelx-secure-document-storage' ),
        ],
        'condition' => [ 'nelxsdst_is_repeater_field' => 'yes' ] + $base,
    ];

    return $args;
});

add_action(
    'elementor/element/jet-listing-dynamic-field/section_field_style/before_section_start',
    'nelxsdst_add_custom_style_sections',
    10,
    2
);
function nelxsdst_add_custom_style_sections($widget) {

    if (!class_exists('Elementor\\Controls_Manager')) { return; }
    /* BUTTON STYLING */
    $widget->start_controls_section('nelxsdst_section_button_style', [
        'label' => 'Button Styling', 'tab' => \Elementor\Controls_Manager::TAB_STYLE,
        'condition' => ['dynamic_field_filter' => 'yes', 'filter_callback' => 'nelxsdst_secure_file_links']
    ]);

    // View Button Styling
    $widget->add_control('nelxsdst_view_button_heading', [
        'label' => 'View Button',
        'type' => \Elementor\Controls_Manager::HEADING,
        'separator' => 'before',
    ]);

    $widget->add_control('nelxsdst_view_button_text_color', [
        'label' => 'Text Color',
        'type' => \Elementor\Controls_Manager::COLOR,
        'selectors' => ['{{WRAPPER}} .nelxsdst-secure-view-btn' => 'color: {{VALUE}};']
    ]);

    $widget->add_control('nelxsdst_view_button_bg_color', [
        'label' => 'Background Color',
        'type' => \Elementor\Controls_Manager::COLOR,
        'selectors' => ['{{WRAPPER}} .nelxsdst-secure-view-btn' => 'background-color: {{VALUE}};']
    ]);

    $widget->add_group_control(\Elementor\Group_Control_Typography::get_type(), [
        'name' => 'nelxsdst_view_button_typography',
        'label' => 'Typography',
        'selector' => '{{WRAPPER}} .nelxsdst-secure-view-btn',
    ]);

    $widget->add_group_control(\Elementor\Group_Control_Border::get_type(), [
        'name' => 'nelxsdst_view_button_border',
        'label' => 'Border',
        'selector' => '{{WRAPPER}} .nelxsdst-secure-view-btn',
    ]);

    $widget->add_responsive_control('nelxsdst_view_button_border_radius', [
        'label' => 'Border Radius',
        'type' => \Elementor\Controls_Manager::DIMENSIONS,
        'selectors' => ['{{WRAPPER}} .nelxsdst-secure-view-btn' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};']
    ]);

    $widget->add_responsive_control('nelxsdst_view_button_padding', [
        'label' => 'Padding',
        'type' => \Elementor\Controls_Manager::DIMENSIONS,
        'selectors' => ['{{WRAPPER}} .nelxsdst-secure-view-btn' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};']
    ]);

    $widget->add_group_control(\Elementor\Group_Control_Box_Shadow::get_type(), [
        'name' => 'nelxsdst_view_button_box_shadow',
        'label' => 'Box Shadow',
        'selector' => '{{WRAPPER}} .nelxsdst-secure-view-btn',
    ]);

    // View Button Hover
    $widget->add_control('nelxsdst_view_button_hover_heading', [
        'label' => 'View Button Hover',
        'type' => \Elementor\Controls_Manager::HEADING,
        'separator' => 'before',
    ]);

    $widget->add_control('nelxsdst_view_button_hover_text_color', [
        'label' => 'Text Color',
        'type' => \Elementor\Controls_Manager::COLOR,
        'selectors' => ['{{WRAPPER}} .nelxsdst-secure-view-btn:hover' => 'color: {{VALUE}};']
    ]);

    $widget->add_control('nelxsdst_view_button_hover_bg_color', [
        'label' => 'Background Color',
        'type' => \Elementor\Controls_Manager::COLOR,
        'selectors' => ['{{WRAPPER}} .nelxsdst-secure-view-btn:hover' => 'background-color: {{VALUE}};']
    ]);

    $widget->add_control('nelxsdst_view_button_hover_border_color', [
        'label' => 'Border Color',
        'type' => \Elementor\Controls_Manager::COLOR,
        'selectors' => ['{{WRAPPER}} .nelxsdst-secure-view-btn:hover' => 'border-color: {{VALUE}};']
    ]);

    $widget->add_group_control(\Elementor\Group_Control_Box_Shadow::get_type(), [
        'name' => 'nelxsdst_view_button_hover_box_shadow',
        'label' => 'Box Shadow',
        'selector' => '{{WRAPPER}} .nelxsdst-secure-view-btn:hover',
    ]);

    $widget->add_control('nelxsdst_view_button_hover_animation', [
        'label' => 'Hover Animation',
        'type' => \Elementor\Controls_Manager::HOVER_ANIMATION,
    ]);

    $widget->add_control('nelxsdst_view_button_hover_transform', [
        'label' => 'Hover Transform',
        'type' => \Elementor\Controls_Manager::SELECT,
        'default' => 'translateY',
        'options' => [
            'none' => 'None',
            'translateY' => 'Lift Up',
            'translateX' => 'Slide Right',
            'scale' => 'Scale Up',
        ],
    ]);

    // Download Button Styling
    $widget->add_control('nelxsdst_download_button_heading', [
        'label' => 'Download Button',
        'type' => \Elementor\Controls_Manager::HEADING,
        'separator' => 'before',
    ]);

    $widget->add_control('nelxsdst_download_button_text_color', [
        'label' => 'Text Color',
        'type' => \Elementor\Controls_Manager::COLOR,
        'selectors' => ['{{WRAPPER}} .nelxsdst-secure-download-btn' => 'color: {{VALUE}};']
    ]);

    $widget->add_control('nelxsdst_download_button_bg_color', [
        'label' => 'Background Color',
        'type' => \Elementor\Controls_Manager::COLOR,
        'selectors' => ['{{WRAPPER}} .nelxsdst-secure-download-btn' => 'background-color: {{VALUE}};']
    ]);

    $widget->add_group_control(\Elementor\Group_Control_Typography::get_type(), [
        'name' => 'nelxsdst_download_button_typography',
        'label' => 'Typography',
        'selector' => '{{WRAPPER}} .nelxsdst-secure-download-btn',
    ]);

    $widget->add_group_control(\Elementor\Group_Control_Border::get_type(), [
        'name' => 'nelxsdst_download_button_border',
        'label' => 'Border',
        'selector' => '{{WRAPPER}} .nelxsdst-secure-download-btn',
    ]);

    $widget->add_responsive_control('nelxsdst_download_button_border_radius', [
        'label' => 'Border Radius',
        'type' => \Elementor\Controls_Manager::DIMENSIONS,
        'selectors' => ['{{WRAPPER}} .nelxsdst-secure-download-btn' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};']
    ]);

    $widget->add_responsive_control('nelxsdst_download_button_padding', [
        'label' => 'Padding',
        'type' => \Elementor\Controls_Manager::DIMENSIONS,
        'selectors' => ['{{WRAPPER}} .nelxsdst-secure-download-btn' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};']
    ]);

    $widget->add_group_control(\Elementor\Group_Control_Box_Shadow::get_type(), [
        'name' => 'nelxsdst_download_button_box_shadow',
        'label' => 'Box Shadow',
        'selector' => '{{WRAPPER}} .nelxsdst-secure-download-btn',
    ]);

    // Download Button Hover
    $widget->add_control('nelxsdst_download_button_hover_heading', [
        'label' => 'Download Button Hover',
        'type' => \Elementor\Controls_Manager::HEADING,
        'separator' => 'before',
    ]);

    $widget->add_control('nelxsdst_download_button_hover_text_color', [
        'label' => 'Text Color',
        'type' => \Elementor\Controls_Manager::COLOR,
        'selectors' => ['{{WRAPPER}} .nelxsdst-secure-download-btn:hover' => 'color: {{VALUE}};']
    ]);

    $widget->add_control('nelxsdst_download_button_hover_bg_color', [
        'label' => 'Background Color',
        'type' => \Elementor\Controls_Manager::COLOR,
        'selectors' => ['{{WRAPPER}} .nelxsdst-secure-download-btn:hover' => 'background-color: {{VALUE}};']
    ]);

    $widget->add_control('nelxsdst_download_button_hover_border_color', [
        'label' => 'Border Color',
        'type' => \Elementor\Controls_Manager::COLOR,
        'selectors' => ['{{WRAPPER}} .nelxsdst-secure-download-btn:hover' => 'border-color: {{VALUE}};']
    ]);

    $widget->add_group_control(\Elementor\Group_Control_Box_Shadow::get_type(), [
        'name' => 'nelxsdst_download_button_hover_box_shadow',
        'label' => 'Box Shadow',
        'selector' => '{{WRAPPER}} .nelxsdst-secure-download-btn:hover',
    ]);

    $widget->add_control('nelxsdst_download_button_hover_animation', [
        'label' => 'Hover Animation',
        'type' => \Elementor\Controls_Manager::HOVER_ANIMATION,
    ]);

    $widget->add_control('nelxsdst_download_button_hover_transform', [
        'label' => 'Hover Transform',
        'type' => \Elementor\Controls_Manager::SELECT,
        'default' => 'translateY',
        'options' => [
            'none' => 'None',
            'translateY' => 'Lift Up',
            'translateX' => 'Slide Right',
            'scale' => 'Scale Up',
        ],
    ]);

    // Button Layout
    $widget->add_control('nelxsdst_button_layout_heading', [
        'label' => 'Button Layout',
        'type' => \Elementor\Controls_Manager::HEADING,
        'separator' => 'before',
    ]);

    $widget->add_responsive_control('nelxsdst_button_gap', [
        'label' => 'Button Gap',
        'type' => \Elementor\Controls_Manager::SLIDER,
        'size_units' => ['px'],
        'range' => ['px' => ['min' => 0, 'max' => 50]],
        'default' => ['size' => 5],
        'selectors' => ['{{WRAPPER}} .nelxsdst-file-buttons' => 'gap: {{SIZE}}{{UNIT}};']
    ]);

    $widget->add_responsive_control('nelxsdst_button_alignment', [
        'label' => 'Alignment',
        'type' => \Elementor\Controls_Manager::CHOOSE,
        'options' => [
            'flex-start' => ['title' => 'Left', 'icon' => 'eicon-text-align-left'],
            'center' => ['title' => 'Center', 'icon' => 'eicon-text-align-center'],
            'flex-end' => ['title' => 'Right', 'icon' => 'eicon-text-align-right'],
        ],
        'default' => 'flex-start',
        'selectors' => ['{{WRAPPER}} .nelxsdst-file-buttons' => 'justify-content: {{VALUE}};']
    ]);

    $widget->end_controls_section();

    /* CARDS STYLING */
    $widget->start_controls_section('nelxsdst_section_cards_style', [
        'label' => 'Cards Styling',
        'tab' => \Elementor\Controls_Manager::TAB_STYLE,
        'condition' => ['nelxsdst_is_repeater_field' => 'yes', 'nelxsdst_repeater_layout' => 'cards']
    ]);

    // Cards Layout
    $widget->add_responsive_control('nelxsdst_cards_columns', [
        'label' => 'Columns',
        'type' => \Elementor\Controls_Manager::SLIDER,
        'range' => ['px' => ['min' => 1, 'max' => 6]],
        'default' => ['size' => 3],
        'selectors' => ['{{WRAPPER}} .nelxsdst-repeater-cards' => '--nelxsdst-cols: {{SIZE}};']
    ]);

    $widget->add_responsive_control('nelxsdst_cards_gap', [
        'label' => 'Gap Between Cards',
        'type' => \Elementor\Controls_Manager::SLIDER,
        'default' => ['size' => 20],
        'selectors' => ['{{WRAPPER}} .nelxsdst-repeater-cards' => '--nelxsdst-gap: {{SIZE}}px;']
    ]);

    // Cards Background & Border
    $widget->add_control('nelxsdst_cards_background', [
        'label' => 'Background Color',
        'type' => \Elementor\Controls_Manager::COLOR,
        'selectors' => ['{{WRAPPER}} .nelxsdst-repeater-card' => 'background: {{VALUE}};']
    ]);

    $widget->add_group_control(\Elementor\Group_Control_Border::get_type(), [
        'name' => 'nelxsdst_cards_border',
        'label' => 'Border',
        'selector' => '{{WRAPPER}} .nelxsdst-repeater-card',
    ]);

    $widget->add_responsive_control('nelxsdst_cards_border_radius', [
        'label' => 'Border Radius',
        'type' => \Elementor\Controls_Manager::DIMENSIONS,
        'selectors' => ['{{WRAPPER}} .nelxsdst-repeater-card' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};']
    ]);

    $widget->add_group_control(\Elementor\Group_Control_Box_Shadow::get_type(), [
        'name' => 'nelxsdst_cards_box_shadow',
        'label' => 'Box Shadow',
        'selector' => '{{WRAPPER}} .nelxsdst-repeater-card',
    ]);

    $widget->add_responsive_control('nelxsdst_cards_padding', [
        'label' => 'Padding',
        'type' => \Elementor\Controls_Manager::DIMENSIONS,
        'selectors' => ['{{WRAPPER}} .nelxsdst-repeater-card' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};']
    ]);

    // Cards Hover Effects
    $widget->add_control('nelxsdst_cards_hover_heading', [
        'label' => 'Hover Effects',
        'type' => \Elementor\Controls_Manager::HEADING,
        'separator' => 'before',
    ]);

    $widget->add_control('nelxsdst_cards_hover_background', [
        'label' => 'Hover Background',
        'type' => \Elementor\Controls_Manager::COLOR,
        'selectors' => ['{{WRAPPER}} .nelxsdst-repeater-card:hover' => 'background: {{VALUE}};']
    ]);

    $widget->add_control('nelxsdst_cards_hover_border_color', [
        'label' => 'Hover Border Color',
        'type' => \Elementor\Controls_Manager::COLOR,
        'selectors' => ['{{WRAPPER}} .nelxsdst-repeater-card:hover' => 'border-color: {{VALUE}};']
    ]);

    $widget->add_group_control(\Elementor\Group_Control_Box_Shadow::get_type(), [
        'name' => 'nelxsdst_cards_hover_box_shadow',
        'label' => 'Hover Box Shadow',
        'selector' => '{{WRAPPER}} .nelxsdst-repeater-card:hover',
    ]);

    $widget->add_control('nelxsdst_cards_hover_transform', [
        'label' => 'Hover Transform',
        'type' => \Elementor\Controls_Manager::SLIDER,
        'range' => ['px' => ['min' => -20, 'max' => 20]],
        'default' => ['size' => -2],
        'selectors' => ['{{WRAPPER}} .nelxsdst-repeater-card:hover' => 'transform: translateY({{SIZE}}px);']
    ]);

    // Label/Typography Styling
    $widget->add_control('nelxsdst_cards_label_heading', [
        'label' => 'Label/Title Styling',
        'type' => \Elementor\Controls_Manager::HEADING,
        'separator' => 'before',
    ]);

    $widget->add_control('nelxsdst_cards_label_color', [
        'label' => 'Label Color',
        'type' => \Elementor\Controls_Manager::COLOR,
        'selectors' => ['{{WRAPPER}} .nelxsdst-card-label' => 'color: {{VALUE}};']
    ]);

    $widget->add_group_control(\Elementor\Group_Control_Typography::get_type(), [
        'name' => 'nelxsdst_cards_label_typography',
        'label' => 'Label Typography',
        'selector' => '{{WRAPPER}} .nelxsdst-card-label',
    ]);

    // Value/Body Typography Styling
    $widget->add_control('nelxsdst_cards_value_heading', [
        'label' => 'Value/Body Styling',
        'type' => \Elementor\Controls_Manager::HEADING,
        'separator' => 'before',
    ]);

    $widget->add_control('nelxsdst_cards_value_color', [
        'label' => 'Value Color',
        'type' => \Elementor\Controls_Manager::COLOR,
        'selectors' => ['{{WRAPPER}} .nelxsdst-card-value' => 'color: {{VALUE}};']
    ]);

    $widget->add_group_control(\Elementor\Group_Control_Typography::get_type(), [
        'name' => 'nelxsdst_cards_value_typography',
        'label' => 'Value Typography',
        'selector' => '{{WRAPPER}} .nelxsdst-card-value',
    ]);

    // Card Row Styling
    $widget->add_control('nelxsdst_cards_row_heading', [
        'label' => 'Row Styling',
        'type' => \Elementor\Controls_Manager::HEADING,
        'separator' => 'before',
    ]);

    $widget->add_control('nelxsdst_cards_row_border_color', [
        'label' => 'Row Border Color',
        'type' => \Elementor\Controls_Manager::COLOR,
        'selectors' => ['{{WRAPPER}} .nelxsdst-card-row' => 'border-bottom-color: {{VALUE}};']
    ]);

    $widget->add_responsive_control('nelxsdst_cards_row_spacing', [
        'label' => 'Row Spacing',
        'type' => \Elementor\Controls_Manager::SLIDER,
        'range' => ['px' => ['min' => 0, 'max' => 50]],
        'default' => ['size' => 12],
        'selectors' => ['{{WRAPPER}} .nelxsdst-card-row' => 'margin-bottom: {{SIZE}}px;']
    ]);

    $widget->end_controls_section();

    /* TABLE STYLING */
    $widget->start_controls_section('nelxsdst_section_table_style', [
        'label' => 'Table Styling',
        'tab' => \Elementor\Controls_Manager::TAB_STYLE,
        'condition' => ['nelxsdst_is_repeater_field' => 'yes', 'nelxsdst_repeater_layout' => 'table']
    ]);

    // Table Header Styling
    $widget->add_control('nelxsdst_table_header_heading', [
        'label' => 'Header Styling',
        'type' => \Elementor\Controls_Manager::HEADING,
    ]);

    $widget->add_control('nelxsdst_table_header_bg', [
        'label' => 'Header Background',
        'type' => \Elementor\Controls_Manager::COLOR,
        'selectors' => ['{{WRAPPER}} .nelxsdst-repeater-table th' => 'background: {{VALUE}};']
    ]);

    $widget->add_control('nelxsdst_table_header_color', [
        'label' => 'Header Text Color',
        'type' => \Elementor\Controls_Manager::COLOR,
        'selectors' => ['{{WRAPPER}} .nelxsdst-repeater-table th' => 'color: {{VALUE}};']
    ]);

    $widget->add_group_control(\Elementor\Group_Control_Typography::get_type(), [
        'name' => 'nelxsdst_table_header_typography',
        'label' => 'Header Typography',
        'selector' => '{{WRAPPER}} .nelxsdst-repeater-table th',
    ]);

    // Table Body Styling
    $widget->add_control('nelxsdst_table_body_heading', [
        'label' => 'Body Styling',
        'type' => \Elementor\Controls_Manager::HEADING,
        'separator' => 'before',
    ]);

    $widget->add_control('nelxsdst_table_body_color', [
        'label' => 'Body Text Color',
        'type' => \Elementor\Controls_Manager::COLOR,
        'selectors' => ['{{WRAPPER}} .nelxsdst-repeater-table td' => 'color: {{VALUE}};']
    ]);

    $widget->add_group_control(\Elementor\Group_Control_Typography::get_type(), [
        'name' => 'nelxsdst_table_body_typography',
        'label' => 'Body Typography',
        'selector' => '{{WRAPPER}} .nelxsdst-repeater-table td',
    ]);

    // Table Border & Layout
    $widget->add_control('nelxsdst_table_border_heading', [
        'label' => 'Border & Layout',
        'type' => \Elementor\Controls_Manager::HEADING,
        'separator' => 'before',
    ]);

    $widget->add_group_control(\Elementor\Group_Control_Border::get_type(), [
        'name' => 'nelxsdst_table_border',
        'label' => 'Table Border',
        'selector' => '{{WRAPPER}} .nelxsdst-repeater-table',
    ]);

    $widget->add_responsive_control('nelxsdst_table_cell_padding', [
        'label' => 'Cell Padding',
        'type' => \Elementor\Controls_Manager::DIMENSIONS,
        'selectors' => ['{{WRAPPER}} .nelxsdst-repeater-table th, {{WRAPPER}} .nelxsdst-repeater-table td' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};']
    ]);

    // Table Striped Rows
    $widget->add_control('nelxsdst_table_striped_heading', [
        'label' => 'Striped Rows',
        'type' => \Elementor\Controls_Manager::HEADING,
        'separator' => 'before',
    ]);

    $widget->add_control('nelxsdst_table_striped_rows', [
        'label' => 'Enable Striped Rows',
        'type' => \Elementor\Controls_Manager::SWITCHER,
        'return_value' => 'yes',
        'default' => 'no',
    ]);

    $widget->add_control('nelxsdst_table_stripe_color', [
        'label' => 'Stripe Color',
        'type' => \Elementor\Controls_Manager::COLOR,
        'condition' => ['nelxsdst_table_striped_rows' => 'yes'],
        'selectors' => ['{{WRAPPER}} .nelxsdst-repeater-table.nelxsdst-striped tr:nth-child(even)' => 'background: {{VALUE}};']
    ]);

    // Table Hover Effects
    $widget->add_control('nelxsdst_table_hover_heading', [
        'label' => 'Hover Effects',
        'type' => \Elementor\Controls_Manager::HEADING,
        'separator' => 'before',
    ]);

    $widget->add_control('nelxsdst_table_hover_bg', [
        'label' => 'Row Hover Background',
        'type' => \Elementor\Controls_Manager::COLOR,
        'selectors' => ['{{WRAPPER}} .nelxsdst-repeater-table tr:hover' => 'background-color: {{VALUE}};']
    ]);

    $widget->end_controls_section();
}

// Inject callback args when rendering the dynamic field
add_filter('jet-engine/listing/dynamic-field/callback-args', function($args, $callback, $settings = []) {
    if ('nelxsdst_secure_file_links' === $callback) {
        $args[] = $settings['nelxsdst_show_view_button'] ?? 'yes';
        $args[] = $settings['nelxsdst_view_button_text'] ?? 'View File';
        $args[] = $settings['nelxsdst_show_download_button'] ?? 'yes';
        $args[] = $settings['nelxsdst_download_button_text'] ?? 'Download File';
        $args[] = $settings['nelxsdst_view_button_class'] ?? 'nelxsdst-secure-view-btn';
        $args[] = $settings['nelxsdst_download_button_class'] ?? 'nelxsdst-secure-download-btn';
        $args[] = $settings['nelxsdst_is_repeater_field'] ?? 'no';
        $args[] = $settings['nelxsdst_repeater_layout'] ?? 'table';

        // ONLY FIELD NAME
        $field_name = $settings['dynamic_field_post_meta'] ?? $settings['dynamic_field_post_meta_custom'] ?? '';
        $args[] = $field_name;
    }
    return $args;
}, 10, 3);

/**
 * Detect if we're in form processing context
 */
function nelxsdst_is_form_processing() {
    if (class_exists('NELXSDST_SecureStorage') && NELXSDST_SecureStorage::is_verified_jfb_request()) {
        return true;
    }
    
    // Check if we're in a form editing context (looking at form fields)
    if (is_admin() && function_exists('get_current_screen')) {
        $screen = get_current_screen();
        if ($screen && strpos($screen->id, 'jet-form-builder') !== false) {
            return true;
        }
    }
    
    return false;
}

/**
 * The main callback function: generates secure file links
 */
function nelxsdst_secure_file_links(
    $value,
    $show_view = 'yes', $view_text = '',
    $show_download = 'yes', $download_text = '',
    $view_button_class = '', $download_button_class = '',
    $is_repeater = 'no', $repeater_layout = 'table',
    $field_name = ''
) {
    
    // If we're in form processing, return the original value (IDs only)
    if (nelxsdst_is_form_processing()) {
        return $value; // Return plain IDs, no secure URLs
    }
    
    // Only generate secure URLs for actual display to users
    if (empty($value)) return '';
    $asset_markup = NELXSDST_Renderer::assets();

    // Check if we should hide buttons for unauthorized users
    $hide_unauthorized = get_option('nelxsdst_hide_buttons_unauthorized', 'no') === 'yes';
    
    // === 1. Handle JSON strings that might contain secure file data ===
    if (is_string($value) && nelxsdst_is_json_attachment($value)) {
        $attachment_data = nelxsdst_parse_json_attachment($value);
        if ($attachment_data && isset($attachment_data['id'])) {
            $attachment_id = $attachment_data['id'];
            if (get_post_meta($attachment_id, '_nelxsdst_secure_file', true)) {
                // Check user access if hiding is enabled
                if ($hide_unauthorized && !NELXSDST_File_Access::user_can_access_file($attachment_id)) {
                    return '—'; // Show dash for unauthorized users
                }
                return $asset_markup . nelxsdst_generate_secure_file_buttons($attachment_id, $show_view, $view_text, $show_download, $download_text, $view_button_class, $download_button_class);
            }
            $value = $attachment_id;
        }
    }

    // === 2. Repeater field ===
    if ($is_repeater === 'yes' && is_array($value)) {
        $current = function_exists('jet_engine') ? jet_engine()->listings->data->get_current_object() : null;
        $post_type = '';
        if ($current instanceof WP_Post) {
            $post_type = $current->post_type;
        } elseif ($current instanceof WP_User) {
            $post_type = 'user';
        } elseif (function_exists('jet_engine') && jet_engine()->listings->data->get_listing_source() === 'cct') {
            $post_type = jet_engine()->listings->data->get_current_cct_type();
        }

        return $asset_markup . nelxsdst_display_repeater_field(
            $value, $repeater_layout,
            $show_view, $view_text, $show_download, $download_text,
            $view_button_class, $download_button_class,
            $post_type, $field_name,
            $hide_unauthorized // Pass the setting to repeater functions
        );
    }

    // === 3. ORIGINAL single/multiple files ===
    $attachment_ids = nelxsdst_normalize_attachment_ids($value);
    if (empty($attachment_ids)) return '';

    $output = $asset_markup . '<div class="nelxsdst-file-links">';
    $view_button_class = $view_button_class ?: 'nelxsdst-secure-view-btn';
    $download_button_class = $download_button_class ?: 'nelxsdst-secure-download-btn';
    $view_text = $view_text ?: __('View File', 'nelx-secure-document-storage');
    $download_text = $download_text ?: __('Download File', 'nelx-secure-document-storage');

    $has_secure_files = false;
    $has_accessible_files = false;
    $secure_file_output = '';

    foreach ($attachment_ids as $attachment_id) {
        if (!get_post_meta($attachment_id, '_nelxsdst_secure_file', true)) continue;

        $has_secure_files = true;
        
        // Check user access if hiding is enabled
        if ($hide_unauthorized && !NELXSDST_File_Access::user_can_access_file($attachment_id)) {
            continue; // Skip this file for unauthorized users
        }

        $has_accessible_files = true;
        $file_output = nelxsdst_generate_secure_file_buttons($attachment_id, $show_view, $view_text, $show_download, $download_text, $view_button_class, $download_button_class);
        $secure_file_output .= $file_output;
    }

    if ($has_secure_files) {
        if ($has_accessible_files && !empty($secure_file_output)) {
            $output .= $secure_file_output;
        } else if ($hide_unauthorized) {
            $output .= '—'; // Show dash for unauthorized users
        } else {
            // If not hiding and no accessible files, show nothing or fallback
            $output .= '';
        }
    } else {
        // No secured files found, return empty
        $output .= '';
    }
    
    $output .= '</div>';

    return $output;
}

/**
 * Generate secure file buttons directly for already-secured files
 */
/**
 * Robust JSON attachment detection for callback
 */
function nelxsdst_secure_file_links_repeater($value, $show_view = 'yes', $view_text = '', $show_download = 'yes', $download_text = '', $button_class = '', $sub_field = '') {
    return nelxsdst_secure_file_links($value, $show_view, $view_text, $show_download, $download_text, $button_class, $button_class, 'no', 'table');
}

/**
 * Helper function to get secure file URLs for use in templates 
 */
function nelxsdst_get_secure_file_links($attachment_id, $args = []) {
    $defaults = [
        'show_view' => true,
        'view_text' => __('View File', 'nelx-secure-document-storage'),
        'show_download' => true,
        'download_text' => __('Download File', 'nelx-secure-document-storage'),
        'view_button_class' => 'nelxsdst-secure-view-btn',
        'download_button_class' => 'nelxsdst-secure-download-btn',
    ];
    
    $args = wp_parse_args($args, $defaults);
    
    return nelxsdst_secure_file_links(
        $attachment_id,
        $args['show_view'] ? 'yes' : 'no',
        $args['view_text'],
        $args['show_download'] ? 'yes' : 'no',
        $args['download_text'],
        $args['view_button_class'],
        $args['download_button_class']
    );
}

