<?php
/**
 * Canonical typography setting matcher.
 *
 * @package ElementorTypographyManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ETM_Matcher {

	private static $typography_groups = array(
		'font_family'    => '_font_family',
		'font_size'      => '_font_size',
		'font_weight'    => '_font_weight',
		'text_transform' => '_text_transform',
		'font_style'     => '_font_style',
		'text_decoration'=> '_text_decoration',
		'line_height'    => '_line_height',
		'letter_spacing' => '_letter_spacing',
		'word_spacing'   => '_word_spacing',
	);

	public static function get_group_from_key( $key ) {
		$match = self::get_match_from_key( $key );

		return false === $match ? false : $match['group'];
	}

	/**
	 * Return the registered breakpoint suffix for a matched typography key.
	 *
	 * Base controls return false and responsive controls return their Elementor
	 * breakpoint key. Both results come from the same matcher used by resets.
	 *
	 * @param string $key Elementor setting key.
	 * @return string|false
	 */
	public static function get_breakpoint_from_key( $key ) {
		$match = self::get_match_from_key( $key );

		return false === $match ? false : $match['breakpoint'];
	}

	public static function get_groups() {
		return self::$typography_groups;
	}

	/**
	 * Return the canonical group/breakpoint match for a setting key.
	 *
	 * @param string $key Elementor setting key.
	 * @return array|false
	 */
	private static function get_match_from_key( $key ) {
		if ( ! is_string( $key ) ) {
			return false;
		}

		$breakpoints = self::get_elementor_breakpoints();

		foreach ( self::$typography_groups as $group_id => $identifier ) {
			if ( substr( $key, -strlen( $identifier ) ) === $identifier ) {
				return array(
					'group'      => $group_id,
					'breakpoint' => false,
				);
			}

			foreach ( $breakpoints as $breakpoint_id ) {
				$terminal = $identifier . '_' . $breakpoint_id;
				if ( substr( $key, -strlen( $terminal ) ) === $terminal ) {
					return array(
						'group'      => $group_id,
						'breakpoint' => $breakpoint_id,
					);
				}
			}
		}

		return false;
	}

	private static function get_elementor_breakpoints() {
		if ( ! class_exists( '\Elementor\Plugin' ) || ! isset( \Elementor\Plugin::$instance ) ) {
			return array();
		}

		$breakpoint_manager = \Elementor\Plugin::$instance->breakpoints;
		if ( ! is_object( $breakpoint_manager ) ) {
			return array();
		}

		$breakpoints = array();
		if ( method_exists( $breakpoint_manager, 'get_breakpoints' ) ) {
			$breakpoints = $breakpoint_manager->get_breakpoints();
		} elseif ( method_exists( $breakpoint_manager, 'get_active_breakpoints' ) ) {
			$breakpoints = $breakpoint_manager->get_active_breakpoints();
		}

		return is_array( $breakpoints ) ? array_keys( $breakpoints ) : array();
	}
}
