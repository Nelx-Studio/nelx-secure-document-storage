<?php
/**
 * Nelx Studio Hub admin application.
 *
 * @package NelxStudioHub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Nelx_Studio_Hub {
	/** @var self|null */
	private static $instance = null;

	/** @var array<string,array> */
	private $registered_products = array();

	/** @var array<string,array>|null */
	private $resolved_products = null;

	/** @var array<int,array<string,mixed>> */
	private $menu_groups = array();

	/**
	 * Get singleton.
	 *
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_base_menu' ), 5 );
		add_action( 'admin_menu', array( $this, 'integrate_product_menus' ), PHP_INT_MAX );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_filter( 'parent_file', array( $this, 'filter_parent_file' ) );
		add_filter( 'submenu_file', array( $this, 'filter_submenu_file' ), 10, 2 );
		add_action( 'wp_ajax_nelx_hub_refresh_updates', array( $this, 'ajax_refresh_updates' ) );
		add_action( 'wp_ajax_nelx_hub_update_plugin', array( $this, 'ajax_update_plugin' ) );
		add_action( 'wp_ajax_nelx_hub_activate_plugin', array( $this, 'ajax_activate_plugin' ) );
	}

	/**
	 * Register a Nelx Studio product with the Hub.
	 *
	 * Products should provide their real WordPress menu slug. The Hub never
	 * invents or rewrites product page slugs.
	 *
	 * @param array $product Product data.
	 * @return bool
	 */
	public function register_product( $product ) {
		$defaults = array(
			'slug'        => '',
			'name'        => '',
			'version'     => '',
			'description' => '',
			'plugin_file' => '',
			'menu_slug'   => '',
			'menu_roots'  => array(),
			'manage_url'  => '',
			'docs_url'    => '',
			'learn_url'   => '',
			'icon'        => 'dashicons-admin-plugins',
			'accent'      => 'blue',
			'plan'        => '',
			'tags'        => array(),
		);

		$product = wp_parse_args( $product, $defaults );
		$slug    = sanitize_key( $product['slug'] );
		$name    = sanitize_text_field( $product['name'] );

		if ( empty( $slug ) || empty( $name ) ) {
			return false;
		}

		$product['slug']        = $slug;
		$product['name']        = $name;
		$product['version']     = sanitize_text_field( $product['version'] );
		$product['description'] = sanitize_text_field( $product['description'] );
		$product['plugin_file'] = sanitize_text_field( $product['plugin_file'] );
		$product['menu_slug']   = sanitize_key( $product['menu_slug'] );
		$product['menu_roots']  = array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) $product['menu_roots'] ) ) ) );
		if ( ! empty( $product['menu_slug'] ) && ! in_array( $product['menu_slug'], $product['menu_roots'], true ) ) {
			array_unshift( $product['menu_roots'], $product['menu_slug'] );
		}
		$product['manage_url']  = esc_url_raw( $product['manage_url'] );
		$product['docs_url']    = esc_url_raw( $product['docs_url'] );
		$product['learn_url']   = esc_url_raw( $product['learn_url'] );
		$product['icon']        = sanitize_html_class( $product['icon'] );
		$product['accent']      = sanitize_key( $product['accent'] );
		$product['plan']        = sanitize_key( $product['plan'] );
		if ( ! in_array( $product['plan'], array( '', 'free', 'premium', 'freemium' ), true ) ) {
			$product['plan'] = '';
		}
		$product['tags'] = array_map( 'sanitize_text_field', (array) $product['tags'] );

		if ( empty( $product['manage_url'] ) && ! empty( $product['menu_slug'] ) ) {
			$product['manage_url'] = admin_url( 'admin.php?page=' . $product['menu_slug'] );
		}

		$this->registered_products[ $slug ] = $product;
		$this->resolved_products            = null;
		return true;
	}

	/**
	 * Register the Hub top-level menu early so product menus can be collected
	 * later without changing their native WordPress page registrations.
	 */
	public function register_base_menu() {
		$capability = apply_filters( 'nelx_studio_hub_capability', 'manage_options' );
		$position   = (float) apply_filters( 'nelx_studio_hub_menu_position', 3.15 );

		add_menu_page(
			__( 'Nelx Studio', 'nelx-secure-document-storage' ),
			__( 'Nelx Studio', 'nelx-secure-document-storage' ),
			$capability,
			'nelx-studio',
			array( $this, 'dashboard' ),
			$this->menu_icon(),
			$position
		);

		add_submenu_page(
			'nelx-studio',
			__( 'Dashboard', 'nelx-secure-document-storage' ),
			__( 'Dashboard', 'nelx-secure-document-storage' ),
			$capability,
			'nelx-studio',
			array( $this, 'dashboard' )
		);
	}

	/**
	 * Discover native menus belonging to installed Nelx products and mirror
	 * them under Nelx Studio. This runs after normal plugin menu registration.
	 *
	 * The native pages are deliberately registered by their owning plugins
	 * first. The Hub only removes the original top-level menu from the visible
	 * sidebar; it does not rewrite page slugs, callbacks or capabilities. This
	 * keeps direct links and child pages valid while giving users one Nelx menu.
	 */
	public function integrate_product_menus() {
		global $menu, $submenu;

		$capability         = apply_filters( 'nelx_studio_hub_capability', 'manage_options' );
		$this->menu_groups = array();

		if ( ! isset( $submenu['nelx-studio'] ) || ! is_array( $submenu['nelx-studio'] ) ) {
			$submenu['nelx-studio'] = array();
		}

		foreach ( $this->get_installed_products() as $product ) {
			if ( empty( $product['name'] ) || empty( $product['active'] ) ) {
				continue;
			}

			$root_slug = sanitize_key( $product['menu_slug'] ?? '' );
			if ( '' === $root_slug ) {
				continue;
			}

			/*
			 * A product can own more than one native top-level menu. The primary
			 * menu becomes the clickable product entry; any extra native roots are
			 * represented inside the product flyout. This is how pages such as
			 * DualGuard Setup stay associated with their owning product without
			 * polluting the Nelx Studio product list.
			 */
			$menu_roots = array_values(
				array_unique(
					array_filter(
						array_map( 'sanitize_key', (array) ( $product['menu_roots'] ?? array() ) )
					)
				)
			);
			if ( ! in_array( $root_slug, $menu_roots, true ) ) {
				array_unshift( $menu_roots, $root_slug );
			}

			$root_cap  = $capability;
			$root_item = $this->find_native_top_level_menu( $root_slug, $menu );
			if ( $root_item && ! empty( $root_item[1] ) ) {
				$root_cap = (string) $root_item[1];
			}

			$root_target = $this->product_menu_target( $product );
			if ( '' !== $root_target ) {
				$this->append_hub_menu_link( $product['name'], $root_cap, $root_target );
			}

			$children = array();
			$seen     = array();

			foreach ( $menu_roots as $owned_root ) {
				$owned_item = $this->find_native_top_level_menu( $owned_root, $menu );
				$owned_cap  = $root_cap;
				if ( $owned_item && ! empty( $owned_item[1] ) ) {
					$owned_cap = (string) $owned_item[1];
				}

				/* Extra top-level pages become children of the owning product. */
				if ( $owned_root !== $root_slug && $owned_item && current_user_can( $owned_cap ) ) {
					$owned_title  = isset( $owned_item[0] ) ? wp_strip_all_tags( (string) $owned_item[0] ) : '';
					$owned_target = $this->menu_slug_target( $owned_root );
					if ( '' !== $owned_title && '' !== $owned_target && empty( $seen[ $owned_target ] ) ) {
						$children[] = array(
							'title'  => $owned_title,
							'slug'   => $owned_root,
							'page'   => $this->page_from_menu_slug( $owned_root ),
							'target' => $owned_target,
						);
						$seen[ $owned_target ] = true;
					}
				}

				/*
				 * Capture every native submenu after all admin_menu callbacks have
				 * run. Freemius deliberately adds Account / Contact Us at an extremely
				 * late priority, so the Hub itself runs at PHP_INT_MAX and must not
				 * hard-code or recreate those SDK-owned pages.
				 */
				if ( ! empty( $submenu[ $owned_root ] ) && is_array( $submenu[ $owned_root ] ) ) {
					foreach ( $submenu[ $owned_root ] as $child ) {
						$child_slug = isset( $child[2] ) ? (string) $child[2] : '';
						if ( '' === $child_slug || $child_slug === $owned_root ) {
							continue;
						}

						$child_title  = isset( $child[0] ) ? wp_strip_all_tags( (string) $child[0] ) : '';
						$child_cap    = ! empty( $child[1] ) ? (string) $child[1] : $owned_cap;
						$child_target = $this->menu_slug_target( $child_slug );

						if ( '' === $child_title || '' === $child_target || ! current_user_can( $child_cap ) || ! empty( $seen[ $child_target ] ) ) {
							continue;
						}

						$children[] = array(
							'title'  => $child_title,
							'slug'   => $child_slug,
							'page'   => $this->page_from_menu_slug( $child_slug ),
							'target' => $child_target,
						);
						$seen[ $child_target ] = true;
					}
				}
			}

			if ( '' !== $root_target && ! empty( $children ) ) {
				$this->menu_groups[] = array(
					'productSlug' => sanitize_key( $product['slug'] ?? '' ),
					'menuSlug'    => $root_slug,
					'menuRoots'   => $menu_roots,
					'target'      => $root_target,
					'children'    => $children,
				);
			}

			/* Hide all native top-level roots only after their complete trees are captured. */
			foreach ( $menu_roots as $owned_root ) {
				remove_menu_page( $owned_root );
			}
		}

		/* Hub-owned utility pages always remain at the bottom. */
		add_submenu_page(
			'nelx-studio',
			__( 'Settings', 'nelx-secure-document-storage' ),
			__( 'Settings', 'nelx-secure-document-storage' ),
			$capability,
			'nelx-studio-settings',
			array( $this, 'settings' )
		);

		add_submenu_page(
			'nelx-studio',
			__( 'Support', 'nelx-secure-document-storage' ),
			__( 'Support', 'nelx-secure-document-storage' ),
			$capability,
			'nelx-studio-support',
			array( $this, 'support' )
		);
	}

	/**
	 * Find a native top-level admin menu item by slug.
	 *
	 * @param string $slug Menu slug.
	 * @param array  $menu WordPress global menu array.
	 * @return array|null
	 */
	private function find_native_top_level_menu( $slug, $menu ) {
		foreach ( (array) $menu as $menu_item ) {
			if ( isset( $menu_item[2] ) && (string) $menu_item[2] === (string) $slug ) {
				return $menu_item;
			}
		}
		return null;
	}


	/**
	 * Resolve the page query slug represented by a native menu target.
	 *
	 * @param string $slug Native menu slug or route.
	 * @return string
	 */
	private function page_from_menu_slug( $slug ) {
		$slug = (string) $slug;
		if ( false !== strpos( $slug, '?' ) ) {
			$query = wp_parse_url( $slug, PHP_URL_QUERY );
			if ( is_string( $query ) ) {
				parse_str( $query, $args );
				if ( ! empty( $args['page'] ) ) {
					return sanitize_key( (string) $args['page'] );
				}
			}
		}
		return sanitize_key( $slug );
	}

	/**
	 * Keep Nelx Studio highlighted while viewing a native product page or one
	 * of that product's child pages.
	 *
	 * @param string $parent_file Current parent file.
	 * @return string
	 */
	public function filter_parent_file( $parent_file ) {
		$current_page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $current_page ) {
			return $parent_file;
		}

		foreach ( $this->menu_groups as $group ) {
			if ( $current_page === $group['menuSlug'] ) {
				return 'nelx-studio';
			}
			foreach ( (array) $group['children'] as $child ) {
				if ( $current_page === sanitize_key( $child['page'] ?? $child['slug'] ?? '' ) ) {
					return 'nelx-studio';
				}
			}
		}

		return $parent_file;
	}

	/**
	 * Highlight the owning Nelx product when one of its third-level pages is
	 * open. The actual child is highlighted by the lightweight menu script.
	 *
	 * @param string|null $submenu_file Current submenu file.
	 * @param string|null $parent_file Current parent file.
	 * @return string|null
	 */
	public function filter_submenu_file( $submenu_file, $parent_file ) {
		if ( 'nelx-studio' !== $parent_file ) {
			return $submenu_file;
		}

		$current_page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $current_page ) {
			return $submenu_file;
		}

		foreach ( $this->menu_groups as $group ) {
			if ( $current_page === $group['menuSlug'] ) {
				return $group['target'];
			}
			foreach ( (array) $group['children'] as $child ) {
				if ( $current_page === sanitize_key( $child['page'] ?? $child['slug'] ?? '' ) ) {
					return $group['target'];
				}
			}
		}

		return $submenu_file;
	}

	/**
	 * Add a direct link to the Nelx Studio submenu without creating a synthetic
	 * WordPress page. Duplicate targets are ignored.
	 *
	 * @param string $title Menu label.
	 * @param string $capability Required capability.
	 * @param string $target Admin target.
	 */
	private function append_hub_menu_link( $title, $capability, $target ) {
		global $submenu;

		foreach ( (array) ( $submenu['nelx-studio'] ?? array() ) as $existing ) {
			if ( isset( $existing[2] ) && (string) $existing[2] === (string) $target ) {
				return;
			}
		}

		$submenu['nelx-studio'][] = array( // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			$title,
			$capability,
			$target,
		);
	}

	/**
	 * Convert a native menu slug into a link target suitable for $submenu.
	 *
	 * @param string $slug Native menu slug or admin route.
	 * @return string
	 */
	private function menu_slug_target( $slug ) {
		$slug = trim( (string) $slug );
		if ( '' === $slug ) {
			return '';
		}

		if ( preg_match( '#^https?://#i', $slug ) ) {
			return esc_url_raw( $slug );
		}

		if ( false !== strpos( $slug, '.php' ) || false !== strpos( $slug, '?' ) ) {
			return $slug;
		}

		return 'admin.php?page=' . rawurlencode( $slug );
	}

	/**
	 * Return a direct admin-menu target for a product.
	 *
	 * @param array $product Product data.
	 * @return string
	 */
	private function product_menu_target( $product ) {
		if ( ! empty( $product['menu_slug'] ) ) {
			return $this->menu_slug_target( $product['menu_slug'] );
		}

		if ( empty( $product['manage_url'] ) ) {
			return '';
		}

		$admin = admin_url();
		if ( 0 === strpos( $product['manage_url'], $admin ) ) {
			return ltrim( substr( $product['manage_url'], strlen( $admin ) ), '/' );
		}

		return $product['manage_url'];
	}

	/**
	 * Custom monochrome sidebar icon.
	 *
	 * @return string
	 */
	private function menu_icon() {
		$icon_path = NELX_HUB_DIR . 'assets/images/nelx-admin-menu-icon.svg';

		if ( ! is_readable( $icon_path ) ) {
			return 'dashicons-admin-plugins';
		}

		$svg = file_get_contents( $icon_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $svg ) {
			return 'dashicons-admin-plugins';
		}

		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}

	/**
	 * Enqueue Hub assets only on Hub pages.
	 *
	 * @param string $hook Current admin hook.
	 */
	public function assets( $hook ) {
		/*
		 * The product flyout belongs to the WordPress admin navigation, so its
		 * lightweight assets must be available on every admin screen. Full Hub
		 * dashboard assets remain limited to Nelx Studio pages.
		 */
		if ( ! empty( $this->menu_groups ) ) {
			wp_enqueue_style( 'nelx-hub-menu', NELX_HUB_URL . 'assets/css/menu.css', array(), NELX_HUB_VERSION );
			wp_enqueue_script( 'nelx-hub-menu', NELX_HUB_URL . 'assets/js/menu.js', array(), NELX_HUB_VERSION, true );
			wp_localize_script(
				'nelx-hub-menu',
				'nelxHubMenu',
				array(
					'groups' => array_values( $this->menu_groups ),
				)
			);
		}

		if ( false === strpos( (string) $hook, 'nelx-studio' ) ) {
			return;
		}

		wp_enqueue_style( 'dashicons' );
		wp_enqueue_style( 'nelx-hub-admin', NELX_HUB_URL . 'assets/css/admin.css', array(), NELX_HUB_VERSION );
		wp_enqueue_script( 'nelx-hub-admin', NELX_HUB_URL . 'assets/js/admin.js', array(), NELX_HUB_VERSION, true );
		wp_localize_script(
			'nelx-hub-admin',
			'nelxHub',
			array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( 'nelx_hub_refresh_updates' ),
				'updateNonce'   => wp_create_nonce( 'nelx_hub_update_plugin' ),
				'activateNonce' => wp_create_nonce( 'nelx_hub_activate_plugin' ),
				'autoCheck'     => (bool) $this->options()['update_checks'],
				'i18n'          => array(
					'button'         => __( 'Check for Updates', 'nelx-secure-document-storage' ),
					'error'          => __( 'Update check failed. Please try again.', 'nelx-secure-document-storage' ),
					'updateNow'      => __( 'Update now', 'nelx-secure-document-storage' ),
					'updating'       => __( 'Updating…', 'nelx-secure-document-storage' ),
					'updateFailed'   => __( 'The plugin update could not be completed.', 'nelx-secure-document-storage' ),
					'activate'       => __( 'Activate', 'nelx-secure-document-storage' ),
					'activating'     => __( 'Activating…', 'nelx-secure-document-storage' ),
					'activateFailed' => __( 'The plugin could not be activated.', 'nelx-secure-document-storage' ),
					'active'         => __( 'Active', 'nelx-secure-document-storage' ),
					'inactive'       => __( 'Inactive', 'nelx-secure-document-storage' ),
					'manage'         => __( 'Manage', 'nelx-secure-document-storage' ),
					'viewPlugins'    => __( 'View Plugins', 'nelx-secure-document-storage' ),
				),
			)
		);
	}

	/** Register Hub settings. */
	public function register_settings() {
		register_setting(
			'nelx_hub',
			'nelx_hub_options',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_options' ),
				'default'           => array(),
			)
		);
	}

	/**
	 * Sanitize Hub settings.
	 *
	 * @param array $input Raw options.
	 * @return array
	 */
	public function sanitize_options( $input ) {
		$input = is_array( $input ) ? $input : array();
		return array(
			'show_discovery' => ! empty( $input['show_discovery'] ) ? 1 : 0,
			'update_checks'  => ! empty( $input['update_checks'] ) ? 1 : 0,
		);
	}

	/** @return array */
	private function options() {
		return wp_parse_args(
			get_option( 'nelx_hub_options', array() ),
			array(
				'show_discovery' => 1,
				'update_checks'  => 1,
			)
		);
	}

	/**
	 * Known real Nelx Studio products used for fallback detection/discovery.
	 *
	 * Integrated plugins should register their own metadata through
	 * nelx_studio_register_product(). The catalog is only a fallback for plugins
	 * that have not yet been updated to the shared Hub API.
	 *
	 * @return array<string,array>
	 */
	private function catalog() {
		$products_url = 'https://nelxstudio.com/products/';
		$catalog      = array(
			'nelx-secure-document-storage' => array(
				'name'        => 'Secure Documents Storage',
				'description' => 'Protect and manage important documents with controlled access.',
				'match'       => array( 'nelx secure document storage', 'secure document storage', 'nelx-secure-document-storage' ),
				'menu_slug'   => 'nelx-secure-document-storage',
				'icon'        => 'dashicons-media-document',
				'accent'      => 'blue',
				'plan'        => 'free',
				'tags'        => array( 'Documents', 'Access Control', 'Secure Storage' ),
				'learn_url'   => 'https://nelxstudio.com/nelx-secure-document-storage/',
			),
			'nelx-jfb-email-templates' => array(
				'name'        => 'JFB Email Templates',
				'description' => 'Create and manage advanced email workflows for JetFormBuilder.',
				'match'       => array( 'nelx jfb email template', 'jfb email template', 'nelx-jfb-email-template' ),
				'menu_slug'   => 'nelx-jfb-email-settings',
				'icon'        => 'dashicons-email-alt2',
				'accent'      => 'green',
				'plan'        => 'freemium',
				'tags'        => array( 'Email', 'JetFormBuilder', 'Workflows' ),
				'learn_url'   => 'https://nelxstudio.com/nelx-jfb-email-template/',
			),
			'nelx-dualguard-pro' => array(
				'name'        => 'DualGuard Pro',
				'description' => 'Two-factor authentication, login protection and WordPress security controls.',
				'match'       => array( 'nelx dualguard pro', 'dualguard pro', 'nelx-dualguard-pro' ),
				'menu_slug'   => 'nelx-dualguard-pro-settings',
				'menu_roots'  => array( 'nelx-dualguard-pro-settings', 'nelx_dgp_setup' ),
				'icon'        => 'dashicons-shield-alt',
				'accent'      => 'navy',
				'plan'        => 'premium',
				'tags'        => array( 'Security', '2FA', 'Login Protection' ),
				'learn_url'   => 'https://nelxstudio.com/nelx-dualguard-pro',
			),
			'nelx-notification-system' => array(
				'name'        => 'Nelx Notification System',
				'description' => 'Real-time WordPress notifications with reusable bell presets and integrations.',
				'match'       => array( 'nelx notification system', 'nelx-notification-system' ),
				'menu_slug'   => 'nelx-notification-settings',
				'icon'        => 'dashicons-bell',
				'accent'      => 'purple',
				'plan'        => '',
				'tags'        => array( 'Notifications', 'Alerts', 'Presets' ),
				'learn_url'   => $products_url,
			),
			'nelx-automation-system' => array(
				'name'        => 'Nelx Automation System',
				'description' => 'Build scheduled and triggered WordPress automation workflows.',
				'match'       => array( 'nelx automation system', 'nelx-automation-system' ),
				'menu_slug'   => 'nelx-automation',
				'icon'        => 'dashicons-admin-generic',
				'accent'      => 'orange',
				'plan'        => '',
				'tags'        => array( 'Automation', 'Workflows', 'Actions' ),
				'learn_url'   => $products_url,
			),
			'nelx-jetappointments' => array(
				'name'        => 'Nelx JetAppointments Frontend Manager',
				'description' => 'Frontend appointment, schedule and provider management for JetAppointments.',
				'match'       => array( 'nelx jetappointments frontend manager', 'nelx-jetappt-frontend', 'jetappointments frontend manager' ),
				'menu_slug'   => 'nelx-jetappt-settings',
				'icon'        => 'dashicons-calendar-alt',
				'accent'      => 'cyan',
				'plan'        => '',
				'tags'        => array( 'Appointments', 'Schedules', 'Frontend' ),
				'learn_url'   => $products_url,
			),
		);

		return (array) apply_filters( 'nelx_studio_hub_catalog', $catalog );
	}

	/**
	 * Build a normalized product record.
	 *
	 * @param string $slug Product slug.
	 * @param array  $product Product metadata.
	 * @param array  $header Installed plugin headers.
	 * @param string $plugin_file Plugin basename.
	 * @return array
	 */
	private function normalize_product( $slug, $product, $header, $plugin_file ) {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$active = $plugin_file ? is_plugin_active( $plugin_file ) : false;
		if ( is_multisite() && $plugin_file && function_exists( 'is_plugin_active_for_network' ) ) {
			$active = $active || is_plugin_active_for_network( $plugin_file );
		}

		$product['slug']        = $slug;
		$product['plugin_file'] = $plugin_file;
		$product['version']     = ! empty( $header['Version'] ) ? $header['Version'] : ( $product['version'] ?? '' );
		$product['installed']   = true;
		$product['active']      = (bool) $active;
		$product['plan']        = in_array( $product['plan'] ?? '', array( 'free', 'premium', 'freemium' ), true ) ? $product['plan'] : '';
		$product['menu_slug']   = sanitize_key( $product['menu_slug'] ?? '' );
		$product['menu_roots']  = array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) ( $product['menu_roots'] ?? array() ) ) ) ) );
		if ( $product['menu_slug'] && ! in_array( $product['menu_slug'], $product['menu_roots'], true ) ) {
			array_unshift( $product['menu_roots'], $product['menu_slug'] );
		}

		if ( empty( $product['manage_url'] ) && ! empty( $product['menu_slug'] ) ) {
			$product['manage_url'] = admin_url( 'admin.php?page=' . $product['menu_slug'] );
		}

		return $product;
	}

	/**
	 * Get installed Nelx products from explicit registration + plugin headers.
	 *
	 * @return array<string,array>
	 */
	private function get_installed_products() {
		if ( null !== $this->resolved_products ) {
			return $this->resolved_products;
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$catalog = $this->catalog();
		$plugins = get_plugins();
		$results = array();

		/* Explicit registration wins. Catalog data only fills missing fields. */
		foreach ( $this->registered_products as $slug => $registered ) {
			$fallback    = $catalog[ $slug ] ?? array();
			$product     = array_merge( $fallback, $registered );
			$plugin_file = $product['plugin_file'] ?? '';
			$header      = $plugin_file && isset( $plugins[ $plugin_file ] ) ? $plugins[ $plugin_file ] : array();
			$results[ $slug ] = $this->normalize_product( $slug, $product, $header, $plugin_file );
		}

		/* Auto-detect Nelx plugins that do not yet register with the API. */
		foreach ( $plugins as $plugin_file => $header ) {
			$haystack = strtolower( $plugin_file . ' ' . ( $header['Name'] ?? '' ) . ' ' . ( $header['TextDomain'] ?? '' ) );

			foreach ( $catalog as $slug => $item ) {
				if ( isset( $results[ $slug ] ) ) {
					continue;
				}

				$matched = false;
				foreach ( (array) ( $item['match'] ?? array() ) as $needle ) {
					if ( '' !== $needle && false !== strpos( $haystack, strtolower( $needle ) ) ) {
						$matched = true;
						break;
					}
				}

				if ( ! $matched ) {
					continue;
				}

				$results[ $slug ] = $this->normalize_product(
					$slug,
					array(
						'name'        => $item['name'] ?? ( $header['Name'] ?? $slug ),
						'version'     => $header['Version'] ?? '',
						'description' => $item['description'] ?? ( $header['Description'] ?? '' ),
						'menu_slug'   => $item['menu_slug'] ?? '',
						'menu_roots'  => $item['menu_roots'] ?? array(),
						'manage_url'  => '',
						'docs_url'    => $item['docs_url'] ?? '',
						'learn_url'   => $item['learn_url'] ?? 'https://nelxstudio.com/products/',
						'icon'        => $item['icon'] ?? 'dashicons-admin-plugins',
						'accent'      => $item['accent'] ?? 'blue',
						'plan'        => $item['plan'] ?? '',
						'tags'        => $item['tags'] ?? array(),
					),
					$header,
					$plugin_file
				);
				break;
			}
		}

		uasort(
			$results,
			static function( $a, $b ) {
				return strcasecmp( $a['name'], $b['name'] );
			}
		);

		$this->resolved_products = $results;
		return $results;
	}

	/** @return array */
	private function get_update_data() {
		$updates = get_site_transient( 'update_plugins' );
		return is_object( $updates ) && isset( $updates->response ) && is_array( $updates->response ) ? $updates->response : array();
	}

	/**
	 * Count updates for installed Nelx products only.
	 *
	 * @param array $products Installed products.
	 * @return int
	 */
	private function nelx_update_count( $products ) {
		$updates = $this->get_update_data();
		$count   = 0;
		foreach ( $products as $product ) {
			$file = $product['plugin_file'] ?? '';
			if ( $file && isset( $updates[ $file ] ) ) {
				$count++;
			}
		}
		return $count;
	}

	/** Render Dashboard. */
	public function dashboard() {
		if ( ! current_user_can( apply_filters( 'nelx_studio_hub_capability', 'manage_options' ) ) ) {
			return;
		}

		$products     = $this->get_installed_products();
		$catalog      = $this->catalog();
		$options      = $this->options();
		$updates      = $this->get_update_data();
		$update_count = $this->nelx_update_count( $products );
		$discover     = array_diff_key( $catalog, $products );
		$support_url  = 'https://nelxstudio.com/support/';
		?>
		<div class="wrap nelx-hub">
			<header class="nelx-hero">
				<div class="nelx-hero-copy">
					<div class="nelx-title-row">
						<div>
							<div class="nelx-title-line"><img class="nelx-brand-wordmark" src="<?php echo esc_url( NELX_HUB_URL . 'assets/images/nelx-studio-logo-horizontal.svg' ); ?>" alt="<?php esc_attr_e( 'Nelx Studio', 'nelx-secure-document-storage' ); ?>" /><span class="nelx-version-pill">v<?php echo esc_html( NELX_HUB_VERSION ); ?></span></div>
							<p><?php esc_html_e( 'Powerful WordPress plugins to help you build, automate and grow — all in one place.', 'nelx-secure-document-storage' ); ?></p>
						</div>
					</div>
					<nav class="nelx-quick-links" aria-label="<?php esc_attr_e( 'Hub quick links', 'nelx-secure-document-storage' ); ?>">
						<a href="#nelx-products"><span class="dashicons dashicons-admin-plugins"></span><?php esc_html_e( 'Manage your plugins', 'nelx-secure-document-storage' ); ?></a>
						<a href="#nelx-discover"><span class="nelx-quick-icon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><circle cx="12" cy="12" r="8.25"></circle><path d="M14.9 9.1 13.2 13.2 9.1 14.9l1.7-4.1 4.1-1.7Z"></path></svg></span><?php esc_html_e( 'Discover new tools', 'nelx-secure-document-storage' ); ?></a>
						<a href="<?php echo esc_url( $support_url ); ?>" target="_blank" rel="noopener noreferrer"><span class="dashicons dashicons-sos"></span><?php esc_html_e( 'Get support', 'nelx-secure-document-storage' ); ?></a>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=nelx-studio-support' ) ); ?>"><span class="dashicons dashicons-media-document"></span><?php esc_html_e( 'Access documentation', 'nelx-secure-document-storage' ); ?></a>
					</nav>
				</div>
				<div class="nelx-hero-art" aria-hidden="true">
					<div class="nelx-orb nelx-orb-a"></div><div class="nelx-orb nelx-orb-b"></div><div class="nelx-orb nelx-orb-c"></div>
					<div class="nelx-floating-icon nelx-floating-one"><span class="dashicons dashicons-portfolio"></span></div>
					<div class="nelx-floating-icon nelx-floating-two"><span class="dashicons dashicons-chart-line"></span></div>
					<div class="nelx-brand-tile"><img src="<?php echo esc_url( NELX_HUB_URL . 'assets/images/nelx-studio-fav.svg' ); ?>" alt="" /></div>
				</div>
			</header>

			<section class="nelx-stats" aria-label="<?php esc_attr_e( 'Nelx Studio overview', 'nelx-secure-document-storage' ); ?>">
				<div class="nelx-stat nelx-stat-green"><span class="nelx-stat-icon dashicons dashicons-screenoptions"></span><div><strong><?php echo esc_html( count( $products ) ); ?></strong><span><?php esc_html_e( 'Nelx plugins installed', 'nelx-secure-document-storage' ); ?></span></div></div>
				<div class="nelx-stat nelx-stat-orange"><span class="nelx-stat-icon dashicons dashicons-update"></span><div><strong id="nelx-update-count"><?php echo esc_html( $update_count ); ?></strong><span id="nelx-update-label"><?php echo esc_html( 1 === $update_count ? __( 'Update available', 'nelx-secure-document-storage' ) : __( 'Updates available', 'nelx-secure-document-storage' ) ); ?></span></div></div>
				<div class="nelx-stat nelx-stat-blue"><span class="nelx-stat-icon dashicons dashicons-download"></span><div><strong><?php echo esc_html( count( $discover ) ); ?></strong><span><?php esc_html_e( 'More plugins to explore', 'nelx-secure-document-storage' ); ?></span></div></div>
				<a class="nelx-stat nelx-stat-support" href="<?php echo esc_url( $support_url ); ?>" target="_blank" rel="noopener noreferrer"><span class="nelx-stat-icon dashicons dashicons-sos"></span><div><strong><?php esc_html_e( 'Get Support', 'nelx-secure-document-storage' ); ?></strong><span><?php esc_html_e( 'Docs, tutorials and help', 'nelx-secure-document-storage' ); ?></span></div><span class="dashicons dashicons-arrow-right-alt2"></span></a>
			</section>

			<section id="nelx-products" class="nelx-section">
				<div class="nelx-section-head">
					<div><h2><?php esc_html_e( 'Your Nelx Products', 'nelx-secure-document-storage' ); ?></h2><p><?php esc_html_e( 'These are the Nelx plugins installed on this site.', 'nelx-secure-document-storage' ); ?></p></div>
					<div class="nelx-head-actions">
						<?php if ( $options['update_checks'] && current_user_can( 'update_plugins' ) ) : ?><button type="button" class="nelx-btn nelx-btn-outline" id="nelx-check-updates"><span class="dashicons dashicons-update"></span><span class="nelx-check-label"><?php esc_html_e( 'Check for Updates', 'nelx-secure-document-storage' ); ?></span></button><?php endif; ?>
					</div>
				</div>
				<div id="nelx-update-feedback" class="nelx-update-feedback" role="status" aria-live="polite" hidden></div>

				<?php if ( empty( $products ) ) : ?>
					<div class="nelx-empty"><span class="dashicons dashicons-admin-plugins"></span><h3><?php esc_html_e( 'No Nelx products detected yet', 'nelx-secure-document-storage' ); ?></h3><p><?php esc_html_e( 'Install a Nelx Studio plugin or register one through the Hub API and it will appear here.', 'nelx-secure-document-storage' ); ?></p></div>
				<?php else : ?>
					<div class="nelx-product-grid">
						<?php foreach ( $products as $product ) :
							$file       = $product['plugin_file'] ?? '';
							$has_update = $file && isset( $updates[ $file ] );
							$accent     = ! empty( $product['accent'] ) ? $product['accent'] : 'blue';
							$plan       = ! empty( $product['plan'] ) ? $product['plan'] : '';
							$active     = ! empty( $product['active'] );
							$manage_url = $active && ! empty( $product['manage_url'] ) ? $product['manage_url'] : admin_url( 'plugins.php' );
							?>
							<article class="nelx-product-card nelx-accent-<?php echo esc_attr( $accent ); ?>" data-product-slug="<?php echo esc_attr( $product['slug'] ); ?>">
								<?php if ( $plan ) : ?><span class="nelx-license-badge nelx-license-<?php echo esc_attr( $plan ); ?>"><?php echo esc_html( ucfirst( $plan ) ); ?></span><?php endif; ?>
								<div class="nelx-product-icon"><span class="dashicons <?php echo esc_attr( $product['icon'] ); ?>"></span></div>
								<h3><?php echo esc_html( $product['name'] ); ?></h3>
								<p><?php echo esc_html( $product['description'] ); ?></p>
								<div class="nelx-product-meta">
									<span class="nelx-version-small" data-product-version="<?php echo esc_attr( $product['slug'] ); ?>">v<?php echo esc_html( $product['version'] ?: '—' ); ?></span>
									<span class="nelx-activity <?php echo $active ? 'is-active' : 'is-inactive'; ?>"><span class="dashicons <?php echo $active ? 'dashicons-yes-alt' : 'dashicons-marker'; ?>"></span><span class="nelx-activity-label"><?php echo esc_html( $active ? __( 'Active', 'nelx-secure-document-storage' ) : __( 'Inactive', 'nelx-secure-document-storage' ) ); ?></span></span>
									<span class="nelx-status <?php echo $has_update ? 'is-update' : 'is-current'; ?>" data-update-status="<?php echo esc_attr( $product['slug'] ); ?>"><span class="dashicons <?php echo $has_update ? 'dashicons-warning' : 'dashicons-yes-alt'; ?>"></span><span class="nelx-status-label"><?php echo esc_html( $has_update ? __( 'Update available', 'nelx-secure-document-storage' ) : __( 'Up to date', 'nelx-secure-document-storage' ) ); ?></span></span>
								</div>
								<div class="nelx-manage-row">
									<a class="nelx-btn nelx-btn-primary" data-nelx-manage-link href="<?php echo esc_url( $manage_url ); ?>"><span class="dashicons dashicons-admin-generic"></span><span class="nelx-manage-label"><?php echo esc_html( $active ? __( 'Manage', 'nelx-secure-document-storage' ) : __( 'View Plugins', 'nelx-secure-document-storage' ) ); ?></span></a>
									<?php if ( current_user_can( 'activate_plugins' ) && $file ) : ?><button type="button" class="nelx-btn nelx-btn-activate" data-nelx-activate-plugin data-plugin-file="<?php echo esc_attr( $file ); ?>" data-product-slug="<?php echo esc_attr( $product['slug'] ); ?>" <?php echo $active ? 'hidden' : ''; ?>><span class="dashicons dashicons-controls-play"></span><span class="nelx-activate-button-label"><?php esc_html_e( 'Activate', 'nelx-secure-document-storage' ); ?></span></button><?php endif; ?>
									<?php if ( current_user_can( 'update_plugins' ) && $file ) : ?><button type="button" class="nelx-btn nelx-btn-update" data-nelx-update-plugin data-plugin-file="<?php echo esc_attr( $file ); ?>" data-product-slug="<?php echo esc_attr( $product['slug'] ); ?>" <?php echo $has_update ? '' : 'hidden'; ?>><span class="dashicons dashicons-update"></span><span class="nelx-update-button-label"><?php esc_html_e( 'Update now', 'nelx-secure-document-storage' ); ?></span></button><?php endif; ?>
								</div>
							</article>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</section>

			<?php if ( $options['show_discovery'] && ! empty( $discover ) ) : ?>
			<section id="nelx-discover" class="nelx-section nelx-discover-section">
				<div class="nelx-section-head"><div><h2><?php esc_html_e( 'Explore More Nelx Products', 'nelx-secure-document-storage' ); ?></h2><p><?php esc_html_e( 'Discover other Nelx Studio plugins that are not installed on this site.', 'nelx-secure-document-storage' ); ?></p></div><a class="nelx-view-all" href="https://nelxstudio.com/products/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View All Products', 'nelx-secure-document-storage' ); ?><span class="dashicons dashicons-arrow-right-alt"></span></a></div>
				<div class="nelx-discovery-grid">
					<?php foreach ( array_slice( $discover, 0, 3, true ) as $slug => $item ) :
						$discover_plan = ! empty( $item['plan'] ) && in_array( $item['plan'], array( 'free', 'premium', 'freemium' ), true ) ? $item['plan'] : '';
						?>
						<article class="nelx-discovery-card nelx-accent-<?php echo esc_attr( $item['accent'] ?? 'blue' ); ?>">
							<?php if ( $discover_plan ) : ?><span class="nelx-license-badge nelx-license-<?php echo esc_attr( $discover_plan ); ?>"><?php echo esc_html( ucfirst( $discover_plan ) ); ?></span><?php endif; ?>
							<div class="nelx-discovery-copy">
								<div class="nelx-discovery-heading"><div class="nelx-product-icon"><span class="dashicons <?php echo esc_attr( $item['icon'] ); ?>"></span></div><div><h3><?php echo esc_html( $item['name'] ); ?></h3><p><?php echo esc_html( $item['description'] ); ?></p></div></div>
								<div class="nelx-tags"><?php foreach ( array_slice( (array) $item['tags'], 0, 3 ) as $tag ) : ?><span><?php echo esc_html( $tag ); ?></span><?php endforeach; ?></div>
							</div>
							<div class="nelx-discovery-art"><span class="dashicons <?php echo esc_attr( $item['icon'] ); ?>"></span></div>
							<div class="nelx-discovery-actions"><a class="nelx-btn nelx-btn-soft" href="<?php echo esc_url( $item['learn_url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Learn More', 'nelx-secure-document-storage' ); ?></a><a class="nelx-btn nelx-btn-primary" href="<?php echo esc_url( $item['learn_url'] ); ?>" target="_blank" rel="noopener noreferrer"><span class="dashicons dashicons-download"></span><?php esc_html_e( 'Get Plugin', 'nelx-secure-document-storage' ); ?></a></div>
						</article>
					<?php endforeach; ?>
				</div>
			</section>
			<?php endif; ?>
		</div>
		<?php
	}

	/** Render Settings. */
	public function settings() {
		if ( ! current_user_can( apply_filters( 'nelx_studio_hub_capability', 'manage_options' ) ) ) {
			return;
		}
		$o = $this->options();
		?>
		<div class="wrap nelx-hub nelx-inner-page">
			<div class="nelx-inner-hero"><img src="<?php echo esc_url( NELX_HUB_URL . 'assets/images/nelx-studio-fav.svg' ); ?>" alt="" /><div><span class="nelx-kicker">NELX STUDIO</span><h1><?php esc_html_e( 'Settings', 'nelx-secure-document-storage' ); ?></h1><p><?php esc_html_e( 'Hub-wide preferences. Product-specific settings remain inside each product.', 'nelx-secure-document-storage' ); ?></p></div></div>
			<form method="post" action="options.php" class="nelx-settings-card">
				<?php settings_fields( 'nelx_hub' ); ?>
				<div class="nelx-setting-row"><div><strong><?php esc_html_e( 'Update checking', 'nelx-secure-document-storage' ); ?></strong><small><?php esc_html_e( 'Allow the Hub to surface WordPress plugin update information.', 'nelx-secure-document-storage' ); ?></small></div><label class="nelx-switch"><input type="checkbox" name="nelx_hub_options[update_checks]" value="1" <?php checked( $o['update_checks'], 1 ); ?>><span></span></label></div>
				<div class="nelx-setting-row"><div><strong><?php esc_html_e( 'Product discovery', 'nelx-secure-document-storage' ); ?></strong><small><?php esc_html_e( 'Show the Explore More Nelx Products section on the dashboard.', 'nelx-secure-document-storage' ); ?></small></div><label class="nelx-switch"><input type="checkbox" name="nelx_hub_options[show_discovery]" value="1" <?php checked( $o['show_discovery'], 1 ); ?>><span></span></label></div>
				<?php submit_button( __( 'Save Settings', 'nelx-secure-document-storage' ), 'primary nelx-save-button' ); ?>
			</form>
		</div>
		<?php
	}

	/** Render Support. */
	public function support() {
		if ( ! current_user_can( apply_filters( 'nelx_studio_hub_capability', 'manage_options' ) ) ) {
			return;
		}
		global $wp_version;
		$products = $this->get_installed_products();
		$lines    = array(
			'WordPress: ' . $wp_version,
			'PHP: ' . PHP_VERSION,
			'Nelx Studio Hub: ' . NELX_HUB_VERSION,
			'Installed Nelx products: ' . count( $products ),
		);
		foreach ( $products as $product ) {
			$lines[] = '- ' . $product['name'] . ': ' . ( $product['version'] ?: 'Unknown' );
		}
		$info = implode( "\n", $lines );
		?>
		<div class="wrap nelx-hub nelx-inner-page">
			<div class="nelx-inner-hero"><img src="<?php echo esc_url( NELX_HUB_URL . 'assets/images/nelx-studio-fav.svg' ); ?>" alt="" /><div><span class="nelx-kicker">NELX STUDIO</span><h1><?php esc_html_e( 'Support', 'nelx-secure-document-storage' ); ?></h1><p><?php esc_html_e( 'Documentation, troubleshooting and environment details in one place.', 'nelx-secure-document-storage' ); ?></p></div></div>
			<div class="nelx-support-grid">
				<section class="nelx-panel"><span class="nelx-panel-icon dashicons dashicons-media-document"></span><h2><?php esc_html_e( 'Documentation', 'nelx-secure-document-storage' ); ?></h2><p><?php esc_html_e( 'Open Nelx Studio documentation and product guides.', 'nelx-secure-document-storage' ); ?></p><a class="nelx-btn nelx-btn-outline" href="https://nelxstudio.com/docs/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open Documentation', 'nelx-secure-document-storage' ); ?></a></section>
				<section class="nelx-panel"><span class="nelx-panel-icon dashicons dashicons-sos"></span><h2><?php esc_html_e( 'Get help', 'nelx-secure-document-storage' ); ?></h2><p><?php esc_html_e( 'Use Nelx Studio support when you need help with a product.', 'nelx-secure-document-storage' ); ?></p><a class="nelx-btn nelx-btn-outline" href="https://nelxstudio.com/support/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Contact Support', 'nelx-secure-document-storage' ); ?></a></section>
				<section class="nelx-panel nelx-system-panel"><span class="nelx-panel-icon dashicons dashicons-info-outline"></span><h2><?php esc_html_e( 'System information', 'nelx-secure-document-storage' ); ?></h2><p><?php esc_html_e( 'Copy this when reporting an environment-specific issue.', 'nelx-secure-document-storage' ); ?></p><textarea id="nelx-system-info" readonly rows="9"><?php echo esc_textarea( $info ); ?></textarea><button type="button" class="nelx-btn nelx-btn-primary" id="nelx-copy-info"><span class="dashicons dashicons-clipboard"></span><?php esc_html_e( 'Copy System Info', 'nelx-secure-document-storage' ); ?></button></section>
			</div>
		</div>
		<?php
	}

	/**
	 * Build current update state for the dashboard.
	 *
	 * @return array
	 */
	private function update_state_payload() {
		$this->resolved_products = null;
		$products                = $this->get_installed_products();
		$updates                 = $this->get_update_data();
		$update_count            = $this->nelx_update_count( $products );
		$statuses                = array();

		foreach ( $products as $slug => $product ) {
			$file = $product['plugin_file'] ?? '';
			$statuses[ $slug ] = array(
				'hasUpdate'  => (bool) ( $file && isset( $updates[ $file ] ) ),
				'pluginFile' => $file,
				'version'    => $product['version'] ?? '',
				'active'     => ! empty( $product['active'] ),
				'manageUrl'  => ! empty( $product['manage_url'] ) ? $product['manage_url'] : '',
			);
		}

		$message = 0 === $update_count
			? __( 'All plugins are up to date.', 'nelx-secure-document-storage' )
			: sprintf(
				/* translators: %d: number of available plugin updates. */
				_n( '%d update available.', '%d updates available.', $update_count, 'nelx-secure-document-storage' ),
				$update_count
			);

		return array(
			'count'    => $update_count,
			'message'  => $message,
			'statuses' => $statuses,
		);
	}

	/**
	 * Refresh native WordPress plugin updates without reloading the Hub.
	 */
	public function ajax_refresh_updates() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You are not allowed to check updates.', 'nelx-secure-document-storage' ) ),
				403
			);
		}

		check_ajax_referer( 'nelx_hub_refresh_updates', 'nonce' );
		$force = ! empty( $_POST['force'] );

		if ( $force ) {
			delete_site_transient( 'update_plugins' );
		}
		wp_update_plugins();

		wp_send_json_success( $this->update_state_payload() );
	}

	/**
	 * Update one installed Nelx plugin directly from the Hub.
	 */
	public function ajax_update_plugin() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You are not allowed to update plugins.', 'nelx-secure-document-storage' ) ),
				403
			);
		}

		check_ajax_referer( 'nelx_hub_update_plugin', 'nonce' );
		$plugin_file = isset( $_POST['plugin'] ) ? sanitize_text_field( wp_unslash( $_POST['plugin'] ) ) : '';
		if ( '' === $plugin_file ) {
			wp_send_json_error( array( 'message' => __( 'Invalid plugin.', 'nelx-secure-document-storage' ) ), 400 );
		}

		$allowed = false;
		foreach ( $this->get_installed_products() as $product ) {
			if ( ! empty( $product['plugin_file'] ) && hash_equals( (string) $product['plugin_file'], $plugin_file ) ) {
				$allowed = true;
				break;
			}
		}
		if ( ! $allowed ) {
			wp_send_json_error( array( 'message' => __( 'This plugin is not registered with Nelx Studio.', 'nelx-secure-document-storage' ) ), 403 );
		}

		$updates = $this->get_update_data();
		if ( ! isset( $updates[ $plugin_file ] ) ) {
			wp_send_json_error( array( 'message' => __( 'No update is currently available for this plugin.', 'nelx-secure-document-storage' ) ), 409 );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$result   = $upgrader->upgrade( $plugin_file );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
		}
		if ( false === $result ) {
			$errors = $skin->get_errors();
			$message = is_wp_error( $errors ) && $errors->has_errors()
				? $errors->get_error_message()
				: __( 'The plugin update could not be completed.', 'nelx-secure-document-storage' );
			wp_send_json_error( array( 'message' => $message ), 500 );
		}

		wp_clean_plugins_cache( true );
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();

		$payload            = $this->update_state_payload();
		$payload['message'] = __( 'Plugin updated successfully.', 'nelx-secure-document-storage' );
		wp_send_json_success( $payload );
	}

	/**
	 * Activate one installed Nelx plugin directly from the Hub.
	 */
	public function ajax_activate_plugin() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'You are not allowed to activate plugins.', 'nelx-secure-document-storage' ) ),
				403
			);
		}

		check_ajax_referer( 'nelx_hub_activate_plugin', 'nonce' );
		$plugin_file = isset( $_POST['plugin'] ) ? sanitize_text_field( wp_unslash( $_POST['plugin'] ) ) : '';
		if ( '' === $plugin_file ) {
			wp_send_json_error( array( 'message' => __( 'Invalid plugin.', 'nelx-secure-document-storage' ) ), 400 );
		}

		$allowed = false;
		foreach ( $this->get_installed_products() as $product ) {
			if ( ! empty( $product['plugin_file'] ) && hash_equals( (string) $product['plugin_file'], $plugin_file ) ) {
				$allowed = true;
				break;
			}
		}
		if ( ! $allowed ) {
			wp_send_json_error( array( 'message' => __( 'This plugin is not registered with Nelx Studio.', 'nelx-secure-document-storage' ) ), 403 );
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		if ( is_plugin_active( $plugin_file ) ) {
			$payload            = $this->update_state_payload();
			$payload['message'] = __( 'Plugin is already active.', 'nelx-secure-document-storage' );
			wp_send_json_success( $payload );
		}

		$result = activate_plugin( $plugin_file, '', false, false );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
		}

		wp_clean_plugins_cache( true );
		$payload            = $this->update_state_payload();
		$payload['message'] = __( 'Plugin activated successfully.', 'nelx-secure-document-storage' );
		wp_send_json_success( $payload );
	}

}
