<?php
/**
 * WordPress admin interface.
 *
 * @package TypeOverride
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ETM_Admin {

	private $page_slug = 'typeoverride';

	private $version = '0.2.0';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function add_menu_page() {
		$plugin_file = dirname( __DIR__ ) . '/typeoverride.php';

		add_menu_page(
			__( 'TypeOverride', 'typeoverride' ),
			__( 'TypeOverride', 'typeoverride' ),
			'manage_options',
			$this->page_slug,
			array( $this, 'render_page' ),
			plugins_url( 'admin/images/typeoverride-admin-menu.svg', $plugin_file ),
			80
		);
	}

	public function enqueue_assets() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This GET parameter only selects the TypeOverride admin screen.
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
			array( 'wp-i18n' ),
			$this->version,
			true
		);
		wp_set_script_translations( 'etm-admin', 'typeoverride', dirname( __DIR__ ) . '/languages' );
	}

	public function handle_actions() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( $this->page_slug !== $page ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'typeoverride' ) );
		}

		$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		$action         = isset( $_POST['etm_action'] ) ? sanitize_key( wp_unslash( $_POST['etm_action'] ) ) : '';
		if ( 'post' === $request_method && 'reset_overrides' === $action ) {
			check_admin_referer( 'etm_reset_action', 'etm_nonce' );
			$this->process_reset();
		}
	}

	private function process_reset() {
		if ( ! $this->is_elementor_available() ) {
			$results = array(
				'modified_docs' => 0,
				'reset_count'   => 0,
				'errors'        => array( __( 'Elementor is unavailable. No reset was performed.', 'typeoverride' ) ),
			);
			set_transient( 'etm_reset_result', $results, MINUTE_IN_SECONDS );
			wp_safe_redirect( $this->page_url( 'reset', array( 'reset' => 'completed' ) ) );
			exit;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The reset nonce is verified in handle_actions before this method runs; scalar values are filtered and sanitized immediately below.
		$submitted_groups = isset( $_POST['reset_groups'] ) ? wp_unslash( $_POST['reset_groups'] ) : array();
		$submitted_groups = array_filter(
			(array) $submitted_groups,
			'is_string'
		);
		$submitted_groups = array_map( 'sanitize_key', $submitted_groups );
		$selected_groups = array_values(
			array_intersect(
				$submitted_groups,
				array_keys( ETM_Matcher::get_groups() )
			)
		);

		if ( empty( $selected_groups ) ) {
			$results = array(
				'modified_docs' => 0,
				'reset_count'   => 0,
				'errors'        => array( __( 'No valid typography groups were selected.', 'typeoverride' ) ),
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
				'no_found_rows'  => true,
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
					/* translators: 1: document ID, 2: error message. */
					__( 'Doc ID %1$d: %2$s', 'typeoverride' ),
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
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This GET parameter only selects the TypeOverride tab.
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

			<nav class="etm-tabs" aria-label="<?php echo esc_attr__( 'TypeOverride sections', 'typeoverride' ); ?>">
				<a href="<?php echo esc_url( $this->page_url( 'audit' ) ); ?>" class="etm-tab <?php echo 'audit' === $current_tab ? 'is-active' : ''; ?>" <?php echo 'audit' === $current_tab ? 'aria-current="page"' : ''; ?>><?php echo esc_html__( 'Audit', 'typeoverride' ); ?></a>
				<a href="<?php echo esc_url( $this->page_url( 'reset' ) ); ?>" class="etm-tab <?php echo 'reset' === $current_tab ? 'is-active' : ''; ?>" <?php echo 'reset' === $current_tab ? 'aria-current="page"' : ''; ?>><?php echo esc_html__( 'Reset Overrides', 'typeoverride' ); ?></a>
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
		$plugin_file = dirname( __DIR__ ) . '/typeoverride.php';
		$lockup_url  = plugins_url( 'admin/images/typeoverride-lockup-horizontal.svg', $plugin_file );
		$status_label = $elementor_available ? __( 'Elementor detected', 'typeoverride' ) : __( 'Elementor unavailable', 'typeoverride' );
		$status_class = $elementor_available ? 'is-ready' : 'is-unavailable';
		$version_label = sprintf(
			/* translators: %s: plugin version. */
			__( 'v%s', 'typeoverride' ),
			$this->version
		);
		?>
		<header class="etm-header">
			<div class="etm-header-copy">
				<p class="etm-eyebrow"><?php echo esc_html__( 'Elementor typography utility', 'typeoverride' ); ?></p>
				<div class="etm-header-lockup">
					<img src="<?php echo esc_url( $lockup_url ); ?>" alt="" aria-hidden="true">
					<h1 class="screen-reader-text"><?php echo esc_html__( 'TypeOverride', 'typeoverride' ); ?></h1>
				</div>
				<p class="etm-subtitle"><?php echo esc_html__( 'Find and reset local typography overrides in Elementor.', 'typeoverride' ); ?></p>
			</div>
			<div class="etm-header-meta" aria-label="<?php echo esc_attr__( 'TypeOverride status', 'typeoverride' ); ?>">
				<span class="etm-status <?php echo esc_attr( $status_class ); ?>">
					<span class="etm-status-dot" aria-hidden="true"></span>
					<?php echo esc_html( $status_label ); ?>
				</span>
				<span class="etm-version"><?php echo esc_html( $version_label ); ?></span>
			</div>
		</header>
		<?php
	}

	private function render_reset_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This GET parameter only selects a transient result view.
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
			$heading = __( 'Typography overrides reset successfully', 'typeoverride' );
		} elseif ( $has_changes ) {
			$state   = 'warning';
			$heading = __( 'Reset completed with skipped items', 'typeoverride' );
		} else {
			$state   = 'error';
			$heading = __( 'Reset could not complete', 'typeoverride' );
		}
		?>
		<section class="etm-result etm-result-<?php echo esc_attr( $state ); ?>" role="<?php echo 'error' === $state ? 'alert' : 'status'; ?>">
			<div class="etm-result-icon" aria-hidden="true"><?php echo 'success' === $state ? '✓' : ( 'warning' === $state ? '!' : '×' ); ?></div>
			<div class="etm-result-body">
				<h2><?php echo esc_html( $heading ); ?></h2>
				<?php if ( $has_errors && $has_changes ) : ?>
					<p><?php echo esc_html__( 'Completed changes are listed below. Review the skipped or failed items before trying again.', 'typeoverride' ); ?></p>
				<?php elseif ( $has_errors ) : ?>
					<p><?php echo esc_html__( 'No documents were modified. Review the reported items before trying again.', 'typeoverride' ); ?></p>
				<?php else : ?>
					<p><?php echo esc_html__( 'Selected local values were cleared so Elementor can use its available default or global inheritance.', 'typeoverride' ); ?></p>
				<?php endif; ?>
				<div class="etm-result-metrics" aria-label="<?php echo esc_attr__( 'Reset results', 'typeoverride' ); ?>">
					<div><span><?php echo esc_html__( 'Documents modified', 'typeoverride' ); ?></span><strong><?php echo (int) $result['modified_docs']; ?></strong></div>
					<div><span><?php echo esc_html__( 'Overrides reset', 'typeoverride' ); ?></span><strong><?php echo (int) $result['reset_count']; ?></strong></div>
				</div>
				<?php if ( $has_errors ) : ?>
					<h3><?php echo esc_html__( 'Skipped or failed items', 'typeoverride' ); ?></h3>
					<ul>
						<?php foreach ( $result['errors'] as $error ) : ?>
							<li><?php echo esc_html( $error ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
				<p class="etm-result-footnote"><?php echo esc_html__( 'If cached styles remain, regenerate Elementor files/data from Elementor Tools.', 'typeoverride' ); ?></p>
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
				<p class="etm-eyebrow"><?php echo esc_html__( 'Dependency check', 'typeoverride' ); ?></p>
				<h2 id="etm-dependency-title"><?php echo esc_html__( 'Elementor is unavailable', 'typeoverride' ); ?></h2>
				<p><?php echo esc_html__( 'TypeOverride requires Elementor to inspect typography overrides. Activate Elementor, then return here to run an audit or reset.', 'typeoverride' ); ?></p>
			</div>
		</section>
		<?php
	}

	private function render_audit_tab() {
		$audit = $this->collect_audit_results();
		$skipped_message = sprintf(
			/* translators: %d: number of skipped documents. */
			_n( '%d document could not be inspected safely, so it was left unchanged.', '%d documents could not be inspected safely, so they were left unchanged.', (int) $audit['skipped_count'], 'typeoverride' ),
			(int) $audit['skipped_count']
		);
		$affected_documents_label = sprintf(
			/* translators: %d: number of affected documents. */
			_n( '%d document', '%d documents', (int) $audit['docs_affected'], 'typeoverride' ),
			(int) $audit['docs_affected']
		);
		?>
		<section class="etm-intro" aria-labelledby="etm-audit-title">
			<p class="etm-eyebrow"><?php echo esc_html__( 'Audit', 'typeoverride' ); ?></p>
			<h2 id="etm-audit-title"><?php echo esc_html__( 'What is overriding my global typography?', 'typeoverride' ); ?></h2>
			<p><?php echo esc_html__( 'Review explicit local typography values across Elementor documents before deciding what to reset.', 'typeoverride' ); ?></p>
		</section>

		<?php if ( $audit['skipped_count'] > 0 ) : ?>
			<section class="etm-state etm-state-warning" role="status" aria-labelledby="etm-audit-warning-title">
				<div class="etm-state-icon" aria-hidden="true">!</div>
				<div>
					<h2 id="etm-audit-warning-title"><?php echo esc_html__( 'Audit completed with skipped items', 'typeoverride' ); ?></h2>
					<p><?php echo esc_html( $skipped_message ); ?></p>
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
				<h2><?php echo esc_html__( 'No local typography overrides found', 'typeoverride' ); ?></h2>
				<p><?php echo esc_html( $audit['skipped_count'] > 0 ? __( 'No overrides were found in the documents that completed the audit.', 'typeoverride' ) : __( 'Elementor typography in the audited content is already inheriting its available global/default settings.', 'typeoverride' ) ); ?></p>
			</section>
			<?php
			return;
		endif;
		?>

		<section class="etm-summary" aria-label="<?php echo esc_attr__( 'Audit summary', 'typeoverride' ); ?>">
			<div class="etm-summary-grid <?php echo $audit['skipped_count'] > 0 ? 'has-attention' : 'two-columns'; ?>">
				<div class="etm-stat">
					<span class="etm-stat-label"><?php echo esc_html__( 'Affected Documents', 'typeoverride' ); ?></span>
					<strong class="etm-stat-value"><?php echo (int) $audit['docs_affected']; ?></strong>
					<span class="etm-stat-note"><?php echo esc_html__( 'Documents with local values', 'typeoverride' ); ?></span>
				</div>
				<div class="etm-stat">
					<span class="etm-stat-label"><?php echo esc_html__( 'Local Overrides', 'typeoverride' ); ?></span>
					<strong class="etm-stat-value"><?php echo (int) $audit['override_count']; ?></strong>
					<span class="etm-stat-note"><?php echo esc_html__( 'Explicit values found', 'typeoverride' ); ?></span>
				</div>
				<?php if ( $audit['skipped_count'] > 0 ) : ?>
					<div class="etm-stat etm-stat-attention">
						<span class="etm-stat-label"><?php echo esc_html__( 'Needs Attention', 'typeoverride' ); ?></span>
						<strong class="etm-stat-value"><?php echo (int) $audit['skipped_count']; ?></strong>
						<span class="etm-stat-note"><?php echo esc_html__( 'Documents skipped safely', 'typeoverride' ); ?></span>
					</div>
				<?php endif; ?>
			</div>
			<div class="etm-category-summary" aria-label="<?php echo esc_attr__( 'Overrides by typography category', 'typeoverride' ); ?>">
				<?php foreach ( $audit['group_counts'] as $group => $count ) : ?>
					<span class="etm-count-chip"><span><?php echo esc_html( $this->group_label( $group ) ); ?></span><strong><?php echo (int) $count; ?></strong></span>
				<?php endforeach; ?>
			</div>
		</section>

		<section class="etm-results" aria-labelledby="etm-results-title">
			<div class="etm-section-heading">
				<div>
					<p class="etm-eyebrow"><?php echo esc_html__( 'Findings', 'typeoverride' ); ?></p>
					<h2 id="etm-results-title"><?php echo esc_html__( 'Affected documents', 'typeoverride' ); ?></h2>
				</div>
				<span class="etm-section-count"><?php echo esc_html( $affected_documents_label ); ?></span>
			</div>
			<div class="etm-document-list">
				<?php foreach ( $audit['documents'] as $document ) : ?>
					<?php
					$document_count_label = sprintf(
						/* translators: %d: number of overrides in the document. */
						_n( '%d override', '%d overrides', (int) $document['count'], 'typeoverride' ),
						(int) $document['count']
					);
					$document_caption = sprintf(
						/* translators: %s: document title. */
						__( 'Typography overrides for %s', 'typeoverride' ),
						$document['title']
					);
					?>
					<details class="etm-document">
						<summary>
							<span class="etm-document-heading">
								<strong><?php echo esc_html( $document['title'] ); ?></strong>
								<span><?php echo esc_html( $document['post_type'] ); ?> · ID <?php echo (int) $document['id']; ?></span>
							</span>
							<span class="etm-document-count"><?php echo esc_html( $document_count_label ); ?></span>
						</summary>
						<div class="etm-document-body">
							<table class="etm-override-table">
								<caption class="screen-reader-text"><?php echo esc_html( $document_caption ); ?></caption>
								<thead>
									<tr><th scope="col"><?php echo esc_html__( 'Property', 'typeoverride' ); ?></th><th scope="col"><?php echo esc_html__( 'Context', 'typeoverride' ); ?></th><th scope="col"><?php echo esc_html__( 'Current Value', 'typeoverride' ); ?></th></tr>
								</thead>
								<tbody>
									<?php foreach ( $document['overrides'] as $override ) : ?>
										<tr>
											<td data-label="<?php echo esc_attr__( 'Property', 'typeoverride' ); ?>"><strong><?php echo esc_html( $this->group_label( $override['group'] ) ); ?></strong></td>
											<td data-label="<?php echo esc_attr__( 'Context', 'typeoverride' ); ?>"><?php echo esc_html( $override['context'] ); ?></td>
											<td data-label="<?php echo esc_attr__( 'Current Value', 'typeoverride' ); ?>"><code class="etm-value" title="<?php echo esc_attr( $override['key'] ); ?>"><?php echo esc_html( $this->format_value( $override['value'] ) ); ?></code></td>
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
				'no_found_rows'  => true,
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
			<p class="etm-eyebrow"><?php echo esc_html__( 'Reset Overrides', 'typeoverride' ); ?></p>
			<h2 id="etm-reset-title"><?php echo esc_html__( 'Reset local typography overrides', 'typeoverride' ); ?></h2>
			<p><?php echo esc_html__( "Remove selected explicit local Elementor values so those properties can return to Elementor's Default or inherited behavior.", 'typeoverride' ); ?></p>
		</section>

		<?php $this->render_protection_panel(); ?>

		<form class="etm-reset-form" method="post" action="<?php echo esc_url( $this->page_url( 'reset' ) ); ?>" data-etm-reset-form>
			<?php wp_nonce_field( 'etm_reset_action', 'etm_nonce' ); ?>
			<input type="hidden" name="etm_action" value="reset_overrides">

			<fieldset class="etm-category-fieldset">
				<legend class="etm-section-title"><?php echo esc_html__( 'Choose typography properties', 'typeoverride' ); ?></legend>
				<p class="etm-section-description"><?php echo esc_html__( 'Only the properties you select will be cleared. Responsive variants are included when they match the same property.', 'typeoverride' ); ?></p>
				<div class="etm-category-grid">
					<?php foreach ( ETM_Matcher::get_groups() as $group_id => $identifier ) : ?>
						<?php $label = $this->group_label( $group_id ); ?>
						<label class="etm-category-card" for="etm-group-<?php echo esc_attr( $group_id ); ?>">
							<input class="etm-category-checkbox" type="checkbox" id="etm-group-<?php echo esc_attr( $group_id ); ?>" name="reset_groups[]" value="<?php echo esc_attr( $group_id ); ?>" data-group-label="<?php echo esc_attr( $label ); ?>">
							<span class="etm-category-copy">
								<strong><?php echo esc_html( $label ); ?></strong>
								<span><?php echo esc_html__( 'Remove explicit local values', 'typeoverride' ); ?></span>
							</span>
						</label>
					<?php endforeach; ?>
				</div>
			</fieldset>

			<div class="etm-selection-toolbar">
				<div class="etm-selection-tools" aria-label="<?php echo esc_attr__( 'Selection tools', 'typeoverride' ); ?>">
					<button type="button" class="etm-button etm-button-secondary" data-etm-select-all><?php echo esc_html__( 'Select all', 'typeoverride' ); ?></button>
					<button type="button" class="etm-button etm-button-secondary" data-etm-clear-selection><?php echo esc_html__( 'Clear selection', 'typeoverride' ); ?></button>
				</div>
				<div class="etm-selection-status" role="status" aria-live="polite">
					<strong data-etm-selection-count>0 <?php echo esc_html__( 'selected', 'typeoverride' ); ?></strong>
					<span data-etm-selection-help><?php echo esc_html__( 'Select one or more typography properties to continue.', 'typeoverride' ); ?></span>
				</div>
			</div>

			<div class="etm-form-actions">
				<button type="submit" class="etm-button etm-button-primary" data-etm-review aria-controls="etm-review-panel" disabled><?php echo esc_html__( 'Review reset', 'typeoverride' ); ?></button>
			</div>

			<section id="etm-review-panel" class="etm-review-panel" data-etm-review-panel hidden aria-labelledby="etm-review-title">
				<div>
					<p class="etm-eyebrow"><?php echo esc_html__( 'Final review', 'typeoverride' ); ?></p>
					<h2 id="etm-review-title"><?php echo esc_html__( 'You are about to reset:', 'typeoverride' ); ?></h2>
					<ul class="etm-review-list" data-etm-review-list></ul>
				</div>
				<p class="etm-review-copy"><?php echo esc_html__( 'The selected explicit local values will be cleared across eligible Elementor documents. Global Typography, the active Kit, and unselected properties remain protected.', 'typeoverride' ); ?></p>
				<div class="etm-form-actions etm-review-actions">
					<button type="submit" class="etm-button etm-button-danger" data-etm-confirm hidden><?php echo esc_html__( 'Reset selected overrides', 'typeoverride' ); ?></button>
					<button type="button" class="etm-button etm-button-secondary" data-etm-cancel><?php echo esc_html__( 'Cancel', 'typeoverride' ); ?></button>
				</div>
			</section>
		</form>
		<?php
	}

	private function render_protection_panel() {
		?>
		<section class="etm-panel etm-protection-panel" aria-labelledby="etm-protection-title">
			<div>
				<p class="etm-eyebrow"><?php echo esc_html__( 'Safety boundary', 'typeoverride' ); ?></p>
				<h2 id="etm-protection-title"><?php echo esc_html__( 'What remains protected', 'typeoverride' ); ?></h2>
			</div>
			<ul class="etm-protection-list">
				<li><span aria-hidden="true">✓</span> <?php echo esc_html__( 'Global Typography is not deleted', 'typeoverride' ); ?></li>
				<li><span aria-hidden="true">✓</span> <?php echo esc_html__( "Elementor's active Kit is protected", 'typeoverride' ); ?></li>
				<li><span aria-hidden="true">✓</span> <?php echo esc_html__( 'Unselected typography properties remain unchanged', 'typeoverride' ); ?></li>
				<li><span aria-hidden="true">✓</span> <?php echo esc_html__( 'Original Elementor document data is backed up before modification', 'typeoverride' ); ?></li>
			</ul>
		</section>
		<?php
	}

	private function group_label( $group_id ) {
		$labels = array(
			'font_family'     => __( 'Font Family', 'typeoverride' ),
			'font_size'       => __( 'Font Size', 'typeoverride' ),
			'font_weight'     => __( 'Font Weight', 'typeoverride' ),
			'text_transform'  => __( 'Text Transform', 'typeoverride' ),
			'font_style'      => __( 'Font Style', 'typeoverride' ),
			'text_decoration' => __( 'Text Decoration', 'typeoverride' ),
			'line_height'     => __( 'Line Height', 'typeoverride' ),
			'letter_spacing'  => __( 'Letter Spacing', 'typeoverride' ),
			'word_spacing'    => __( 'Word Spacing', 'typeoverride' ),
		);

		return isset( $labels[ $group_id ] ) ? $labels[ $group_id ] : ucwords( str_replace( '_', ' ', $group_id ) );
	}

	private function format_breakpoint_label( $breakpoint ) {
		$labels = array(
			'tablet'       => __( 'Tablet', 'typeoverride' ),
			'mobile'       => __( 'Mobile', 'typeoverride' ),
			'widescreen'   => __( 'Widescreen', 'typeoverride' ),
			'laptop'       => __( 'Laptop', 'typeoverride' ),
			'tablet_extra' => __( 'Tablet Extra', 'typeoverride' ),
			'mobile_extra' => __( 'Mobile Extra', 'typeoverride' ),
		);
		$breakpoint = (string) $breakpoint;

		return isset( $labels[ $breakpoint ] ) ? $labels[ $breakpoint ] : ucwords( str_replace( '_', ' ', $breakpoint ) );
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
			return false === $encoded ? __( 'Unable to display value.', 'typeoverride' ) : $encoded;
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
