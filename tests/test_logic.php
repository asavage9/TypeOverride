<?php
/**
 * Standalone test script for TypeOverride logic.
 */

namespace Elementor {
    class Breakpoints {
        public function get_breakpoints() {
            return array(
                'tablet'    => array(),
                'mobile'    => array(),
                'widescreen' => array(),
            );
        }
    }

    class Plugin {
        public static $instance = null;
        public $breakpoints;

        public function __construct() {
            $this->breakpoints = new Breakpoints();
        }
    }
}

namespace {
    define( 'ABSPATH', __DIR__ );

    $mock_db = array();
    $mock_update_failures = array();
    $mock_corrupt_next_write = array();

    // Mock functions
    function get_post_meta( $post_id, $key, $single = false ) {
        global $mock_db;
        if ( ! isset( $mock_db[ $post_id ] ) || ! array_key_exists( $key, $mock_db[ $post_id ] ) ) return '';
        return $single ? $mock_db[ $post_id ][ $key ] : array( $mock_db[ $post_id ][ $key ] );
    }

    function metadata_exists( $meta_type, $post_id, $key ) {
        global $mock_db;
        return isset( $mock_db[ $post_id ] ) && array_key_exists( $key, $mock_db[ $post_id ] );
    }

    function mock_stripslashes_deep( $value ) {
        if ( is_array( $value ) ) {
            return array_map( 'mock_stripslashes_deep', $value );
        }

        return is_string( $value ) ? stripslashes( $value ) : $value;
    }

    function mock_addslashes_deep( $value ) {
        if ( is_array( $value ) ) {
            return array_map( 'mock_addslashes_deep', $value );
        }

        return is_string( $value ) ? addslashes( $value ) : $value;
    }

    function update_post_meta( $post_id, $key, $value ) {
        global $mock_db, $mock_update_failures, $mock_corrupt_next_write;
        $write_key = $post_id . '|' . $key;
        if ( ! empty( $mock_update_failures[ $write_key ] ) ) {
            return false;
        }

        $stored_value = mock_stripslashes_deep( $value );
        if ( ! empty( $mock_corrupt_next_write[ $write_key ] ) ) {
            unset( $mock_corrupt_next_write[ $write_key ] );
            $stored_value = '{"corrupt":';
        }

        $mock_db[ $post_id ][ $key ] = $stored_value;
        return true;
    }

    function __( $message, $domain ) { return $message; }
    function current_user_can( $capability ) { return true; }
    function check_admin_referer( $action, $nonce ) { return true; }
    function get_the_title( $post_id ) { return "Mock Doc $post_id"; }
    function sanitize_key( $key ) {
        return strtolower( preg_replace( '/[^a-z0-9_\\-]/', '', (string) $key ) );
    }
    function wp_json_encode( $value ) { return json_encode( $value ); }
    function wp_slash( $value ) { return mock_addslashes_deep( $value ); }

    \Elementor\Plugin::$instance = new \Elementor\Plugin();

    // Load classes
    require_once __DIR__ . '/../includes/class-typeoverride-elementor-helper.php';
    require_once __DIR__ . '/../includes/class-typeoverride-matcher.php';
    require_once __DIR__ . '/../includes/class-typeoverride-scanner.php';
    require_once __DIR__ . '/../includes/class-typeoverride-resetter.php';

echo "Starting Logic Tests (Global Protection Pass)...\n";
echo "--------------------------------------------------\n";

// TEST 1: Atomic Reset of Structured Value
echo "Test 1: Atomic Reset of Structured Value... ";
$structured_data = array(
    'widget_1' => array(
        'settings' => array(
            'typography_font_size' => array(
                'unit'  => 'px',
                'size'  => 32,
                'sizes' => array(),
            ),
        )
    )
);
$mock_db[1]['_elementor_data'] = json_encode( $structured_data );

$res = TypeOverride_Resetter::reset_document( 1, array( 'font_size' ) );
$updated_raw = get_post_meta( 1, '_elementor_data', true );
$updated_data = json_decode( $updated_raw, true );

if ( $updated_data['widget_1']['settings']['typography_font_size'] === '' ) {
    echo "PASS\n";
} else {
    echo "FAIL (Value not atomically reset to empty string)\n";
}

// TEST 2: Responsive Structured Values (Separate Keys)
echo "Test 2: Responsive Structured Values... ";
$responsive_data = array(
    'widget_2' => array(
        'settings' => array(
            'typography_font_size' => array( 'unit' => 'px', 'size' => 20 ),
            'typography_font_size_tablet' => array( 'unit' => 'px', 'size' => 18 ),
            'typography_font_size_mobile' => array( 'unit' => 'px', 'size' => 14 ),
        )
    )
);
$mock_db[2]['_elementor_data'] = json_encode( $responsive_data );

$res = TypeOverride_Resetter::reset_document( 2, array( 'font_size' ) );
$updated_raw = get_post_meta( 2, '_elementor_data', true );
$updated_data = json_decode( $updated_raw, true );

if ( $updated_data['widget_2']['settings']['typography_font_size'] === '' && 
     $updated_data['widget_2']['settings']['typography_font_size_tablet'] === '' && 
     $updated_data['widget_2']['settings']['typography_font_size_mobile'] === '' ) {
    echo "PASS\n";
} else {
    echo "FAIL (Responsive keys not all reset)\n";
}

// TEST 3: False-Positive Keys
echo "Test 3: False-Positive Keys... ";
$false_positive_data = array(
    'widget_3' => array(
        'settings' => array(
            'some_widget_font_size_label_color' => 'red',
            'typography_font_size' => '16px',
        )
    )
);
$mock_db[3]['_elementor_data'] = json_encode( $false_positive_data );

$res = TypeOverride_Resetter::reset_document( 3, array( 'font_size' ) );
$updated_raw = get_post_meta( 3, '_elementor_data', true );
$updated_data = json_decode( $updated_raw, true );

if ( $updated_data['widget_3']['settings']['some_widget_font_size_label_color'] === 'red' && 
     $updated_data['widget_3']['settings']['typography_font_size'] === '' ) {
    echo "PASS\n";
} else {
    echo "FAIL (False-positive was reset or legitimate was not)\n";
}

// TEST 4: Legitimate Prefixes
echo "Test 4: Legitimate Prefixes... ";
$prefix_data = array(
    'widget_4' => array(
        'settings' => array(
            'typography_font_size' => '16px',
            'title_typography_font_size' => '24px',
            'content_typography_font_size' => '14px',
            'third_party_heading_typography_font_size' => '30px',
        )
    )
);
$mock_db[4]['_elementor_data'] = json_encode( $prefix_data );

$res = TypeOverride_Resetter::reset_document( 4, array( 'font_size' ) );
$updated_raw = get_post_meta( 4, '_elementor_data', true );
$updated_data = json_decode( $updated_raw, true );

if ( $updated_data['widget_4']['settings']['typography_font_size'] === '' && 
     $updated_data['widget_4']['settings']['title_typography_font_size'] === '' && 
     $updated_data['widget_4']['settings']['content_typography_font_size'] === '' && 
     $updated_data['widget_4']['settings']['third_party_heading_typography_font_size'] === '' ) {
    echo "PASS\n";
} else {
    echo "FAIL (Some legitimate prefixes were missed)\n";
}

// TEST 5: __globals__ Protection
echo "Test 5: __globals__ Protection... ";
$global_data = array(
    'widget_5' => array(
        'settings' => array(
            'title_typography_font_family' => 'Roboto',
            '__globals__' => array(
                'typography_typography' => 'globals/typography?id=primary',
                'some_future_font_size' => 'globals/typography?id=secondary',
            ),
            'typography_font_size' => '16px',
        )
    )
);
$mock_db[5]['_elementor_data'] = json_encode( $global_data );

// Audit check
$overrides = TypeOverride_Scanner::audit_document( $global_data );
$found_globals = 0;
foreach ( $overrides as $o ) {
    if ( strpos( $o['key'], '__globals__' ) !== false ) {
        $found_globals++;
    }
}

// Reset check
$res = TypeOverride_Resetter::reset_document( 5, array( 'font_family', 'font_size' ) );
$updated_raw = get_post_meta( 5, '_elementor_data', true );
$updated_data = json_decode( $updated_raw, true );

if ( $found_globals === 0 && 
     $updated_data['widget_5']['settings']['title_typography_font_family'] === '' && 
     $updated_data['widget_5']['settings']['typography_font_size'] === '' && 
     $updated_data['widget_5']['settings']['__globals__']['some_future_font_size'] === 'globals/typography?id=secondary' ) {
    echo "PASS\n";
} else {
    echo "FAIL (__globals__ were audited or mutated)\n";
}

// TEST 6: Shared Responsive Context Matching
echo "Test 6: Shared Responsive Context Matching... ";
$context_base = TypeOverride_Matcher::get_breakpoint_from_key( 'title_typography_font_size' );
$context_tablet = TypeOverride_Matcher::get_breakpoint_from_key( 'title_typography_font_size_tablet' );
$context_mobile = TypeOverride_Matcher::get_breakpoint_from_key( 'title_typography_font_size_mobile' );
$context_arbitrary = TypeOverride_Matcher::get_breakpoint_from_key( 'title_typography_font_size_custom' );

if ( false === $context_base && 'tablet' === $context_tablet && 'mobile' === $context_mobile && false === $context_arbitrary ) {
    echo "PASS\n";
} else {
    echo "FAIL (Breakpoint context was not classified canonically)\n";
}

// TEST 7: WordPress Metadata Slash Handling and Complete Backup
echo "Test 7: WordPress Metadata Slash Handling and Complete Backup... ";
$escaped_data = array(
    'widget_7' => array(
        'id'         => 'widget_7',
        'elType'     => 'widget',
        'widgetType' => 'text-editor',
        'settings'   => array(
            'typography_font_size' => '32px',
            'editor'               => 'He said "Hello" and path C:\\Fonts',
        ),
    ),
);
$escaped_raw = json_encode( $escaped_data );
$mock_db[7]['_elementor_data'] = $escaped_raw;

$res = TypeOverride_Resetter::reset_document( 7, array( 'font_size' ) );
$stored_raw = get_post_meta( 7, '_elementor_data', true );
$stored_data = json_decode( $stored_raw, true );
$stored_error = json_last_error();
$backup_raw = get_post_meta( 7, '_typeoverride_elementor_data_backup', true );

if ( $res['success'] && JSON_ERROR_NONE === $stored_error && $escaped_raw === $backup_raw &&
     '' === $stored_data['widget_7']['settings']['typography_font_size'] &&
     $escaped_data['widget_7']['settings']['editor'] === $stored_data['widget_7']['settings']['editor'] ) {
    echo "PASS\n";
} else {
    echo "FAIL (Metadata slashing or backup verification failed)\n";
}

// TEST 8: Invalid Data Is Left Unchanged
echo "Test 8: Invalid Data Is Left Unchanged... ";
$invalid_raw = '{"widget_8":';
$mock_db[8]['_elementor_data'] = $invalid_raw;

$res = TypeOverride_Resetter::reset_document( 8, array( 'font_size' ) );

if ( ! $res['success'] && $invalid_raw === get_post_meta( 8, '_elementor_data', true ) && ! metadata_exists( 'post', 8, '_typeoverride_elementor_data_backup' ) ) {
    echo "PASS\n";
} else {
    echo "FAIL (Invalid data was modified)\n";
}

// TEST 9: Existing Backup Is Preserved
echo "Test 9: Existing Backup Is Preserved... ";
$existing_backup = json_encode( array( 'original' => 'backup-data', 'widget' => array( 'id' => 'preserved' ) ) );
$current_data = array(
    'widget_9' => array(
        'id'       => 'widget_9',
        'settings' => array( 'typography_font_size' => '24px' ),
    ),
);
$mock_db[9]['_elementor_data'] = json_encode( $current_data );
$mock_db[9]['_typeoverride_elementor_data_backup'] = $existing_backup;

$res = TypeOverride_Resetter::reset_document( 9, array( 'font_size' ) );

if ( $res['success'] && $existing_backup === get_post_meta( 9, '_typeoverride_elementor_data_backup', true ) ) {
    echo "PASS\n";
} else {
    echo "FAIL (Existing backup was replaced)\n";
}

// TEST 10: Persistence Verification Restores Original Data
echo "Test 10: Persistence Verification Restores Original Data... ";
$rollback_data = array(
    'container_10' => array(
        'id'       => 'container_10',
        'elType'    => 'container',
        'settings' => array( 'background_color' => '#ffffff' ),
        'elements' => array(
            array(
                'id'         => 'widget_10',
                'elType'     => 'widget',
                'widgetType' => 'heading',
                'settings'   => array( 'typography_font_size' => '20px' ),
            ),
        ),
    ),
);
$rollback_raw = json_encode( $rollback_data );
$mock_db[10]['_elementor_data'] = $rollback_raw;
$mock_corrupt_next_write['10|_elementor_data'] = true;

$res = TypeOverride_Resetter::reset_document( 10, array( 'font_size' ) );

if ( ! $res['success'] && $rollback_raw === get_post_meta( 10, '_elementor_data', true ) &&
     $rollback_raw === get_post_meta( 10, '_typeoverride_elementor_data_backup', true ) ) {
    echo "PASS\n";
} else {
    echo "FAIL (Persistence failure was not rolled back)\n";
}

// TEST 11: Failed Metadata Write Is Not Reported as Success
echo "Test 11: Failed Metadata Write Is Not Reported as Success... ";
$failed_write_data = array(
    'widget_11' => array(
        'settings' => array( 'typography_font_size' => '18px' ),
    ),
);
$failed_write_raw = json_encode( $failed_write_data );
$mock_db[11]['_elementor_data'] = $failed_write_raw;
$mock_update_failures['11|_elementor_data'] = true;

$res = TypeOverride_Resetter::reset_document( 11, array( 'font_size' ) );

if ( ! $res['success'] && $failed_write_raw === get_post_meta( 11, '_elementor_data', true ) ) {
    echo "PASS\n";
} else {
    echo "FAIL (Failed metadata write was reported as successful)\n";
}

// TEST 12: Repeating a Successful Reset Is Idempotent
echo "Test 12: Repeating a Successful Reset Is Idempotent... ";
$repeat_data = array(
    'widget_12' => array(
        'settings' => array( 'typography_font_size' => '16px' ),
    ),
);
$mock_db[12]['_elementor_data'] = json_encode( $repeat_data );

$first_result = TypeOverride_Resetter::reset_document( 12, array( 'font_size' ) );
$after_first_reset = get_post_meta( 12, '_elementor_data', true );
$second_result = TypeOverride_Resetter::reset_document( 12, array( 'font_size' ) );

if ( $first_result['success'] && $second_result['success'] && 0 === $second_result['reset_count'] &&
     $after_first_reset === get_post_meta( 12, '_elementor_data', true ) ) {
    echo "PASS\n";
} else {
    echo "FAIL (Repeated reset changed the document)\n";
}

// TEST 13: Audit Is Read-Only
echo "Test 13: Audit Is Read-Only... ";
$audit_data = array(
    'widget_13' => array(
        'settings' => array(
            'typography_font_size' => '14px',
            'content'              => 'unchanged',
        ),
    ),
);
$audit_before = json_encode( $audit_data );
TypeOverride_Scanner::audit_document( $audit_data );

if ( $audit_before === json_encode( $audit_data ) ) {
    echo "PASS\n";
} else {
    echo "FAIL (Audit mutated document data)\n";
}

echo "--------------------------------------------------\n";
echo "Tests Completed.\n";
}
