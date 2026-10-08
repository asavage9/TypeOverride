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

    // Mock functions
    function get_post_meta( $post_id, $key, $single = false ) {
        global $mock_db;
        if ( ! isset( $mock_db[ $post_id ][ $key ] ) ) return '';
        return $single ? $mock_db[ $post_id ][ $key ] : array( $mock_db[ $post_id ][ $key ] );
    }

    function update_post_meta( $post_id, $key, $value ) {
        global $mock_db;
        $mock_db[ $post_id ][ $key ] = $value;
        return true;
    }

    function current_user_can( $capability ) { return true; }
    function check_admin_referer( $action, $nonce ) { return true; }
    function get_the_title( $post_id ) { return "Mock Doc $post_id"; }
    function sanitize_key( $key ) {
        return strtolower( preg_replace( '/[^a-z0-9_\\-]/', '', (string) $key ) );
    }
    function wp_json_encode( $value ) { return json_encode( $value ); }

    \Elementor\Plugin::$instance = new \Elementor\Plugin();

    // Load classes
    require_once __DIR__ . '/../includes/class-typeoverride-elementor-helper.php';
    require_once __DIR__ . '/../includes/class-typeoverride-matcher.php';
    require_once __DIR__ . '/../includes/class-typeoverride-scanner.php';
    require_once __DIR__ . '/../includes/class-typeoverride-resetter.php';

$mock_db = array();

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

echo "--------------------------------------------------\n";
echo "Tests Completed.\n";
}
