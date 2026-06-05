<?php
/**
 * Admin page for Oversized Image Finder.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OIF_Admin_Page {

	/**
	 * Register admin hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Register Tools submenu page.
	 */
	public static function register_menu() {
		add_management_page(
			__( 'Oversized Images', 'oversized-image-finder' ),
			__( 'Oversized Images', 'oversized-image-finder' ),
			'manage_options',
			'oversized-image-finder',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Register plugin settings.
	 */
	public static function register_settings() {
		register_setting(
			'oif_settings_group',
			OIF_OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
				'default'           => oif_get_default_settings(),
			)
		);
	}

	/**
	 * Sanitize settings input.
	 *
	 * @param array<string, mixed> $input Raw settings.
	 * @return array<string, int>
	 */
	public static function sanitize_settings( $input ) {
		$defaults = oif_get_default_settings();
		$output   = array();

		$output['max_file_size_kb'] = isset( $input['max_file_size_kb'] ) ? max( 1, absint( $input['max_file_size_kb'] ) ) : $defaults['max_file_size_kb'];
		$output['max_width']        = isset( $input['max_width'] ) ? max( 1, absint( $input['max_width'] ) ) : $defaults['max_width'];
		$output['max_height']       = isset( $input['max_height'] ) ? max( 1, absint( $input['max_height'] ) ) : $defaults['max_height'];
		$output['batch_size']       = isset( $input['batch_size'] ) ? max( 10, min( 200, absint( $input['batch_size'] ) ) ) : $defaults['batch_size'];
		$output['cache_ttl_hours']  = isset( $input['cache_ttl_hours'] ) ? max( 1, min( 168, absint( $input['cache_ttl_hours'] ) ) ) : $defaults['cache_ttl_hours'];

		return $output;
	}

	/**
	 * Enqueue admin assets on plugin page only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue_assets( $hook ) {
		if ( 'tools_page_oversized-image-finder' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'oif-admin',
			OIF_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			OIF_VERSION
		);

		wp_enqueue_script(
			'oif-admin',
			OIF_PLUGIN_URL . 'assets/js/admin.js',
			array(),
			OIF_VERSION,
			true
		);

		$settings = oif_get_settings();
		$cached   = get_transient( OIF_TRANSIENT_RESULTS );

		wp_localize_script(
			'oif-admin',
			'oifAdmin',
			array(
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'oif_scan_nonce' ),
				'settings' => $settings,
				'cached'   => is_array( $cached ) ? $cached : null,
				'i18n'     => array(
					'scanning'       => __( 'Scanning', 'oversized-image-finder' ),
					'scanComplete'   => __( 'Scan complete.', 'oversized-image-finder' ),
					'scanError'      => __( 'Scan failed. Please try again.', 'oversized-image-finder' ),
					'noResults'      => __( 'No images match the current filter.', 'oversized-image-finder' ),
					'noCached'       => __( 'No scan results yet. Click "Start Scan" to begin.', 'oversized-image-finder' ),
					'confirmRescan'  => __( 'Start a new scan? Current cached results will be replaced.', 'oversized-image-finder' ),
					'yes'            => __( 'Yes', 'oversized-image-finder' ),
					'inLibrary'      => __( 'Yes', 'oversized-image-finder' ),
					'notInLibrary'   => __( 'No', 'oversized-image-finder' ),
					'edit'           => __( 'Edit', 'oversized-image-finder' ),
					'view'           => __( 'View', 'oversized-image-finder' ),
					'severityHigh'   => __( 'High', 'oversized-image-finder' ),
					'severityMedium' => __( 'Medium', 'oversized-image-finder' ),
					'severityInfo'   => __( 'Info', 'oversized-image-finder' ),
				),
			)
		);
	}

	/**
	 * Render admin page.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$tab      = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'scan';
		$settings = oif_get_settings();
		$cached   = get_transient( OIF_TRANSIENT_RESULTS );
		$base_url = admin_url( 'tools.php?page=oversized-image-finder' );

		?>
		<div class="wrap oif-wrap">
			<h1><?php esc_html_e( 'Oversized Image Finder', 'oversized-image-finder' ); ?></h1>

			<nav class="nav-tab-wrapper oif-tabs">
				<a href="<?php echo esc_url( $base_url . '&tab=scan' ); ?>" class="nav-tab <?php echo 'scan' === $tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Scan Results', 'oversized-image-finder' ); ?>
				</a>
				<a href="<?php echo esc_url( $base_url . '&tab=settings' ); ?>" class="nav-tab <?php echo 'settings' === $tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Settings', 'oversized-image-finder' ); ?>
				</a>
			</nav>

			<?php if ( 'settings' === $tab ) : ?>
				<?php self::render_settings_tab( $settings ); ?>
			<?php else : ?>
				<?php self::render_scan_tab( $settings, $cached ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render settings tab.
	 *
	 * @param array<string, int> $settings Plugin settings.
	 */
	private static function render_settings_tab( $settings ) {
		?>
		<form method="post" action="options.php" class="oif-settings-form">
			<?php settings_fields( 'oif_settings_group' ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="oif_max_file_size_kb"><?php esc_html_e( 'Max file size (KB)', 'oversized-image-finder' ); ?></label>
					</th>
					<td>
						<input type="number" id="oif_max_file_size_kb" name="<?php echo esc_attr( OIF_OPTION_KEY ); ?>[max_file_size_kb]" value="<?php echo esc_attr( $settings['max_file_size_kb'] ); ?>" min="1" class="small-text" />
						<p class="description"><?php esc_html_e( 'Images larger than this file size are flagged as oversized.', 'oversized-image-finder' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="oif_max_width"><?php esc_html_e( 'Max width (px)', 'oversized-image-finder' ); ?></label>
					</th>
					<td>
						<input type="number" id="oif_max_width" name="<?php echo esc_attr( OIF_OPTION_KEY ); ?>[max_width]" value="<?php echo esc_attr( $settings['max_width'] ); ?>" min="1" class="small-text" />
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="oif_max_height"><?php esc_html_e( 'Max height (px)', 'oversized-image-finder' ); ?></label>
					</th>
					<td>
						<input type="number" id="oif_max_height" name="<?php echo esc_attr( OIF_OPTION_KEY ); ?>[max_height]" value="<?php echo esc_attr( $settings['max_height'] ); ?>" min="1" class="small-text" />
						<p class="description"><?php esc_html_e( 'Images wider or taller than these dimensions are flagged.', 'oversized-image-finder' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="oif_batch_size"><?php esc_html_e( 'Batch size', 'oversized-image-finder' ); ?></label>
					</th>
					<td>
						<input type="number" id="oif_batch_size" name="<?php echo esc_attr( OIF_OPTION_KEY ); ?>[batch_size]" value="<?php echo esc_attr( $settings['batch_size'] ); ?>" min="10" max="200" class="small-text" />
						<p class="description"><?php esc_html_e( 'Number of images processed per AJAX request (10–200).', 'oversized-image-finder' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="oif_cache_ttl_hours"><?php esc_html_e( 'Cache TTL (hours)', 'oversized-image-finder' ); ?></label>
					</th>
					<td>
						<input type="number" id="oif_cache_ttl_hours" name="<?php echo esc_attr( OIF_OPTION_KEY ); ?>[cache_ttl_hours]" value="<?php echo esc_attr( $settings['cache_ttl_hours'] ); ?>" min="1" max="168" class="small-text" />
						<p class="description"><?php esc_html_e( 'How long scan results are cached before requiring a rescan.', 'oversized-image-finder' ); ?></p>
					</td>
				</tr>
			</table>

			<?php submit_button(); ?>
		</form>
		<?php
	}

	/**
	 * Render scan results tab.
	 *
	 * @param array<string, int>        $settings Plugin settings.
	 * @param array<string, mixed>|false $cached  Cached scan results.
	 */
	private static function render_scan_tab( $settings, $cached ) {
		$scanned_at = '';
		if ( is_array( $cached ) && ! empty( $cached['scanned_at'] ) ) {
			$scanned_at = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $cached['scanned_at'] );
		}
		?>
		<div class="oif-scan-panel">
			<div class="oif-toolbar">
				<fieldset class="oif-scope">
					<legend><?php esc_html_e( 'Scan scope', 'oversized-image-finder' ); ?></legend>
					<label>
						<input type="checkbox" id="oif-scope-media" checked />
						<?php esc_html_e( 'Media Library', 'oversized-image-finder' ); ?>
					</label>
					<label>
						<input type="checkbox" id="oif-scope-uploads" checked />
						<?php esc_html_e( 'Entire uploads folder', 'oversized-image-finder' ); ?>
					</label>
					<label>
						<input type="checkbox" id="oif-scope-theme" />
						<?php esc_html_e( 'Theme & plugins', 'oversized-image-finder' ); ?>
					</label>
				</fieldset>

				<div class="oif-actions">
					<button type="button" class="button button-primary" id="oif-start-scan">
						<?php esc_html_e( 'Start Scan', 'oversized-image-finder' ); ?>
					</button>
					<button type="button" class="button" id="oif-rescan">
						<?php esc_html_e( 'Rescan', 'oversized-image-finder' ); ?>
					</button>
				</div>

				<div class="oif-filter">
					<label for="oif-filter-mode"><?php esc_html_e( 'Filter', 'oversized-image-finder' ); ?></label>
					<select id="oif-filter-mode">
						<option value="oversized"><?php esc_html_e( 'Oversized only (size or dimensions)', 'oversized-image-finder' ); ?></option>
						<option value="size"><?php esc_html_e( 'By file size only', 'oversized-image-finder' ); ?></option>
						<option value="dimensions"><?php esc_html_e( 'By dimensions only', 'oversized-image-finder' ); ?></option>
						<option value="all"><?php esc_html_e( 'Show all (sorted by size)', 'oversized-image-finder' ); ?></option>
					</select>
				</div>
			</div>

			<div class="oif-progress" id="oif-progress" hidden>
				<div class="oif-progress-bar">
					<div class="oif-progress-fill" id="oif-progress-fill"></div>
				</div>
				<p class="oif-progress-text" id="oif-progress-text"></p>
			</div>

			<div class="oif-summary" id="oif-summary">
				<?php if ( $scanned_at ) : ?>
					<p>
						<?php
						printf(
							/* translators: %s: scan date/time */
							esc_html__( 'Last scan: %s', 'oversized-image-finder' ),
							esc_html( $scanned_at )
						);
						?>
					</p>
				<?php endif; ?>
				<p class="oif-summary-stats" id="oif-summary-stats"></p>
			</div>

			<div class="oif-thresholds-note">
				<?php
				printf(
					/* translators: 1: max file size KB, 2: max width, 3: max height */
					esc_html__( 'Current thresholds: %1$s KB file size, %2$s px width, %3$s px height.', 'oversized-image-finder' ),
					esc_html( (string) $settings['max_file_size_kb'] ),
					esc_html( (string) $settings['max_width'] ),
					esc_html( (string) $settings['max_height'] )
				);
				?>
				<a href="<?php echo esc_url( admin_url( 'tools.php?page=oversized-image-finder&tab=settings' ) ); ?>">
					<?php esc_html_e( 'Change settings', 'oversized-image-finder' ); ?>
				</a>
			</div>

			<table class="wp-list-table widefat fixed striped oif-results-table" id="oif-results-table">
				<thead>
					<tr>
						<th class="oif-col-thumb"><?php esc_html_e( 'Preview', 'oversized-image-finder' ); ?></th>
						<th class="oif-col-sortable" data-sort="filename"><?php esc_html_e( 'Filename', 'oversized-image-finder' ); ?></th>
						<th class="oif-col-sortable" data-sort="filesize"><?php esc_html_e( 'File size', 'oversized-image-finder' ); ?></th>
						<th class="oif-col-sortable" data-sort="dimensions"><?php esc_html_e( 'Dimensions', 'oversized-image-finder' ); ?></th>
						<th><?php esc_html_e( 'Format', 'oversized-image-finder' ); ?></th>
						<th><?php esc_html_e( 'Location', 'oversized-image-finder' ); ?></th>
						<th><?php esc_html_e( 'In library', 'oversized-image-finder' ); ?></th>
						<th><?php esc_html_e( 'Used on site', 'oversized-image-finder' ); ?></th>
						<th><?php esc_html_e( 'Severity', 'oversized-image-finder' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'oversized-image-finder' ); ?></th>
					</tr>
				</thead>
				<tbody id="oif-results-body">
					<tr class="oif-empty-row">
						<td colspan="10"><?php esc_html_e( 'No scan results yet. Click "Start Scan" to begin.', 'oversized-image-finder' ); ?></td>
					</tr>
				</tbody>
			</table>
		</div>
		<?php
	}
}
