<?php
/**
 * Elementor data resetter.
 *
 * @package TypeOverride
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TypeOverride_Resetter {

	private static $backup_key = '_typeoverride_elementor_data_backup';

	public static function reset_document( $post_id, $selected_groups = array() ) {
		$allowed_groups = array_keys( TypeOverride_Matcher::get_groups() );
		$selected_groups = array_values(
			array_intersect(
				array_map( 'sanitize_key', (array) $selected_groups ),
				$allowed_groups
			)
		);

		if ( empty( $selected_groups ) ) {
			return array(
				'success' => false,
				'error'   => __( 'No valid groups selected for reset.', 'typeoverride' ),
			);
		}

		if ( TypeOverride_Elementor_Helper::is_active_kit( $post_id ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Active Elementor Kit cannot be reset.', 'typeoverride' ),
			);
		}

		$raw_data = get_post_meta( $post_id, '_elementor_data', true );
		if ( ! is_string( $raw_data ) || '' === $raw_data ) {
			return array(
				'success' => false,
				'error'   => __( 'No Elementor data found.', 'typeoverride' ),
			);
		}

		$data = json_decode( $raw_data, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Malformed Elementor data.', 'typeoverride' ),
			);
		}

		$reset_count = 0;
		$modified_data = self::traverse_and_reset( $data, $selected_groups, $reset_count );

		if ( $reset_count > 0 ) {
			$new_raw_data = wp_json_encode( $modified_data );
			if ( false === $new_raw_data ) {
				return array(
					'success' => false,
					'error'   => __( 'Unable to encode modified Elementor data.', 'typeoverride' ),
				);
			}

			$validated_data = json_decode( $new_raw_data, true );
			if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $validated_data ) ) {
				return array(
					'success' => false,
					'error'   => __( 'Unable to validate modified Elementor data. No reset was performed.', 'typeoverride' ),
				);
			}

			if ( self::get_preserved_snapshot( $data, $selected_groups ) !== self::get_preserved_snapshot( $validated_data, $selected_groups ) ) {
				return array(
					'success' => false,
					'error'   => __( 'Modified Elementor data changed protected document fields. No reset was performed.', 'typeoverride' ),
				);
			}

			if ( ! self::create_backup( $post_id, $raw_data ) ) {
				return array(
					'success' => false,
					'error'   => __( 'Unable to create or verify the Elementor data backup. No reset was performed.', 'typeoverride' ),
				);
			}

			$persist_result = self::persist_document( $post_id, $raw_data, $new_raw_data );
			if ( ! $persist_result['success'] ) {
				return $persist_result;
			}
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

			$group_id = TypeOverride_Matcher::get_group_from_key( $key );
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

	private static function get_preserved_snapshot( $data, $selected_groups ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}

		$snapshot = array();
		foreach ( $data as $key => $value ) {
			if ( '__globals__' !== $key ) {
				$group_id = TypeOverride_Matcher::get_group_from_key( $key );
				if ( $group_id && in_array( $group_id, $selected_groups, true ) ) {
					continue;
				}
			}

			$snapshot[ $key ] = is_array( $value ) ? self::get_preserved_snapshot( $value, $selected_groups ) : $value;
		}

		return $snapshot;
	}

	private static function create_backup( $post_id, $raw_data ) {
		if ( metadata_exists( 'post', $post_id, self::$backup_key ) ) {
			$existing_backup = get_post_meta( $post_id, self::$backup_key, true );
			$existing_backup_data = is_string( $existing_backup ) ? json_decode( $existing_backup, true ) : false;

			return is_string( $existing_backup )
				&& '' !== $existing_backup
				&& JSON_ERROR_NONE === json_last_error()
				&& is_array( $existing_backup_data );
		}

		$write_result = update_post_meta( $post_id, self::$backup_key, wp_slash( $raw_data ) );
		$stored_backup = get_post_meta( $post_id, self::$backup_key, true );

		return false !== $write_result
			&& is_string( $stored_backup )
			&& $raw_data === $stored_backup;
	}

	private static function persist_document( $post_id, $raw_data, $new_raw_data ) {
		$write_result = update_post_meta( $post_id, '_elementor_data', wp_slash( $new_raw_data ) );
		$saved_raw_data = get_post_meta( $post_id, '_elementor_data', true );

		if ( false !== $write_result && is_string( $saved_raw_data ) && $new_raw_data === $saved_raw_data ) {
			$validated_saved_data = json_decode( $saved_raw_data, true );
			if ( JSON_ERROR_NONE === json_last_error() && is_array( $validated_saved_data ) ) {
				return array(
					'success'     => true,
					'reset_count' => 0,
				);
			}
		}

		update_post_meta( $post_id, '_elementor_data', wp_slash( $raw_data ) );
		$restored_raw_data = get_post_meta( $post_id, '_elementor_data', true );
		if ( $raw_data !== $restored_raw_data ) {
			return array(
				'success' => false,
				'error'   => __( 'Unable to verify the reset or restore the original Elementor data. No reset was recorded.', 'typeoverride' ),
			);
		}

		return array(
			'success' => false,
			'error'   => __( 'Unable to verify the saved Elementor data. The original data was restored and no reset was recorded.', 'typeoverride' ),
		);
	}
}
