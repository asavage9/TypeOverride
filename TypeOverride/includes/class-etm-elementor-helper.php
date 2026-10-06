<?php
/**
 * Elementor integration helpers.
 *
 * @package ElementorTypographyManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ETM_Elementor_Helper {

	public static function get_active_kit_id() {
		if ( ! class_exists( '\Elementor\Plugin' ) || ! isset( \Elementor\Plugin::$instance ) ) {
			return false;
		}

		$plugin = \Elementor\Plugin::$instance;

		if ( isset( $plugin->kits_manager ) && is_object( $plugin->kits_manager ) ) {
			if ( method_exists( $plugin->kits_manager, 'get_active_id' ) ) {
				return (int) $plugin->kits_manager->get_active_id();
			}

			if ( method_exists( $plugin->kits_manager, 'get_active_kit_id' ) ) {
				return (int) $plugin->kits_manager->get_active_kit_id();
			}
		}

		return false;
	}

	public static function regenerate_css() {
		if ( class_exists( '\Elementor\Core\Files\CSS\CSS_File_Manager' ) ) {
			$manager = \Elementor\Core\Files\CSS\CSS_File_Manager::instance();
			if ( method_exists( $manager, 'clear_cache' ) ) {
				$manager->clear_cache();
				return true;
			}
		}

		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
			$files_manager = \Elementor\Plugin::$instance->files_manager;
			if ( method_exists( $files_manager, 'clear_cache' ) ) {
				$files_manager->clear_cache();
				return true;
			}
		}

		return false;
	}

	public static function is_active_kit( $post_id ) {
		$active_kit_id = self::get_active_kit_id();

		return false !== $active_kit_id && (int) $post_id === (int) $active_kit_id;
	}
}
