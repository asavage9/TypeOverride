<?php
/**
 * Plugin Name: TypeOverride
 * Description: Find and reset local typography overrides in Elementor.
 * Version: 0.2.0
 * Author: Anthony Savage
 * Text Domain: typeoverride
 *
 * @package ElementorTypographyManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'ETM_VERSION' ) ) {
	define( 'ETM_VERSION', '0.2.0' );
}

class ETM_Typography_Manager {

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-etm-elementor-helper.php';
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-etm-matcher.php';
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-etm-scanner.php';
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-etm-resetter.php';
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-etm-admin.php';

		add_action( 'plugins_loaded', array( $this, 'boot' ) );
	}

	public function boot() {
		if ( ! did_action( 'elementor/loaded' ) ) {
			add_action( 'admin_notices', array( $this, 'elementor_inactive_notice' ) );
		}

		new ETM_Admin();
	}

	public function elementor_inactive_notice() {
		if ( did_action( 'elementor/loaded' ) ) {
			return;
		}

		?>
		<div class="notice notice-error">
			<p><strong>TypeOverride</strong> requires Elementor to be installed and active to function.</p>
		</div>
		<?php
	}
}

ETM_Typography_Manager::get_instance();
