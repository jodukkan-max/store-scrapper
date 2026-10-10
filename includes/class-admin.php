<?php
/**
 * Admin screens (settings, selection, progress) and AJAX endpoints.
 *
 * The product discovery/selection/import workflow is stateless: discovered
 * products are returned to the browser and never written to the database.
 *
 * @package SWI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWI_Admin {

	/**
	 * Singleton instance.
	 *
	 * @var SWI_Admin|null
	 */
	private static $instance = null;

	/**
	 * Admin page hook suffix.
	 *
	 * @var string
	 */
	private $page_hook = '';

	/**
	 * Get the singleton instance.
	 *
	 * @return SWI_Admin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register hooks.
	 */
	private function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );

		// Form handlers.
		add_action( 'admin_post_swi_save_settings', array( $this, 'handle_save_settings' ) );

		// AJAX handlers.
		$ajax_actions = array(
			'swi_detect',
			'swi_discover',
			'swi_preview',
			'swi_import_one',
			'swi_debug_log',
			'swi_clear_log',
		);

		foreach ( $ajax_actions as $action ) {
			add_action( 'wp_ajax_' . $action, array( $this, 'ajax_' . $action ) );
		}
	}

	/**
	 * Register the admin submenu under WooCommerce.
	 */
	public function menu() {
		$this->page_hook = add_submenu_page(
			'woocommerce',
			__( 'Store Scraper', 'swi' ),
			__( 'Store Scraper', 'swi' ),
			'manage_woocommerce',
			'swi-importer',
			array( $this, 'render' )
		);
	}

	/**
	 * Enqueue assets only on our page.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue( $hook ) {
		if ( $hook !== $this->page_hook ) {
			return;
		}

		wp_enqueue_style( 'swi-admin', SWI_URL . 'assets/css/admin.css', array(), SWI_VERSION );
		wp_enqueue_script( 'swi-admin', SWI_URL . 'assets/js/admin.js', array( 'jquery' ), SWI_VERSION, true );

		wp_localize_script(
			'swi-admin',
			'swi',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'swi_nonce' ),
				'delay'   => (int) SWI_Plugin::get_option( 'delay', 1 ),
			)
		);
	}

	/**
	 * Render the admin page.
	 */
	public function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'swi' ) );
		}

		$url      = SWI_Plugin::get_option( 'target_url' );
		$platform = SWI_Plugin::get_option( 'platform' );

		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'products';
		if ( ! in_array( $tab, array( 'settings', 'products' ), true ) ) {
			$tab = 'products';
		}

		$settings_url = admin_url( 'admin.php?page=swi-importer&tab=settings' );
		$products_url = admin_url( 'admin.php?page=swi-importer&tab=products' );

		?>
		<div class="wrap swi-wrap">
			<h1><?php esc_html_e( 'Shopify / WooCommerce Scraper Importer', 'swi' ); ?></h1>

			<?php if ( isset( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'swi' ); ?></p></div>
			<?php endif; ?>

			<nav class="nav-tab-wrapper">
				<a href="<?php echo esc_url( $products_url ); ?>" class="nav-tab <?php echo 'products' === $tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Products', 'swi' ); ?>
				</a>
				<a href="<?php echo esc_url( $settings_url ); ?>" class="nav-tab <?php echo 'settings' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Settings', 'swi' ); ?></a>
			</nav>

			<?php if ( 'settings' === $tab ) : ?>
				<?php $this->render_settings_card(); ?>
				<?php $this->render_debug_card(); ?>
			<?php else : ?>
				<?php $this->render_products_card( $url, $platform ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the settings card.
	 */
	private function render_settings_card() {
		?>
		<div class="swi-card">
			<h2><?php esc_html_e( 'Settings', 'swi' ); ?></h2>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="swi_save_settings" />
				<?php wp_nonce_field( 'swi_settings' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="swi_delay"><?php esc_html_e( 'Request delay (seconds)', 'swi' ); ?></label></th>
						<td>
							<input type="number" name="swi_delay" id="swi_delay" min="0" max="10" step="1" value="<?php echo esc_attr( (int) SWI_Plugin::get_option( 'delay', 1 ) ); ?>" />
							<p class="description"><?php esc_html_e( 'Pause between products to be polite to the source server.', 'swi' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Images', 'swi' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="swi_include_images" value="1" <?php checked( (int) SWI_Plugin::get_option( 'include_images', 1 ), 1 ); ?> />
								<?php esc_html_e( 'Download product images into the media library', 'swi' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Publishing', 'swi' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="swi_auto_publish" value="1" <?php checked( (int) SWI_Plugin::get_option( 'auto_publish', 0 ), 1 ); ?> />
								<?php esc_html_e( 'Publish imported products immediately', 'swi' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'When unchecked, imported products are saved as drafts for your review before going live.', 'swi' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'SSL verification', 'swi' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="swi_sslverify" value="1" <?php checked( (int) SWI_Plugin::get_option( 'sslverify', 1 ), 1 ); ?> />
								<?php esc_html_e( 'Verify SSL certificates when fetching stores and images (recommended)', 'swi' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Only disable this if a source store has an invalid certificate.', 'swi' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Debug logging', 'swi' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="swi_debug" value="1" <?php checked( (int) SWI_Plugin::get_option( 'debug', 0 ), 1 ); ?> />
								<?php esc_html_e( 'Enable debug logging (writes diagnostics to the uploads folder)', 'swi' ); ?>
							</label>
							<p class="description"><?php echo esc_html( SWI_Debug::log_path() ); ?></p>
						</td>
					</tr>
				</table>

				<p class="submit">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save settings', 'swi' ); ?></button>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Render the debug log viewer card.
	 */
	private function render_debug_card() {
		$log_url = SWI_Debug::log_url();
		?>
		<div class="swi-card">
			<h2><?php esc_html_e( 'Debug log', 'swi' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Shows the last import actions and any errors. Enable "Debug logging" above, run an import, then refresh this log to see exactly where it stopped.', 'swi' ); ?>
			</p>
			<textarea id="swi-debug-log" rows="20" class="widefat" readonly spellcheck="false" style="font-family: monospace; font-size: 12px; white-space: pre; overflow-wrap: normal; overflow-x: auto;"></textarea>
			<p class="submit" style="margin: 8px 0 0;">
				<button type="button" class="button" id="swi-debug-refresh"><?php esc_html_e( 'Refresh', 'swi' ); ?></button>
				<?php if ( $log_url ) : ?>
					<a class="button" href="<?php echo esc_url( $log_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open raw log', 'swi' ); ?></a>
				<?php endif; ?>
				<button type="button" class="button" id="swi-debug-clear"><?php esc_html_e( 'Clear log', 'swi' ); ?></button>
			</p>
		</div>
		<?php
	}

	/**
	 * Render the source store URL form (Store URL + Detect platform + Discover).
	 *
	 * @param string $url      Stored target URL.
	 * @param string $platform Detected platform.
	 */
	private function render_source_form( $url, $platform ) {
		?>
		<div class="swi-source">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="swi_target_url"><?php esc_html_e( 'Store URL', 'swi' ); ?></label></th>
					<td>
						<input type="text" id="swi_target_url" class="regular-text" value="<?php echo esc_attr( $url ); ?>" placeholder="https://example-store.com" />
						<button type="button" class="button button-secondary" id="swi-detect"><?php esc_html_e( 'Detect platform', 'swi' ); ?></button>
						<button type="button" class="button button-primary" id="swi-discover"<?php echo $platform ? '' : ' style="display:none;"'; ?>><?php esc_html_e( 'Discover products', 'swi' ); ?></button>
						<span id="swi-platform-badge" class="swi-badge <?php echo esc_attr( $platform ? 'swi-badge--' . $platform : '' ); ?>">
							<?php echo $platform ? esc_html( ucfirst( $platform ) ) : ''; ?>
						</span>
						<p class="description"><?php esc_html_e( 'Enter the homepage URL of the Shopify or WooCommerce store you want to scrape. Discovered products are shown in memory only and are discarded as soon as you leave this screen.', 'swi' ); ?></p>
					</td>
				</tr>
			</table>
			<p class="submit">
				<span id="swi-discover-status" class="swi-status"></span>
			</p>
		</div>
		<?php
	}

	/**
	 * Render the products card (client-rendered list + import progress).
	 *
	 * @param string $url      Stored target URL.
	 * @param string $platform Detected platform.
	 */
	private function render_products_card( $url, $platform ) {
		?>
		<div class="swi-card">
			<h2><?php esc_html_e( 'Products', 'swi' ); ?></h2>

			<?php $this->render_source_form( $url, $platform ); ?>

			<div class="notice notice-info" id="swi-empty" style="margin: 8px 0 16px; padding: 10px 12px;">
				<p style="margin: 0;"><?php esc_html_e( 'No products discovered yet. Enter a store URL above and click "Discover products".', 'swi' ); ?></p>
			</div>

			<div id="swi-results" style="display:none;">
				<p>
					<span id="swi-count-text" class="swi-status" style="margin-left:0;"></span>
					<button type="button" class="button button-link-delete" id="swi-clear-products"><?php esc_html_e( 'Clear products', 'swi' ); ?></button>
				</p>

				<table class="widefat striped swi-products">
					<thead>
						<tr>
							<td class="swi-col-check">
								<input type="checkbox" id="swi-select-all" />
							</td>
							<th><?php esc_html_e( 'Image', 'swi' ); ?></th>
							<th><?php esc_html_e( 'Title', 'swi' ); ?></th>
							<th><?php esc_html_e( 'Price', 'swi' ); ?></th>
							<th><?php esc_html_e( 'Status', 'swi' ); ?></th>
						</tr>
					</thead>
					<tbody id="swi-tbody"></tbody>
				</table>

				<p class="submit">
					<button type="button" class="button button-primary button-hero" id="swi-start"><?php esc_html_e( 'Import selected (0)', 'swi' ); ?></button>
					<button type="button" class="button button-secondary button-hero" id="swi-import-all" style="display:none;"><?php esc_html_e( 'Import all (0)', 'swi' ); ?></button>
				</p>

				<div id="swi-progress" class="swi-progress" style="display:none;">
					<div class="swi-progress__bar"><div class="swi-progress__fill" id="swi-progress-fill" style="width:0%"></div></div>
					<div class="swi-progress__meta">
						<span id="swi-progress-text">0 / 0</span>
						<span id="swi-progress-summary"></span>
					</div>
				</div>

				<div class="swi-complete notice notice-success" id="swi-complete" style="display:none; margin: 12px 0; padding: 12px;">
					<p style="margin: 0 0 8px;"><strong id="swi-complete-text"></strong></p>
					<a class="button button-primary" href="<?php echo esc_url( admin_url( 'edit.php?post_type=product' ) ); ?>"><?php esc_html_e( 'View imported products', 'swi' ); ?></a>
					<button type="button" class="button" id="swi-reset-import"><?php esc_html_e( 'Import again', 'swi' ); ?></button>
				</div>

				<ul id="swi-log" class="swi-log" style="display:none;"></ul>

				<p>
					<button type="button" class="button button-secondary" id="swi-cancel" style="display:none;"><?php esc_html_e( 'Cancel', 'swi' ); ?></button>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Save settings (admin-post). The store URL is NOT managed here — it is
	 * handled live by the detect/discover flow on the Products tab.
	 */
	public function handle_save_settings() {
		check_admin_referer( 'swi_settings' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'swi' ) );
		}

		SWI_Plugin::set_option( 'delay', max( 0, min( 10, (int) ( $_POST['swi_delay'] ?? 1 ) ) ) );
		SWI_Plugin::set_option( 'include_images', isset( $_POST['swi_include_images'] ) ? 1 : 0 );
		SWI_Plugin::set_option( 'auto_publish', isset( $_POST['swi_auto_publish'] ) ? 1 : 0 );
		SWI_Plugin::set_option( 'sslverify', isset( $_POST['swi_sslverify'] ) ? 1 : 0 );
		SWI_Plugin::set_option( 'debug', isset( $_POST['swi_debug'] ) ? 1 : 0 );

		wp_safe_redirect( add_query_arg( 'saved', '1', admin_url( 'admin.php?page=swi-importer&tab=settings' ) ) );
		exit;
	}

	/**
	 * Verify an AJAX request.
	 *
	 * @return bool
	 */
	private function verify_ajax() {
		check_ajax_referer( 'swi_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'swi' ) ), 403 );
		}
		return true;
	}

	/**
	 * AJAX: detect platform.
	 */
	public function ajax_swi_detect() {
		$this->verify_ajax();

		$url = isset( $_POST['url'] ) ? sanitize_text_field( wp_unslash( $_POST['url'] ) ) : SWI_Plugin::get_option( 'target_url' );

		SWI_Plugin::set_option( 'target_url', $url );

		$result = SWI_Detector::detect( $url );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		SWI_Plugin::set_option( 'platform', $result['platform'] );

		wp_send_json_success( $result );
	}

	/**
	 * AJAX: discover products.
	 *
	 * Returns the product list directly to the browser. Nothing is written to
	 * the database — the list is held in JS memory and discarded on leaving
	 * the screen.
	 */
	public function ajax_swi_discover() {
		$this->verify_ajax();

		if ( isset( $_POST['url'] ) && '' !== trim( (string) $_POST['url'] ) ) {
			SWI_Plugin::set_option( 'target_url', sanitize_text_field( wp_unslash( $_POST['url'] ) ) );
		}

		$url      = SWI_Plugin::get_option( 'target_url' );
		$platform = SWI_Plugin::get_option( 'platform' );

		if ( ! $url ) {
			wp_send_json_error( array( 'message' => __( 'Please enter a store URL first.', 'swi' ) ) );
		}

		if ( ! $platform ) {
			$detect = SWI_Detector::detect( $url );
			if ( is_wp_error( $detect ) ) {
				wp_send_json_error( array( 'message' => $detect->get_error_message() ) );
			}
			$platform = $detect['platform'];
			SWI_Plugin::set_option( 'platform', $platform );
		}

		// 1. Try the bulk JSON endpoint first (fast, full previews).
		$bulk     = SWI_Crawler::bulk_products( $url, $platform );
		$bulk_ok  = ! is_wp_error( $bulk ) && ! empty( $bulk );
		$products = $bulk_ok ? $bulk : array();

		// 2. Fall back to sitemap discovery (URLs only, previews loaded later).
		if ( ! $bulk_ok ) {
			$urls = SWI_Crawler::discover( $url, $platform );
			if ( is_wp_error( $urls ) ) {
				wp_send_json_error( array( 'message' => $urls->get_error_message() ) );
			}
			foreach ( $urls as $u ) {
				$products[] = array(
					'source_url' => $u,
					'title'      => '',
					'thumbnail'  => '',
					'price'      => '',
					'currency'   => '',
				);
			}
		}

		if ( empty( $products ) ) {
			wp_send_json_error( array( 'message' => __( 'No products were discovered on this site.', 'swi' ) ) );
		}

		wp_send_json_success(
			array(
				'count'    => count( $products ),
				'products' => $products,
				'previews' => $bulk_ok,
				'source'   => $bulk_ok ? 'bulk' : 'sitemap',
				'platform' => $platform,
			)
		);
	}

	/**
	 * AJAX: preview a single product page (title / image / price).
	 *
	 * Used only when the bulk endpoint was unavailable (sitemap fallback) so
	 * the selection table can still show previews, loaded one-by-one.
	 */
	public function ajax_swi_preview() {
		$this->verify_ajax();

		$url = isset( $_POST['url'] ) ? sanitize_text_field( wp_unslash( $_POST['url'] ) ) : '';
		if ( '' === $url ) {
			wp_send_json_error( array( 'message' => __( 'No URL provided.', 'swi' ) ) );
		}

		$preview = SWI_Scraper::preview( $url );
		if ( is_wp_error( $preview ) ) {
			wp_send_json_success(
				array(
					'title'    => '',
					'image'    => '',
					'price'    => '',
					'currency' => '',
					'error'    => $preview->get_error_message(),
				)
			);
		}

		wp_send_json_success( $preview );
	}

	/**
	 * AJAX: import a single product by URL.
	 *
	 * Stateless: the browser drives the loop, sending one URL at a time. The
	 * product is scraped and imported into WooCommerce, then the result is
	 * returned. No queue/table state is kept on the server.
	 */
	public function ajax_swi_import_one() {
		$this->verify_ajax();

		$url      = isset( $_POST['url'] ) ? sanitize_text_field( wp_unslash( $_POST['url'] ) ) : '';
		$platform = isset( $_POST['platform'] ) ? sanitize_key( wp_unslash( $_POST['platform'] ) ) : SWI_Plugin::get_option( 'platform' );

		if ( '' === $url ) {
			wp_send_json_error( array( 'status' => 'error', 'message' => __( 'No URL provided.', 'swi' ) ) );
		}

		// Capture PHP fatal errors (memory exhaustion, timeouts, etc.).
		register_shutdown_function(
			function () {
				$error = error_get_last();
				if ( $error && in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ), true ) ) {
					SWI_Debug::log( 'FATAL ERROR during import_one', $error );
				}
			}
		);

		$data = SWI_Scraper::scrape( $url, $platform );
		if ( is_wp_error( $data ) ) {
			SWI_Debug::log( 'import_one: scrape failed', array( 'url' => $url, 'error' => $data->get_error_message() ) );
			wp_send_json_success(
				array(
					'status'  => 'error',
					'title'   => $url,
					'message' => $data->get_error_message(),
				)
			);
		}

		try {
			$existed    = (bool) SWI_Importer::find_existing( $url );
			$product_id = SWI_Importer::import( $data, $url );
			$title      = ! empty( $data['title'] ) ? $data['title'] : $url;

			SWI_Debug::log( 'import_one: import OK', array( 'url' => $url, 'product_id' => $product_id, 'existed' => $existed ) );

			// NOTE: the polite delay between products is applied client-side
			// (see swi.delay in admin.js) so no server worker is blocked here.
			wp_send_json_success(
				array(
					'status'     => $existed ? 'updated' : 'imported',
					'title'      => $title,
					'product_id' => $product_id,
				)
			);
		} catch ( \Throwable $e ) {
			SWI_Debug::log(
				'import_one: import threw',
				array(
					'url'   => $url,
					'error' => $e->getMessage(),
					'file'  => $e->getFile(),
					'line'  => $e->getLine(),
				)
			);
			wp_send_json_success(
				array(
					'status'  => 'error',
					'title'   => $url,
					'message' => $e->getMessage(),
				)
			);
		}
	}

	/**
	 * AJAX: return the tail of the debug log.
	 */
	public function ajax_swi_debug_log() {
		$this->verify_ajax();

		wp_send_json_success(
			array(
				'log'     => SWI_Debug::tail( 400 ),
				'path'    => SWI_Debug::log_path(),
				'url'     => SWI_Debug::log_url(),
				'enabled' => SWI_Debug::enabled(),
			)
		);
	}

	/**
	 * AJAX: clear the debug log.
	 */
	public function ajax_swi_clear_log() {
		$this->verify_ajax();

		SWI_Debug::clear();

		wp_send_json_success();
	}
}
