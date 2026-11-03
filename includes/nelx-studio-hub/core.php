<?php
/**
 * Shared Nelx Studio Hub core bootstrap.
 *
 * @package NelxStudioHub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'NELX_HUB_DIR' ) || ! defined( 'NELX_HUB_URL' ) || ! defined( 'NELX_HUB_VERSION' ) ) {
	return;
}

require_once NELX_HUB_DIR . 'includes/class-nelx-studio-hub.php';

if ( ! function_exists( 'nelx_studio_register_product' ) ) {
	/**
	 * Register a Nelx Studio product with the shared Hub.
	 *
	 * @param array $product Product data.
	 * @return bool
	 */
	function nelx_studio_register_product( $product ) {
		return Nelx_Studio_Hub::instance()->register_product( $product );
	}
}

if ( ! function_exists( 'nelx_studio_hub_version' ) ) {
	/**
	 * Return the running Hub version.
	 *
	 * @return string
	 */
	function nelx_studio_hub_version() {
		return NELX_HUB_VERSION;
	}
}

Nelx_Studio_Hub::instance();
