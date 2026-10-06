<?php
/**
 * Elementor data resetter.
 *
 * @package ElementorTypographyManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ETM_Resetter {

	private static $backup_key = '_etm_elementor_data_backup';

	public static function reset_document( $post_id, $selected_groups = array() ) {
		$allowed_groups = array_keys( ETM_Matcher::get_groups() );
		$selected_groups = array_values(
			array_intersect(
				array_map( 'sanitize_key', (array) $selected_groups ),
				$allowed_groups
			)
		);

		if ( empty( $selected_groups ) ) {
			return array(
				'success' => false,
				'error'   => 'No valid groups selected for reset.',
			);
		}

		if ( ETM_Elementor_Helper::is_active_kit( $post_id ) ) {
			return array(
				'success' => false,
				'error'   => 'Active Elementor Kit cannot be reset.',
			);
		}

		$raw_data = get_post_meta( $post_id, '_elementor_data', true );
		if ( ! is_string( $raw_data ) || '' === $raw_data ) {
			return array(
				'success' => false,
				'error'   => 'No Elementor data found.',
			);
		}

		$data = json_decode( $raw_data, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) ) {
			return array(
				'success' => false,
				'error'   => 'Malformed Elementor data.',
			);
		}

		$reset_count = 0;
		$modified_data = self::traverse_and_reset( $data, $selected_groups, $reset_count );

		if ( $reset_count > 0 ) {
			$new_raw_data = wp_json_encode( $modified_data );
			if ( false === $new_raw_data ) {
				return array(
					'success' => false,
					'error'   => 'Unable to encode modified Elementor data.',
				);
			}

			self::create_backup( $post_id, $raw_data );
			update_post_meta( $post_id, '_elementor_data', $new_raw_data );
		}

		return array(
			'success'     => true,
			'reset_count' => $reset_count,
		);
	}

	private static function traverse_and_reset( $data, $selected_groups, &$count ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}

		foreach ( $data as $key => $value ) {
			// Elementor's global-reference map is opaque protected data.
			if ( '__globals__' === $key ) {
				continue;
			}

			$group_id = ETM_Matcher::get_group_from_key( $key );
			if ( $group_id && in_array( $group_id, $selected_groups, true ) ) {
				if ( self::is_explicit_override( $value ) ) {
					// Clear the complete control value atomically.
					$data[ $key ] = '';
					$count++;
				}

				continue;
			}

			if ( is_array( $value ) ) {
				$data[ $key ] = self::traverse_and_reset( $value, $selected_groups, $count );
			}
		}

		return $data;
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

	private static function create_backup( $post_id, $raw_data ) {
		if ( ! get_post_meta( $post_id, self::$backup_key, true ) ) {
			update_post_meta( $post_id, self::$backup_key, $raw_data );
		}
	}
}
