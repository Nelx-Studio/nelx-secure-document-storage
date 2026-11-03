<?php
/**
 * Shared Nelx Studio Hub core loader.
 *
 * Every Nelx plugin may bundle a Hub core candidate. All candidates register
 * before plugins_loaded; the newest bundled version is then selected once.
 *
 * @package NelxSecureDocumentStorage
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'nelx_studio_hub_register_core_candidate' ) ) {
	/**
	 * Register a bundled Hub core candidate.
	 *
	 * @param string $version Hub core version.
	 * @param string $core_file Absolute path to core.php.
	 * @param string $base_dir Absolute Hub base directory.
	 * @param string $base_url Hub base URL.
	 * @return void
	 */
	function nelx_studio_hub_register_core_candidate( $version, $core_file, $base_dir, $base_url ) {
		global $nelx_studio_hub_core_candidates;

		if ( ! is_array( $nelx_studio_hub_core_candidates ) ) {
			$nelx_studio_hub_core_candidates = array();
		}

		$version   = sanitize_text_field( (string) $version );
		$core_file = wp_normalize_path( (string) $core_file );
		$base_dir  = trailingslashit( wp_normalize_path( (string) $base_dir ) );
		$base_url  = trailingslashit( esc_url_raw( (string) $base_url ) );

		if ( '' === $version || '' === $core_file || ! is_readable( $core_file ) ) {
			return;
		}

		$nelx_studio_hub_core_candidates[ $core_file ] = array(
			'version'   => $version,
			'core_file' => $core_file,
			'base_dir'  => $base_dir,
			'base_url'  => $base_url,
		);
	}

	/**
	 * Load the newest available Hub core once all plugins have registered.
	 *
	 * @return void
	 */
	function nelx_studio_hub_bootstrap_best_core() {
		global $nelx_studio_hub_core_candidates;

		// A standalone Hub (or another already-loaded core) takes precedence.
		if ( function_exists( 'nelx_studio_register_product' ) && class_exists( 'Nelx_Studio_Hub' ) ) {
			Nelx_Studio_Hub::instance();
			return;
		}

		if ( empty( $nelx_studio_hub_core_candidates ) || ! is_array( $nelx_studio_hub_core_candidates ) ) {
			return;
		}

		$selected = null;
		foreach ( $nelx_studio_hub_core_candidates as $candidate ) {
			if ( null === $selected || version_compare( $candidate['version'], $selected['version'], '>' ) ) {
				$selected = $candidate;
			}
		}

		if ( ! $selected || ! is_readable( $selected['core_file'] ) ) {
			return;
		}

		if ( ! defined( 'NELX_HUB_VERSION' ) ) {
			define( 'NELX_HUB_VERSION', $selected['version'] );
		}
		if ( ! defined( 'NELX_HUB_DIR' ) ) {
			define( 'NELX_HUB_DIR', $selected['base_dir'] );
		}
		if ( ! defined( 'NELX_HUB_URL' ) ) {
			define( 'NELX_HUB_URL', $selected['base_url'] );
		}

		require_once $selected['core_file'];
	}

	add_action( 'plugins_loaded', 'nelx_studio_hub_bootstrap_best_core', 1 );
}
