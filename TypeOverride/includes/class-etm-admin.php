<?php
/**
 * WordPress admin interface.
 *
 * @package ElementorTypographyManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ETM_Admin {

	private $page_slug = 'typeoverride';

	private $version = '0.2.0';

	private $group_labels = array(
		'font_family'     => 'Font Family',
		'font_size'       => 'Font Size',
		'font_weight'     => 'Font Weight',
		'text_transform'  => 'Text Transform',
		'font_style'      => 'Font Style',
		'text_decoration' => 'Text Decoration',
		'line_height'     => 'Line Height',
		'letter_spacing'  => 'Letter Spacing',
		'word_spacing'    => 'Word Spacing',
	);

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function add_menu_page() {
		add_menu_page(
			'TypeOverride',
			'TypeOverride',
			'manage_options',
			$this->page_slug,
			array( $this, 'render_page' ),
			'dashicons-editor-textcolor',
			80
		);
	}

	public function enqueue_assets() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( $this->page_slug !== $page ) {
			return;
		}

		$plugin_file = dirname( __DIR__ ) . '/typeoverride.php';
		wp_enqueue_style(
			'etm-admin',
			plugins_url( 'admin/css/typeoverride-admin.css', $plugin_file ),
			array(),
			$this->version
		);
		wp_enqueue_script(
			'etm-admin',
			plugins_url( 'admin/js/typeoverride-admin.js', $plugin_file ),
			array(),
			$this->version,
			true
		);
	}

	public function handle_actions() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( $this->page_slug !== $page ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'typeoverride' ) );
		}

		$action = isset( $_POST['etm_action'] ) ? sanitize_key( wp_unslash( $_POST['etm_action'] ) ) : '';
		if ( 'reset_overrides' === $action ) {
			$this->process_reset();
		}
	}

	private function process_reset() {
		check_admin_referer( 'etm_reset_action', 'etm_nonce' );

		if ( ! $this->is_elementor_available() ) {
			$results = array(
				'modified_docs' => 0,
				'reset_count'   => 0,
				'errors'        => array( 'Elementor is unavailable. No reset was performed.' ),
			);
			set_transient( 'etm_reset_result', $results, MINUTE_IN_SECONDS );
			wp_safe_redirect( $this->page_url( 'reset', array( 'reset' => 'completed' ) ) );
			exit;
		}

		$submitted_groups = isset( $_POST['reset_groups'] ) ? wp_unslash( $_POST['reset_groups'] ) : array();
		$selected_groups = array_values(
			array_intersect(
				array_map( 'sanitize_key', (array) $submitted_groups ),
				array_keys( ETM_Matcher::get_groups() )
			)
		);

		if ( empty( $selected_groups ) ) {
			$results = array(
				'modified_docs' => 0,
				'reset_count'   => 0,
				'errors'        => array( 'No valid typography groups were selected.' ),
			);
			set_transient( 'etm_reset_result', $results, MINUTE_IN_SECONDS );
			wp_safe_redirect( $this->page_url( 'reset', array( 'reset' => 'completed' ) ) );
			exit;
		}

		$results = array(
			'modified_docs' => 0,
			'reset_count'   => 0,
			'errors'        => array(),
		);

		$query = new WP_Query(
			array(
				'post_type'      => 'any',
				'post_status'    => array( 'publish', 'pending', 'draft', 'future', 'private' ),
				'posts_per_page' => -1,
				'meta_query'     => array(
					array(
						'key'     => '_elementor_data',
						'compare' => 'EXISTS',
					),
				),
			)
		);

		foreach ( $query->posts as $post ) {
			if ( ETM_Elementor_Helper::is_active_kit( $post->ID ) ) {
				continue;
			}

			$result = ETM_Resetter::reset_document( $post->ID, $selected_groups );
			if ( ! $result['success'] ) {
				$results['errors'][] = sprintf(
					'Doc ID %d: %s',
					(int) $post->ID,
					$result['error']
				);
				continue;
			}

			if ( $result['reset_count'] > 0 ) {
				$results['modified_docs']++;
				$results['reset_count'] += $result['reset_count'];
			}
		}

		if ( $results['reset_count'] > 0 ) {
			ETM_Elementor_Helper::regenerate_css();
		}

		set_transient( 'etm_reset_result', $results, MINUTE_IN_SECONDS );
		wp_safe_redirect( $this->page_url( 'reset', array( 'reset' => 'completed' ) ) );
		exit;
	}

	public function render_page() {
		$current_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'audit';
		if ( ! in_array( $current_tab, array( 'audit', 'reset' ), true ) ) {
			$current_tab = 'audit';
		}

		$elementor_available = $this->is_elementor_available();
		?>
		<div class="wrap etm-admin-page">
			<?php $this->render_header( $elementor_available ); ?>
			<?php $this->render_reset_notice(); ?>

			<?php if ( ! $elementor_available ) : ?>
				<?php $this->render_dependency_state(); ?>
			</div>
				<?php
				return;
			endif;
			?>

			<nav class="etm-tabs" aria-label="TypeOverride sections">
				<a href="<?php echo esc_url( $this->page_url( 'audit' ) ); ?>" class="etm-tab <?php echo 'audit' === $current_tab ? 'is-active' : ''; ?>" <?php echo 'audit' === $current_tab ? 'aria-current="page"' : ''; ?>>Audit</a>
				<a href="<?php echo esc_url( $this->page_url( 'reset' ) ); ?>" class="etm-tab <?php echo 'reset' === $current_tab ? 'is-active' : ''; ?>" <?php echo 'reset' === $current_tab ? 'aria-current="page"' : ''; ?>>Reset Overrides</a>
			</nav>

			<main class="etm-content">
				<?php
				if ( 'audit' === $current_tab ) {
					$this->render_audit_tab();
				} else {
					$this->render_reset_tab();
				}
				?>
			</main>
		</div>
		<?php
	}

	private function render_header( $elementor_available ) {
		$status_label = $elementor_available ? 'Elementor detected' : 'Elementor unavailable';
		$status_class = $elementor_available ? 'is-ready' : 'is-unavailable';
		?>
		<header class="etm-header">
			<div class="etm-header-copy">
				<p class="etm-eyebrow">Elementor typography utility</p>
				<h1>TypeOverride</h1>
				<p class="etm-subtitle">Find and reset local typography overrides in Elementor.</p>
			</div>
			<div class="etm-header-meta" aria-label="TypeOverride status">
				<span class="etm-status <?php echo esc_attr( $status_class ); ?>">
					<span class="etm-status-dot" aria-hidden="true"></span>
					<?php echo esc_html( $status_label ); ?>
				</span>
				<span class="etm-version">v<?php echo esc_html( $this->version ); ?></span>
			</div>
		</header>
		<?php
	}

	private function render_reset_notice() {
		if ( ! isset( $_GET['reset'] ) || 'completed' !== sanitize_key( wp_unslash( $_GET['reset'] ) ) ) {
			return;
		}

		$result = get_transient( 'etm_reset_result' );
		if ( ! is_array( $result ) ) {
			return;
		}

		$has_errors  = ! empty( $result['errors'] );
		$has_changes = (int) $result['reset_count'] > 0;
		if ( ! $has_errors ) {
			$state   = 'success';
			$heading = 'Typography overrides reset successfully';
		} elseif ( $has_changes ) {
			$state   = 'warning';
			$heading = 'Reset completed with skipped items';
		} else {
			$state   = 'error';
			$heading = 'Reset could not complete';
		}
		?>
		<section class="etm-result etm-result-<?php echo esc_attr( $state ); ?>" role="<?php echo 'error' === $state ? 'alert' : 'status'; ?>">
			<div class="etm-result-icon" aria-hidden="true"><?php echo 'success' === $state ? '✓' : ( 'warning' === $state ? '!' : '×' ); ?></div>
			<div class="etm-result-body">
				<h2><?php echo esc_html( $heading ); ?></h2>
				<?php if ( $has_errors && $has_changes ) : ?>
					<p>Completed changes are listed below. Review the skipped or failed items before trying again.</p>
				<?php elseif ( $has_errors ) : ?>
					<p>No documents were modified. Review the reported items before trying again.</p>
				<?php else : ?>
					<p>Selected local values were cleared so Elementor can use its available default or global inheritance.</p>
				<?php endif; ?>
				<div class="etm-result-metrics" aria-label="Reset results">
					<div><span>Documents modified</span><strong><?php echo (int) $result['modified_docs']; ?></strong></div>
					<div><span>Overrides reset</span><strong><?php echo (int) $result['reset_count']; ?></strong></div>
				</div>
				<?php if ( $has_errors ) : ?>
					<h3>Skipped or failed items</h3>
					<ul>
						<?php foreach ( $result['errors'] as $error ) : ?>
							<li><?php echo esc_html( $error ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
				<p class="etm-result-footnote">If cached styles remain, regenerate Elementor files/data from Elementor Tools.</p>
			</div>
		</section>
		<?php
		delete_transient( 'etm_reset_result' );
	}

	private function render_dependency_state() {
		?>
		<section class="etm-panel etm-state etm-state-unavailable" role="status" aria-labelledby="etm-dependency-title">
			<div class="etm-state-icon" aria-hidden="true">!</div>
			<div>
				<p class="etm-eyebrow">Dependency check</p>
				<h2 id="etm-dependency-title">Elementor is unavailable</h2>
				<p>TypeOverride requires Elementor to inspect typography overrides. Activate Elementor, then return here to run an audit or reset.</p>
			</div>
		</section>
		<?php
	}

	private function render_audit_tab() {
		$audit = $this->collect_audit_results();
		?>
		<section class="etm-intro" aria-labelledby="etm-audit-title">
			<p class="etm-eyebrow">Audit</p>
			<h2 id="etm-audit-title">What is overriding my global typography?</h2>
			<p>Review explicit local typography values across Elementor documents before deciding what to reset.</p>
		</section>

		<?php if ( $audit['skipped_count'] > 0 ) : ?>
			<section class="etm-state etm-state-warning" role="status" aria-labelledby="etm-audit-warning-title">
				<div class="etm-state-icon" aria-hidden="true">!</div>
				<div>
					<h2 id="etm-audit-warning-title">Audit completed with skipped items</h2>
					<p><?php echo (int) $audit['skipped_count']; ?> document(s) could not be inspected safely, so they were left unchanged.</p>
					<ul>
						<?php foreach ( $audit['skipped_docs'] as $skipped ) : ?>
							<li><?php echo esc_html( $skipped['title'] ); ?> (ID <?php echo (int) $skipped['id']; ?>): <?php echo esc_html( $skipped['reason'] ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			</section>
		<?php endif; ?>

		<?php if ( empty( $audit['documents'] ) ) : ?>
			<section class="etm-panel etm-empty-state" role="status">
				<div class="etm-empty-icon" aria-hidden="true">✓</div>
				<h2>No local typography overrides found</h2>
				<p><?php echo $audit['skipped_count'] > 0 ? 'No overrides were found in the documents that completed the audit.' : 'Elementor typography in the audited content is already inheriting its available global/default settings.'; ?></p>
			</section>
			<?php
			return;
		endif;
		?>

		<section class="etm-summary" aria-label="Audit summary">
			<div class="etm-summary-grid <?php echo $audit['skipped_count'] > 0 ? 'has-attention' : 'two-columns'; ?>">
				<div class="etm-stat">
					<span class="etm-stat-label">Affected Documents</span>
					<strong class="etm-stat-value"><?php echo (int) $audit['docs_affected']; ?></strong>
					<span class="etm-stat-note">Documents with local values</span>
				</div>
				<div class="etm-stat">
					<span class="etm-stat-label">Local Overrides</span>
					<strong class="etm-stat-value"><?php echo (int) $audit['override_count']; ?></strong>
					<span class="etm-stat-note">Explicit values found</span>
				</div>
				<?php if ( $audit['skipped_count'] > 0 ) : ?>
					<div class="etm-stat etm-stat-attention">
						<span class="etm-stat-label">Needs Attention</span>
						<strong class="etm-stat-value"><?php echo (int) $audit['skipped_count']; ?></strong>
						<span class="etm-stat-note">Documents skipped safely</span>
					</div>
				<?php endif; ?>
			</div>
			<div class="etm-category-summary" aria-label="Overrides by typography category">
				<?php foreach ( $audit['group_counts'] as $group => $count ) : ?>
					<span class="etm-count-chip"><span><?php echo esc_html( $this->group_label( $group ) ); ?></span><strong><?php echo (int) $count; ?></strong></span>
				<?php endforeach; ?>
			</div>
		</section>

		<section class="etm-results" aria-labelledby="etm-results-title">
			<div class="etm-section-heading">
				<div>
					<p class="etm-eyebrow">Findings</p>
					<h2 id="etm-results-title">Affected documents</h2>
				</div>
				<span class="etm-section-count"><?php echo (int) $audit['docs_affected']; ?> document(s)</span>
			</div>
			<div class="etm-document-list">
				<?php foreach ( $audit['documents'] as $document ) : ?>
					<details class="etm-document">
						<summary>
							<span class="etm-document-heading">
								<strong><?php echo esc_html( $document['title'] ); ?></strong>
								<span><?php echo esc_html( $document['post_type'] ); ?> · ID <?php echo (int) $document['id']; ?></span>
							</span>
							<span class="etm-document-count"><?php echo (int) $document['count']; ?> override<?php echo 1 === (int) $document['count'] ? '' : 's'; ?></span>
						</summary>
						<div class="etm-document-body">
							<table class="etm-override-table">
								<caption class="screen-reader-text">Typography overrides for <?php echo esc_html( $document['title'] ); ?></caption>
								<thead>
									<tr><th scope="col">Property</th><th scope="col">Context</th><th scope="col">Current Value</th></tr>
								</thead>
								<tbody>
									<?php foreach ( $document['overrides'] as $override ) : ?>
										<tr>
											<td data-label="Property"><strong><?php echo esc_html( $this->group_label( $override['group'] ) ); ?></strong></td>
											<td data-label="Context"><?php echo esc_html( $override['context'] ); ?></td>
											<td data-label="Current Value"><code class="etm-value" title="<?php echo esc_attr( $override['key'] ); ?>"><?php echo esc_html( $this->format_value( $override['value'] ) ); ?></code></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					</details>
				<?php endforeach; ?>
			</div>
		</section>
		<?php
	}

	private function collect_audit_results() {
		$results = array(
			'documents'      => array(),
			'docs_affected'  => 0,
			'override_count' => 0,
			'group_counts'   => array_fill_keys( array_keys( ETM_Matcher::get_groups() ), 0 ),
			'skipped_count'  => 0,
			'skipped_docs'   => array(),
		);

		$query = new WP_Query(
			array(
				'post_type'      => 'any',
				'post_status'    => array( 'publish', 'pending', 'draft', 'future', 'private' ),
				'posts_per_page' => -1,
				'meta_query'     => array(
					array(
						'key'     => '_elementor_data',
						'compare' => 'EXISTS',
					),
				),
			)
		);

		foreach ( $query->posts as $post ) {
			if ( ETM_Elementor_Helper::is_active_kit( $post->ID ) ) {
				continue;
			}

			$raw_data = get_post_meta( $post->ID, '_elementor_data', true );
			$data     = is_string( $raw_data ) ? json_decode( $raw_data, true ) : null;
			if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) ) {
				$results['skipped_count']++;
				$results['skipped_docs'][] = array(
					'id'     => $post->ID,
					'title'  => get_the_title( $post->ID ),
					'reason' => 'Malformed or unavailable Elementor data.',
				);
				continue;
			}

			$overrides = ETM_Scanner::audit_document( $data );
			if ( empty( $overrides ) ) {
				continue;
			}

			$document_overrides = array();
			foreach ( $overrides as $override ) {
				$breakpoint = ETM_Matcher::get_breakpoint_from_key( $override['key'] );
				$document_overrides[] = array(
					'group'   => $override['group'],
					'key'     => $override['key'],
					'value'   => $override['value'],
					'context' => false === $breakpoint ? 'Desktop' : $this->format_breakpoint_label( $breakpoint ),
				);
				$results['group_counts'][ $override['group'] ]++;
			}

			$results['documents'][] = array(
				'id'        => $post->ID,
				'title'     => get_the_title( $post->ID ),
				'post_type' => $this->post_type_label( $post ),
				'count'     => count( $document_overrides ),
				'overrides' => $document_overrides,
			);
			$results['docs_affected']++;
			$results['override_count'] += count( $document_overrides );
		}

		return $results;
	}

	private function render_reset_tab() {
		?>
		<section class="etm-intro" aria-labelledby="etm-reset-title">
			<p class="etm-eyebrow">Reset Overrides</p>
			<h2 id="etm-reset-title">Reset local typography overrides</h2>
			<p>Remove selected explicit local Elementor values so those properties can return to Elementor's Default or inherited behavior.</p>
		</section>

		<?php $this->render_protection_panel(); ?>

		<form class="etm-reset-form" method="post" action="<?php echo esc_url( $this->page_url( 'reset' ) ); ?>" data-etm-reset-form>
			<?php wp_nonce_field( 'etm_reset_action', 'etm_nonce' ); ?>
			<input type="hidden" name="etm_action" value="reset_overrides">

			<fieldset class="etm-category-fieldset">
				<legend class="etm-section-title">Choose typography properties</legend>
				<p class="etm-section-description">Only the properties you select will be cleared. Responsive variants are included when they match the same property.</p>
				<div class="etm-category-grid">
					<?php foreach ( ETM_Matcher::get_groups() as $group_id => $identifier ) : ?>
						<?php $label = $this->group_label( $group_id ); ?>
						<label class="etm-category-card" for="etm-group-<?php echo esc_attr( $group_id ); ?>">
							<input class="etm-category-checkbox" type="checkbox" id="etm-group-<?php echo esc_attr( $group_id ); ?>" name="reset_groups[]" value="<?php echo esc_attr( $group_id ); ?>" data-group-label="<?php echo esc_attr( $label ); ?>">
							<span class="etm-category-copy">
								<strong><?php echo esc_html( $label ); ?></strong>
								<span>Remove explicit local values</span>
							</span>
						</label>
					<?php endforeach; ?>
				</div>
			</fieldset>

			<div class="etm-selection-toolbar">
				<div class="etm-selection-tools" aria-label="Selection tools">
					<button type="button" class="etm-button etm-button-secondary" data-etm-select-all>Select all</button>
					<button type="button" class="etm-button etm-button-secondary" data-etm-clear-selection>Clear selection</button>
				</div>
				<div class="etm-selection-status" role="status" aria-live="polite">
					<strong data-etm-selection-count>0 selected</strong>
					<span data-etm-selection-help>Select one or more typography properties to continue.</span>
				</div>
			</div>

			<div class="etm-form-actions">
				<button type="submit" class="etm-button etm-button-primary" data-etm-review aria-controls="etm-review-panel" disabled>Review reset</button>
			</div>

			<section id="etm-review-panel" class="etm-review-panel" data-etm-review-panel hidden aria-labelledby="etm-review-title">
				<div>
					<p class="etm-eyebrow">Final review</p>
					<h2 id="etm-review-title">You are about to reset:</h2>
					<ul class="etm-review-list" data-etm-review-list></ul>
				</div>
				<p class="etm-review-copy">The selected explicit local values will be cleared across eligible Elementor documents. Global Typography, the active Kit, and unselected properties remain protected.</p>
				<div class="etm-form-actions etm-review-actions">
					<button type="submit" class="etm-button etm-button-danger" data-etm-confirm hidden>Reset selected overrides</button>
					<button type="button" class="etm-button etm-button-secondary" data-etm-cancel>Cancel</button>
				</div>
			</section>
		</form>
		<?php
	}

	private function render_protection_panel() {
		?>
		<section class="etm-panel etm-protection-panel" aria-labelledby="etm-protection-title">
			<div>
				<p class="etm-eyebrow">Safety boundary</p>
				<h2 id="etm-protection-title">What remains protected</h2>
			</div>
			<ul class="etm-protection-list">
				<li><span aria-hidden="true">✓</span> Global Typography is not deleted</li>
				<li><span aria-hidden="true">✓</span> Elementor's active Kit is protected</li>
				<li><span aria-hidden="true">✓</span> Unselected typography properties remain unchanged</li>
				<li><span aria-hidden="true">✓</span> Original Elementor document data is backed up before modification</li>
			</ul>
		</section>
		<?php
	}

	private function group_label( $group_id ) {
		return isset( $this->group_labels[ $group_id ] ) ? $this->group_labels[ $group_id ] : ucwords( str_replace( '_', ' ', $group_id ) );
	}

	private function format_breakpoint_label( $breakpoint ) {
		return ucwords( str_replace( '_', ' ', (string) $breakpoint ) );
	}

	private function post_type_label( $post ) {
		$post_type_object = get_post_type_object( $post->post_type );
		if ( $post_type_object && isset( $post_type_object->labels->singular_name ) ) {
			return $post_type_object->labels->singular_name;
		}

		return ucwords( str_replace( '_', ' ', $post->post_type ) );
	}

	private function format_value( $value ) {
		if ( is_array( $value ) ) {
			if ( array_key_exists( 'size', $value ) ) {
				$formatted = (string) $value['size'];
				if ( isset( $value['unit'] ) && '' !== (string) $value['unit'] ) {
					$formatted .= (string) $value['unit'];
				}

				return $formatted;
			}

			$encoded = wp_json_encode( $value );
			return false === $encoded ? print_r( $value, true ) : $encoded;
		}

		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}

		return null === $value ? '' : (string) $value;
	}

	private function is_elementor_available() {
		return did_action( 'elementor/loaded' ) > 0 && class_exists( '\Elementor\Plugin' );
	}

	private function page_url( $tab, $extra = array() ) {
		$args = array_merge(
			array(
				'page' => $this->page_slug,
				'tab'  => $tab,
			),
			$extra
		);

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}
}
