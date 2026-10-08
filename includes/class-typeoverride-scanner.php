<?php
/**
 * Elementor data scanner.
 *
 * @package TypeOverride
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TypeOverride_Scanner {

	public static function audit_document( $data, $selected_groups = array() ) {
		$overrides = array();
		self::traverse_and_match( $data, $overrides, $selected_groups );

		return $overrides;
	}

	private static function traverse_and_match( $data, &$overrides, $selected_groups ) {
		if ( ! is_array( $data ) ) {
			return;
		}

		foreach ( $data as $key => $value ) {
			// Elementor's global-reference map is opaque protected data.
			if ( '__globals__' === $key ) {
				continue;
			}

			$group_id = TypeOverride_Matcher::get_group_from_key( $key );
			if ( $group_id ) {
				if (
					( empty( $selected_groups ) || in_array( $group_id, $selected_groups, true ) )
					&& self::is_explicit_override( $value )
				) {
					$overrides[] = array(
						'group'   => $group_id,
						'key'     => $key,
						'value'   => $value,
						'variant' => 'key',
					);
				}

				// A matched control value is atomic; do not inspect its internals.
				continue;
			}

			if ( is_array( $value ) ) {
				self::traverse_and_match( $value, $overrides, $selected_groups );
			}
		}
	}

	private static function is_explicit_override( $value ) {
		if ( null === $value || '' === $value ) {
			return false;
		}

		if ( is_string( $value ) && 0 === strpos( $value, 'global' ) ) {
			return false;
		}

		if ( is_array( $value ) && isset( $value['global'] ) ) {
			return false;
		}

		return true;
	}
}
