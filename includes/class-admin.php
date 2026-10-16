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
			'swi_train_analyze',
			'swi_train_save',
			'swi_profiles_list',
			'swi_profile_delete',
			'swi_universal_scrape',
			'swi_ai_fix',
			'swi_ai_fix_train',
			'swi_universal_catalog',
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

		// Use file modification times as the asset version so a hot-patched
		// admin.js/admin.css is picked up immediately (browser cache busting)
		// without needing a plugin version bump.
		$css_ver = (string) @filemtime( SWI_DIR . 'assets/css/admin.css' );
		$js_ver  = (string) @filemtime( SWI_DIR . 'assets/js/admin.js' );

		wp_enqueue_style( 'swi-admin', SWI_URL . 'assets/css/admin.css', array(), $css_ver );
		wp_enqueue_script( 'swi-admin', SWI_URL . 'assets/js/admin.js', array( 'jquery' ), $js_ver, true );

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
		if ( ! in_array( $tab, array( 'settings', 'products', 'universal' ), true ) ) {
			$tab = 'products';
		}

		$settings_url = admin_url( 'admin.php?page=swi-importer&tab=settings' );
		$products_url = admin_url( 'admin.php?page=swi-importer&tab=products' );
		$universal_url = admin_url( 'admin.php?page=swi-importer&tab=universal' );

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
				<a href="<?php echo esc_url( $universal_url ); ?>" class="nav-tab <?php echo 'universal' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Universal Website', 'swi' ); ?></a>
			</nav>

			<?php if ( 'settings' === $tab ) : ?>
				<?php $this->render_settings_card(); ?>
				<?php $this->render_debug_card(); ?>
			<?php elseif ( 'universal' === $tab ) : ?>
				<?php $this->render_universal_card(); ?>
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
						<th scope="row"><label for="swi_model_provider"><?php esc_html_e( 'AI model provider', 'swi' ); ?></label></th>
						<td>
							<select name="swi_model_provider" id="swi_model_provider">
								<option value="gemini" <?php selected( SWI_Plugin::get_option( 'model_provider', 'gemini' ), 'gemini' ); ?>><?php esc_html_e( 'Gemini (recommended — best quality)', 'swi' ); ?></option>
								<option value="deepseek" <?php selected( SWI_Plugin::get_option( 'model_provider', 'gemini' ), 'deepseek' ); ?>><?php esc_html_e( 'DeepSeek (cheaper)', 'swi' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Which LLM analyzes product HTML and fixes profiles. DeepSeek is cheaper; Gemini handles hard-to-scrape sites more reliably.', 'swi' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="swi_render_url"><?php esc_html_e( 'JS render proxy URL', 'swi' ); ?></label></th>
						<td>
							<input type="url" name="swi_render_url" id="swi_render_url" class="regular-text" value="<?php echo esc_attr( SWI_Plugin::get_option( 'render_url', '' ) ); ?>" placeholder="http://127.0.0.1:8191" />
							<p class="description"><?php esc_html_e( 'Optional. A FlareSolverr-compatible endpoint used to render JavaScript and bypass bot challenges (e.g. Cloudflare) on source stores. Leave blank to disable.', 'swi' ); ?></p>
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
						<button type="button" class="button button-primary" id="swi-discover"><?php esc_html_e( 'Discover products', 'swi' ); ?></button>
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
	 * Render the "Universal Website" card: list trained sites, add a new one,
	 * and scrape a single product from a trained site.
	 */
	private function render_universal_card() {
		?>
		<div class="swi-card">
			<div class="swi-universal-head">
				<div>
					<h2 style="margin:0;"><?php esc_html_e( 'Universal Website', 'swi' ); ?></h2>
					<p class="description" style="margin:4px 0 0;"><?php esc_html_e( 'Websites the AI has learned to scrape (non-Shopify / non-WooCommerce stores). Select a website to scrape and import a single product from it.', 'swi' ); ?></p>
				</div>
				<button type="button" class="button button-primary" id="swi-universal-add-toggle"><?php esc_html_e( 'Add new website', 'swi' ); ?></button>
			</div>

			<div id="swi-universal-list" style="margin-top:16px;"></div>

			<div class="swi-card swi-card--inset" id="swi-universal-add" style="display:none;">
				<h2><?php esc_html_e( 'Add a new website', 'swi' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Give the AI one simple and one variable product URL. It learns the site\'s product structure and maps it to your store\'s product fields. After saving, the website appears in the list above.', 'swi' ); ?></p>

				<div class="swi-train-grid">
					<div class="swi-train-col">
						<h3><?php esc_html_e( '1. Simple product sample', 'swi' ); ?></h3>
						<label class="swi-field-label" for="swi-train-simple-url"><?php esc_html_e( 'Product URL', 'swi' ); ?></label>
						<input type="text" id="swi-train-simple-url" class="regular-text" placeholder="https://site.com/products/tee" />
					</div>

					<div class="swi-train-col">
						<h3><?php esc_html_e( '2. Variable product sample (optional)', 'swi' ); ?></h3>
						<label class="swi-field-label" for="swi-train-variable-url"><?php esc_html_e( 'Product URL', 'swi' ); ?></label>
						<input type="text" id="swi-train-variable-url" class="regular-text" placeholder="https://site.com/products/shoes" />
					</div>
				</div>

				<label class="swi-field-label" for="swi-train-domain"><?php esc_html_e( 'Site domain (used as the profile key)', 'swi' ); ?></label>
				<input type="text" id="swi-train-domain" class="regular-text" placeholder="example-store.com" />
				<p class="description"><?php esc_html_e( 'Optional — auto-detected from the URL if left blank.', 'swi' ); ?></p>

				<p class="submit">
					<button type="button" class="button button-primary button-hero" id="swi-train-analyze"><?php esc_html_e( 'Analyze samples', 'swi' ); ?></button>
					<span id="swi-train-status" class="swi-status"></span>
				</p>

				<div class="swi-card swi-card--inset" id="swi-train-result" style="display:none;">
					<h2><?php esc_html_e( 'Review & save the profile', 'swi' ); ?></h2>

					<div class="swi-scraped-panel">
						<h3><?php esc_html_e( 'Scraped data', 'swi' ); ?></h3>
						<div id="swi-train-scraped"></div>
					</div>

					<p style="margin:12px 0 4px;">
						<button type="button" class="button button-link" id="swi-train-profile-toggle"><?php esc_html_e( 'Show profile JSON (advanced)', 'swi' ); ?></button>
					</p>
					<div id="swi-train-profile-wrap" style="display:none;">
						<label class="swi-field-label" for="swi-train-profile"><?php esc_html_e( 'Scraping profile (JSON — edit if needed)', 'swi' ); ?></label>
						<textarea id="swi-train-profile" class="swi-train-profile" rows="18" spellcheck="false"></textarea>
					</div>

					<div class="swi-fix-chat swi-fix-chat--train" style="margin-top:14px; border-top:1px solid #e2e4e7; padding-top:12px;">
						<label class="swi-field-label"><?php esc_html_e( 'Ask the AI to fix something', 'swi' ); ?></label>
						<div class="swi-fix-chat__messages" style="max-height:260px; overflow-y:auto; margin-bottom:10px;"></div>
						<div class="swi-fix-chat__input-row" style="display:flex; gap:8px;">
							<input type="text" class="swi-fix-chat__input regular-text" placeholder="e.g. the shade names are missing the volume…" style="flex:1;" />
							<button type="button" class="button swi-fix-chat__send"><?php esc_html_e( 'Send', 'swi' ); ?></button>
						</div>
						<p style="margin:8px 0 0;"><span class="swi-status swi-fix-chat__status"></span></p>
					</div>

					<p class="submit">
						<button type="button" class="button button-primary button-hero" id="swi-train-save"><?php esc_html_e( 'Save website', 'swi' ); ?></button>
						<span id="swi-train-save-status" class="swi-status"></span>
					</p>
				</div>
			</div>
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

				<div class="swi-products-cats" id="swi-categories"></div>

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

		$provider = isset( $_POST['swi_model_provider'] ) ? sanitize_key( wp_unslash( $_POST['swi_model_provider'] ) ) : 'gemini';
		SWI_Plugin::set_option( 'model_provider', ( 'deepseek' === $provider ) ? 'deepseek' : 'gemini' );

		$render_url = isset( $_POST['swi_render_url'] ) ? trim( (string) wp_unslash( $_POST['swi_render_url'] ) ) : '';
		SWI_Plugin::set_option( 'render_url', esc_url_raw( $render_url ) );

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

		$url = SWI_Plugin::get_option( 'target_url' );

		if ( ! $url ) {
			wp_send_json_error( array( 'message' => __( 'Please enter a store URL first.', 'swi' ) ) );
		}

		// Always detect the platform fresh from the current URL (there is no
		// separate "Detect platform" button anymore, and a stale stored value
		// would otherwise break discovery).
		$detect = SWI_Detector::detect( $url );
		if ( is_wp_error( $detect ) ) {
			wp_send_json_error( array( 'message' => $detect->get_error_message() ) );
		}
		$platform = $detect['platform'];
		SWI_Plugin::set_option( 'platform', $platform );

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
					'title'      => $this->title_from_product_url( $u ),
					'thumbnail'  => '',
					'price'      => '',
					'currency'   => '',
				);
			}
		}

		if ( empty( $products ) ) {
			wp_send_json_error( array( 'message' => __( 'No products were discovered on this site.', 'swi' ) ) );
		}

		$categories = $this->build_category_tree( $products, $platform, $url );

		wp_send_json_success(
			array(
				'count'      => count( $products ),
				'products'   => $products,
				'categories' => $categories,
				'previews'   => $bulk_ok,
				'source'     => $bulk_ok ? 'bulk' : 'sitemap',
				'platform'   => $platform,
			)
		);
	}

	/**
	 * Build a category tree (top-level categories → subcategories → products)
	 * for the folded "Discover products" list.
	 *
	 * WooCommerce uses the Store API category hierarchy; Shopify falls back to
	 * product_type as flat top-level groups.
	 *
	 * @param array  $products Flat product list.
	 * @param string $platform Platform slug.
	 * @param string $url      Base URL.
	 * @return array Category tree.
	 */
	private function build_category_tree( $products, $platform, $url ) {
		if ( 'woocommerce' !== $platform ) {
			// Shopify / generic: group by first category name (product_type).
			$groups = array();
			foreach ( $products as $p ) {
				$name = ! empty( $p['categories'][0]['name'] ) ? $p['categories'][0]['name'] : __( 'Uncategorized', 'swi' );
				if ( ! isset( $groups[ $name ] ) ) {
					$groups[ $name ] = array();
				}
				$groups[ $name ][] = $p;
			}
			$tree = array();
			foreach ( $groups as $name => $items ) {
				$tree[] = array(
					'id'       => 0,
					'name'     => $name,
					'count'    => count( $items ),
					'children' => $items,
				);
			}
			return $tree;
		}

		// WooCommerce: map category id → product list (products attach to their
		// deepest category), then assemble a hierarchy with a recursive helper.
		$cats = SWI_Crawler::woocommerce_categories( $url );

		$by_id = array(); // id => [name,parent,count]
		foreach ( $cats as $c ) {
			$by_id[ $c['id'] ] = array(
				'id'     => $c['id'],
				'name'   => $c['name'],
				'parent' => $c['parent'],
				'count'  => $c['count'],
			);
		}

		// Orphaned categories (their parent lives on a later API page) would be
		// dropped from the tree — promote them to top-level so their products
		// still appear.
		foreach ( $by_id as $id => $cat ) {
			if ( (int) $cat['parent'] !== 0 && ! isset( $by_id[ (int) $cat['parent'] ] ) ) {
				$by_id[ $id ]['parent'] = 0;
			}
		}

		// category id → list of products assigned directly to it.
		$cat_products  = array();
		$uncategorized = array();
		foreach ( $products as $p ) {
			$ids = array();
			foreach ( (array) $p['categories'] as $pc ) {
				if ( ! empty( $pc['id'] ) && isset( $by_id[ (int) $pc['id'] ] ) ) {
					$ids[] = (int) $pc['id'];
				}
			}
			if ( empty( $ids ) ) {
				$uncategorized[] = $p;
				continue;
			}
			$deepest = end( $ids );
			if ( ! isset( $cat_products[ $deepest ] ) ) {
				$cat_products[ $deepest ] = array();
			}
			$cat_products[ $deepest ][] = $p;
		}

		// Build the tree (by reference to parent id). Each node's `children`
		// mixes subcategory nodes and product leaves.
		$build = function ( $parent_id ) use ( &$build, $by_id, $cat_products ) {
			$out = array();
			foreach ( $by_id as $id => $cat ) {
				if ( (int) $cat['parent'] !== (int) $parent_id ) {
					continue;
				}
				$node = array(
					'id'       => $id,
					'name'     => $cat['name'],
					'count'    => $cat['count'],
					'children' => $build( $id ),
				);
				// Append products attached directly to this category.
				if ( isset( $cat_products[ $id ] ) ) {
					foreach ( $cat_products[ $id ] as $p ) {
						$node['children'][] = $p;
					}
				}
				$out[] = $node;
			}
			return $out;
		};

		$tree = $build( 0 );

		if ( ! empty( $uncategorized ) ) {
			$tree[] = array(
				'id'       => -1,
				'name'     => __( 'Uncategorized', 'swi' ),
				'count'    => count( $uncategorized ),
				'children' => $uncategorized,
			);
		}

		return $tree;
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
	 * AJAX: analyze sample product HTML via the AI endpoint.
	 *
	 * Accepts URL(s) and/or uploaded .html file(s) and/or pasted HTML for the
	 * simple + variable samples, fetches any URLs server-side, sends the HTML
	 * to the AI endpoint, and returns the resulting profile plus a preview of
	 * the fields it would extract.
	 */
	public function ajax_swi_train_analyze() {
		$this->verify_ajax();

		$html_simple   = $this->train_sample_html( 'simple' );
		$html_variable = $this->train_sample_html( 'variable' );

		if ( is_wp_error( $html_simple ) ) {
			wp_send_json_error( array( 'message' => $html_simple->get_error_message() ) );
		}
		if ( is_wp_error( $html_variable ) ) {
			wp_send_json_error( array( 'message' => $html_variable->get_error_message() ) );
		}

		if ( '' === $html_simple && '' === $html_variable ) {
			wp_send_json_error( array( 'message' => __( 'Provide a URL, an uploaded .html file, or pasted HTML for at least one sample product.', 'swi' ) ) );
		}

		// Sample URL (used to absolutize relative images + as the audit base).
		$base = isset( $_POST['variable_url'] ) && '' !== trim( (string) $_POST['variable_url'] ) ? (string) $_POST['variable_url'] : ( isset( $_POST['simple_url'] ) ? (string) $_POST['simple_url'] : '' );

		// Generate the profile with a full agent loop: the AI investigates the
		// HTML with tools (search / run / inspect) until it's confident the
		// profile extracts everything correctly.
		$profile = SWI_AI::analyze_with_agent( $html_simple, $html_variable, $base );
		if ( is_wp_error( $profile ) ) {
			wp_send_json_error( array( 'message' => $profile->get_error_message() ) );
		}

		// Validate with the local profile model (drops unsupported rules).
		$valid = SWI_Profile::validate( $profile );
		if ( is_wp_error( $valid ) ) {
			wp_send_json_error( array( 'message' => $valid->get_error_message() ) );
		}

		// Domain: explicit input wins, else derived from a sample URL.
		$domain = isset( $_POST['domain'] ) ? sanitize_text_field( wp_unslash( $_POST['domain'] ) ) : '';
		$domain = SWI_Profile::normalize_domain( $domain );
		if ( '' === $domain ) {
			$base_url = '' !== $base ? $base : ( isset( $_POST['simple_url'] ) && '' !== trim( (string) $_POST['simple_url'] ) ? (string) $_POST['simple_url'] : '' );
			$domain   = SWI_Profile::normalize_domain( $base_url );
		}

		// Preview: apply the profile to whichever sample HTML we have, and build
		// BOTH the raw "scraped data" (left panel) and our editable "product
		// data sheet" (right panel).
		$preview = array();
		$scraped = array();
		$sheet   = array();
		$locked  = array();
		$sample  = ( '' !== $html_variable ) ? $html_variable : $html_simple;

		if ( '' !== $sample ) {
			$data = SWI_Generic_Scraper::scrape_html( $sample, $base, $valid );
			if ( ! is_wp_error( $data ) ) {
				$preview = $this->preview_summary( $data );
				$scraped = $data;
				$sheet   = $this->product_sheet( $data );
			}
		}

		// Restore any persisted manual edits (locked fields) for this product,
		// so re-analyzing the same sample doesn't wipe the user's corrections.
		if ( '' !== $base ) {
			$edits  = $this->get_sheet_edits( $domain, $base );
			$locked = $edits['locked'];
			if ( ! empty( $edits['locked'] ) && ! empty( $sheet ) ) {
				$sheet = $this->apply_sheet_locks( $sheet, $edits );
			}
		}

		wp_send_json_success(
			array(
				'domain'  => $domain,
				'profile' => $valid,
				'preview' => $preview,
				'scraped' => $scraped,
				'sheet'   => $sheet,
				'locked'  => $locked,
			)
		);
	}

	/**
	 * AJAX: save a scraping profile for a domain.
	 */
	public function ajax_swi_train_save() {
		$this->verify_ajax();

		$domain  = isset( $_POST['domain'] ) ? sanitize_text_field( wp_unslash( $_POST['domain'] ) ) : '';
		$profile = isset( $_POST['profile'] ) ? wp_unslash( $_POST['profile'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized — JSON, validated below.

		$decoded = json_decode( $profile, true );
		if ( ! is_array( $decoded ) || JSON_ERROR_NONE !== json_last_error() ) {
			wp_send_json_error( array( 'message' => __( 'The profile is not valid JSON.', 'swi' ) ) );
		}

		// Persist the site base URL (derived from the sample URL) so "Show
		// Products" knows where to crawl later.
		$sample_url = isset( $_POST['variable_url'] ) && '' !== trim( (string) $_POST['variable_url'] ) ? (string) $_POST['variable_url'] : ( isset( $_POST['simple_url'] ) ? (string) $_POST['simple_url'] : '' );
		if ( '' !== $sample_url ) {
			$decoded['base_url'] = SWI_Profile::base_url( $sample_url );
		}

		$saved = SWI_Profile::save( $domain, $decoded );
		if ( is_wp_error( $saved ) ) {
			wp_send_json_error( array( 'message' => $saved->get_error_message() ) );
		}

		// Persist the edited product sheet (and locked/correct fields) so the
		// user's manual corrections survive a re-analyze of the same product.
		$sheet_raw = isset( $_POST['sheet'] ) ? wp_unslash( $_POST['sheet'] ) : '';
		$locked    = isset( $_POST['locked'] ) ? json_decode( wp_unslash( $_POST['locked'] ), true ) : array();
		$locked    = is_array( $locked ) ? $locked : array();
		$sheet     = json_decode( $sheet_raw, true );
		$sheet     = is_array( $sheet ) ? $sheet : array();

		$sample_url = isset( $_POST['variable_url'] ) && '' !== trim( (string) $_POST['variable_url'] ) ? (string) $_POST['variable_url'] : ( isset( $_POST['simple_url'] ) ? (string) $_POST['simple_url'] : '' );
		$norm       = SWI_Profile::normalize_domain( '' !== $sample_url ? $sample_url : $domain );
		if ( '' !== $sample_url ) {
			$this->save_sheet_edits( $norm, $sample_url, $sheet, $locked );
		}

		wp_send_json_success(
			array(
				'message' => __( 'Website saved.', 'swi' ),
				'domain'  => $saved['domain'],
			)
		);
	}

	/**
	 * AJAX: list every saved universal-website profile.
	 */
	public function ajax_swi_profiles_list() {
		$this->verify_ajax();

		$profiles = SWI_Profile::all();
		$list     = array();

		foreach ( $profiles as $domain => $profile ) {
			$list[] = array(
				'domain'     => (string) $domain,
				'trained_at' => isset( $profile['trained_at'] ) ? (string) $profile['trained_at'] : '',
			);
		}

		// Newest first.
		usort(
			$list,
			function ( $a, $b ) {
				return strcmp( (string) $b['trained_at'], (string) $a['trained_at'] );
			}
		);

		wp_send_json_success( array( 'profiles' => $list ) );
	}

	/**
	 * AJAX: delete a saved universal-website profile.
	 */
	public function ajax_swi_profile_delete() {
		$this->verify_ajax();

		$domain = isset( $_POST['domain'] ) ? sanitize_text_field( wp_unslash( $_POST['domain'] ) ) : '';
		if ( '' === $domain ) {
			wp_send_json_error( array( 'message' => __( 'No website specified.', 'swi' ) ) );
		}

		$deleted = SWI_Profile::delete( $domain );
		if ( ! $deleted ) {
			wp_send_json_error( array( 'message' => __( 'That website has no saved profile.', 'swi' ) ) );
		}

		wp_send_json_success( array( 'message' => __( 'Website removed.', 'swi' ) ) );
	}

	/**
	 * AJAX: scrape and import a single product URL using a trained profile.
	 *
	 * The "Universal Website" flow: pick a website, paste one product URL, and
	 * it is scraped with the saved profile and imported into WooCommerce.
	 */
	public function ajax_swi_universal_scrape() {
		$this->verify_ajax();

		$url    = isset( $_POST['url'] ) ? sanitize_text_field( wp_unslash( $_POST['url'] ) ) : '';
		$domain = isset( $_POST['domain'] ) ? sanitize_text_field( wp_unslash( $_POST['domain'] ) ) : '';

		if ( '' === $url ) {
			wp_send_json_error( array( 'message' => __( 'Enter a product URL first.', 'swi' ) ) );
		}

		// Image processing options: white square background + padding.
		$white_bg = isset( $_POST['white_bg'] ) ? (int) $_POST['white_bg'] : 0;
		$padding  = isset( $_POST['padding'] ) ? (int) $_POST['padding'] : 0;
		$white_bg = in_array( $white_bg, array( 800, 1000 ), true ) ? $white_bg : 0;
		$padding  = in_array( $padding, array( 50, 60, 70, 80, 90 ), true ) ? $padding : 0;
		$options  = array(
			'white_bg' => $white_bg,
			'padding'  => $padding,
		);

		// Resolve the profile from the explicit domain, falling back to the URL.
		$profile = SWI_Profile::get( '' !== $domain ? $domain : $url );
		if ( null === $profile ) {
			wp_send_json_error( array( 'message' => __( 'No saved profile for this website. Add it first, then scrape.', 'swi' ) ) );
		}

		$data = SWI_Scraper::scrape( $url, 'generic' );
		if ( is_wp_error( $data ) ) {
			wp_send_json_error( array( 'message' => $data->get_error_message() ) );
		}

		try {
			$existed    = (bool) SWI_Importer::find_existing( $url );
			$product_id = SWI_Importer::import( $data, $url, $options );
			$title      = ! empty( $data['title'] ) ? $data['title'] : $url;

			wp_send_json_success(
				array(
					'status'     => $existed ? 'updated' : 'imported',
					'title'      => $title,
					'product_id' => $product_id,
					'preview'    => $this->preview_summary( $data ),
				)
			);
		} catch ( \Throwable $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	/**
	 * AJAX: list the products of a trained site, grouped into categories.
	 *
	 * Discovers product URLs via the site's sitemap and derives a category per
	 * product from its URL path (best-effort; no public category API exists for
	 * generic sites). Returns a tree of categories → products (URL + a
	 * humanized placeholder title), which the browser renders as an accordion.
	 */
	public function ajax_swi_universal_catalog() {
		$this->verify_ajax();

		$domain = isset( $_POST['domain'] ) ? sanitize_text_field( wp_unslash( $_POST['domain'] ) ) : '';
		if ( '' === $domain ) {
			wp_send_json_error( array( 'message' => __( 'No website specified.', 'swi' ) ) );
		}

		$profile = SWI_Profile::get( $domain );
		if ( null === $profile ) {
			wp_send_json_error( array( 'message' => __( 'No saved profile for this website.', 'swi' ) ) );
		}

		$base = ! empty( $profile['base_url'] ) ? (string) $profile['base_url'] : SWI_Profile::base_url( $domain );

		$urls = SWI_Crawler::discover_limited( $base, 'generic', 500 );
		if ( is_wp_error( $urls ) ) {
			wp_send_json_error( array( 'message' => $urls->get_error_message() ) );
		}

		$categories = array();
		foreach ( $urls as $url ) {
			$url   = trim( (string) $url );
			$cat   = $this->derive_product_category( $url );
			$title = $this->title_from_product_url( $url );

			$key = strtolower( $cat );
			if ( ! isset( $categories[ $key ] ) ) {
				$categories[ $key ] = array(
					'name'     => $cat,
					'products' => array(),
				);
			}
			$categories[ $key ]['products'][] = array(
				'url'   => $url,
				'title' => $title,
			);
		}

		// Order categories alphabetically, then products by title.
		uksort( $categories, 'strcasecmp' );
		foreach ( $categories as &$category ) {
			usort(
				$category['products'],
				function ( $a, $b ) {
					return strcasecmp( (string) $a['title'], (string) $b['title'] );
				}
			);
		}
		unset( $category );

		wp_send_json_success(
			array(
				'count'      => count( $urls ),
				'categories' => array_values( $categories ),
			)
		);
	}

	/**
	 * Derive a category label from a product URL path (best-effort for generic
	 * sites with no category API).
	 *
	 * Handles explicit category markers (/category/, /collections/,
	 * /catalogue/category/) and falls back to the first meaningful path segment
	 * (e.g. Notino's brand segment "dior"). Returns "All" when no structure.
	 *
	 * @param string $url Product URL.
	 * @return string
	 */
	private function derive_product_category( $url ) {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$path = trim( $path, '/' );
		if ( '' === $path ) {
			return 'All';
		}

		$segments = explode( '/', $path );

		// Drop a leading language segment ("en", "ar", "el", …).
		if ( isset( $segments[0] ) && preg_match( '/^[a-z]{2,3}(-[a-z]{2})?$/i', $segments[0] ) && count( $segments ) > 2 ) {
			array_shift( $segments );
		}

		// Explicit category markers.
		$markers = array( 'category', 'collections', 'catalogue' );
		foreach ( $segments as $i => $seg ) {
			$lower = strtolower( $seg );
			if ( 'catalogue' === $lower && isset( $segments[ $i + 1 ] ) && 'category' === strtolower( $segments[ $i + 1 ] ) ) {
				return $this->humanize_segment( isset( $segments[ $i + 2 ] ) ? $segments[ $i + 2 ] : 'All' );
			}
			if ( in_array( $lower, $markers, true ) && isset( $segments[ $i + 1 ] ) ) {
				return $this->humanize_segment( $segments[ $i + 1 ] );
			}
		}

		// Fallback: first meaningful segment (e.g. "dior" in Notino), but only
		// when there's a product slug after it.
		if ( count( $segments ) >= 2 ) {
			return $this->humanize_segment( $segments[0] );
		}

		return 'All';
	}

	/**
	 * Humanize a URL path segment into a display label.
	 *
	 * @param string $segment URL path segment.
	 * @return string
	 */
	private function humanize_segment( $segment ) {
		$segment = urldecode( (string) $segment );
		$segment = preg_replace( '/\.(html?|php)$/i', '', $segment );
		$segment = str_replace( array( '-', '_' ), ' ', $segment );
		$segment = trim( preg_replace( '/\s+/', ' ', $segment ) );
		return '' !== $segment ? ucwords( $segment ) : 'All';
	}

	/**
	 * Humanize a product URL into a placeholder title (real title is fetched at
	 * import time by the profile).
	 *
	 * @param string $url Product URL.
	 * @return string
	 */
	private function title_from_product_url( $url ) {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$path = trim( $path, '/' );
		$seg  = explode( '/', $path );
		$slug = end( $seg );
		return $this->humanize_segment( $slug );
	}

	/**
	 * AJAX: fix a scraping profile via the AI chat.
	 *
	 * The user describes a problem ("the shade names are wrong", "description
	 * has HTML entities"). The plugin loads the profile, re-scrapes the product
	 * (for the data the user is complaining about + the HTML to search), sends
	 * it to the AI fix endpoint, and returns a corrected profile + explanation
	 * + a preview of the corrected extraction.
	 */
	public function ajax_swi_ai_fix() {
		$this->verify_ajax();

		$domain      = isset( $_POST['domain'] ) ? sanitize_text_field( wp_unslash( $_POST['domain'] ) ) : '';
		$url         = isset( $_POST['url'] ) ? sanitize_text_field( wp_unslash( $_POST['url'] ) ) : '';
		$instruction = isset( $_POST['instruction'] ) ? sanitize_textarea_field( wp_unslash( $_POST['instruction'] ) ) : '';
		$history     = isset( $_POST['history'] ) ? json_decode( wp_unslash( $_POST['history'] ), true ) : array();
		$history     = is_array( $history ) ? $history : array();

		if ( '' === trim( $instruction ) ) {
			wp_send_json_error( array( 'message' => __( 'Describe the problem you want fixed.', 'swi' ) ) );
		}
		if ( '' === $url ) {
			wp_send_json_error( array( 'message' => __( 'Scrape the product first, then describe the problem.', 'swi' ) ) );
		}

		$profile = SWI_Profile::get( '' !== $domain ? $domain : $url );
		if ( null === $profile ) {
			wp_send_json_error( array( 'message' => __( 'No saved profile for this website.', 'swi' ) ) );
		}

		// Scrape the product (what the user sees) + fetch the HTML to search.
		$data = SWI_Scraper::scrape( $url, 'generic' );
		if ( is_wp_error( $data ) ) {
			wp_send_json_error( array( 'message' => $data->get_error_message() ) );
		}
		$html = SWI_HTTP::get( $url );
		if ( is_wp_error( $html ) ) {
			wp_send_json_error( array( 'message' => $html->get_error_message() ) );
		}

		// Compact the data we send to the AI (truncate long text, cap variants).
		$ai_data = $this->ai_data_snapshot( $data );

		$result = SWI_AI::fix_product( $instruction, $profile, $ai_data, $html, $history );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$valid = SWI_Profile::validate( $result['profile'] );
		if ( is_wp_error( $valid ) ) {
			wp_send_json_error( array( 'message' => $valid->get_error_message() ) );
		}

		// Preview the corrected profile against the same HTML.
		$preview = array();
		$fixed   = SWI_Generic_Scraper::scrape_html( $html, $url, $valid );
		if ( ! is_wp_error( $fixed ) ) {
			$preview = $this->preview_summary( $fixed );
		}

		wp_send_json_success(
			array(
				'profile'     => $valid,
				'explanation' => isset( $result['explanation'] ) ? (string) $result['explanation'] : '',
				'changes'     => isset( $result['changes'] ) ? $result['changes'] : array(),
				'preview'     => $preview,
			)
		);
	}

	/**
	 * AJAX: fix an UNSAVED profile from the "Add new website" flow.
	 *
	 * Called from the chat shown after "Analyze samples". The profile hasn't
	 * been saved yet (it lives in the textarea), so this re-fetches the sample
	 * HTML (URL or pasted HTML) and asks the AI to correct the profile from a
	 * natural-language instruction. Returns the corrected profile + preview.
	 */
	public function ajax_swi_ai_fix_train() {
		$this->verify_ajax();

		$instruction = isset( $_POST['instruction'] ) ? sanitize_textarea_field( wp_unslash( $_POST['instruction'] ) ) : '';
		$profile_raw = isset( $_POST['profile'] ) ? wp_unslash( $_POST['profile'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized — JSON, validated below.
		$history     = isset( $_POST['history'] ) ? json_decode( wp_unslash( $_POST['history'] ), true ) : array();
		$history     = is_array( $history ) ? $history : array();
		$sheet       = isset( $_POST['sheet'] ) ? json_decode( wp_unslash( $_POST['sheet'] ), true ) : array();
		$sheet       = is_array( $sheet ) ? $sheet : array();
		$locked      = isset( $_POST['locked'] ) ? json_decode( wp_unslash( $_POST['locked'] ), true ) : array();
		$locked      = is_array( $locked ) ? array_values( array_map( 'strval', $locked ) ) : array();

		if ( '' === trim( $instruction ) ) {
			wp_send_json_error( array( 'message' => __( 'Describe the problem you want fixed.', 'swi' ) ) );
		}

		$profile = json_decode( $profile_raw, true );
		if ( ! is_array( $profile ) || JSON_ERROR_NONE !== json_last_error() ) {
			wp_send_json_error( array( 'message' => __( 'The current profile is not valid JSON.', 'swi' ) ) );
		}

		// Re-fetch the sample HTML (same precedence as the analyze step).
		$html_simple   = $this->train_sample_html( 'simple' );
		$html_variable = $this->train_sample_html( 'variable' );
		if ( is_wp_error( $html_simple ) ) {
			wp_send_json_error( array( 'message' => $html_simple->get_error_message() ) );
		}
		if ( is_wp_error( $html_variable ) ) {
			wp_send_json_error( array( 'message' => $html_variable->get_error_message() ) );
		}

		$sample = '' !== $html_variable ? $html_variable : $html_simple;
		if ( '' === $sample ) {
			wp_send_json_error( array( 'message' => __( 'No sample HTML to work from. Re-run "Analyze samples" first.', 'swi' ) ) );
		}

		$base = isset( $_POST['variable_url'] ) && '' !== trim( (string) $_POST['variable_url'] ) ? (string) $_POST['variable_url'] : ( isset( $_POST['simple_url'] ) ? (string) $_POST['simple_url'] : '' );

		// Scrape the sample with the CURRENT profile to show the AI what's
		// coming out wrong.
		$data = SWI_Generic_Scraper::scrape_html( $sample, $base, $profile );
		if ( is_wp_error( $data ) ) {
			wp_send_json_error( array( 'message' => $data->get_error_message() ) );
		}

		$ai_data = $this->ai_data_snapshot( $data );

		$result = SWI_AI::fix_product( $instruction, $profile, $ai_data, $sample, $history, $sheet, $locked );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		$valid = SWI_Profile::validate( $result['profile'] );
		if ( is_wp_error( $valid ) ) {
			wp_send_json_error( array( 'message' => $valid->get_error_message() ) );
		}

		// Preview the corrected profile against the same sample HTML, and
		// rebuild the editable product data sheet.
		$preview = array();
		$sheet_out = array();
		$fixed   = SWI_Generic_Scraper::scrape_html( $sample, $base, $valid );
		if ( ! is_wp_error( $fixed ) ) {
			$preview   = $this->preview_summary( $fixed );
			$sheet_out = $this->product_sheet( $fixed );
		}

		// Re-apply the user's locked (correct) values so the AI can't clobber
		// fields they already confirmed/edited.
		if ( ! empty( $locked ) && ! empty( $sheet_out ) ) {
			$sheet_out = $this->apply_sheet_locks(
				$sheet_out,
				array( 'sheet' => $sheet, 'locked' => $locked )
			);
		}

		// Persist the user's edits (locked fields) for this product.
		$norm = SWI_Profile::normalize_domain( '' !== $base ? $base : '' );
		if ( '' !== $base && '' !== $norm ) {
			$this->save_sheet_edits( $norm, $base, $sheet_out, $locked );
		}

		wp_send_json_success(
			array(
				'profile'     => $valid,
				'explanation' => isset( $result['explanation'] ) ? (string) $result['explanation'] : '',
				'changes'     => isset( $result['changes'] ) ? $result['changes'] : array(),
				'preview'     => $preview,
				'sheet'       => $sheet_out,
				'locked'      => $locked,
				'scraped'     => is_wp_error( $fixed ) ? array() : $fixed,
			)
		);
	}

	/**
	 * Build a compact snapshot of scraped data to send to the AI (so it can
	 * understand exactly what the user is seeing, without huge payloads).
	 *
	 * @param array $data Scraped product data.
	 * @return array
	 */
	private function ai_data_snapshot( $data ) {
		$snap = array(
			'type'        => isset( $data['type'] ) ? $data['type'] : 'simple',
			'title'       => isset( $data['title'] ) ? $data['title'] : '',
			'price'       => isset( $data['price'] ) ? $data['price'] : '',
			'sale_price'  => isset( $data['sale_price'] ) ? $data['sale_price'] : '',
			'currency'    => isset( $data['currency'] ) ? $data['currency'] : '',
			'sku'         => isset( $data['sku'] ) ? $data['sku'] : '',
			'description' => isset( $data['description'] ) ? wp_trim_words( wp_strip_all_tags( (string) $data['description'] ), 60, '…' ) : '',
			'categories'  => isset( $data['categories'] ) ? array_values( (array) $data['categories'] ) : array(),
			'images_count' => isset( $data['images'] ) ? count( (array) $data['images'] ) : 0,
			'variants'    => array(),
		);

		if ( ! empty( $data['variants'] ) && is_array( $data['variants'] ) ) {
			foreach ( array_slice( $data['variants'], 0, 30 ) as $v ) {
				$snap['variants'][] = array(
					'attributes' => isset( $v['attributes'] ) ? $v['attributes'] : array(),
					'price'      => isset( $v['price'] ) ? $v['price'] : '',
					'sku'        => isset( $v['sku'] ) ? $v['sku'] : '',
				);
			}
			if ( count( $data['variants'] ) > 30 ) {
				$snap['variants_truncated'] = count( $data['variants'] );
			}
		}

		return $snap;
	}

	/**
	 * Resolve a sample's HTML from URL upload / file upload / pasted text.
	 *
	 * Precedence: pasted HTML text > uploaded file > URL fetch.
	 *
	 * @param string $which 'simple' | 'variable'.
	 * @return string|WP_Error
	 */
	private function train_sample_html( $which ) {
		// 1. Pasted HTML.
		$html_key = $which . '_html';
		if ( isset( $_POST[ $html_key ] ) && '' !== trim( (string) $_POST[ $html_key ] ) ) {
			return wp_unslash( $_POST[ $html_key ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized — raw HTML, parsed server-side.
		}

		// 2. Uploaded file.
		$file_key = $which . '_file';
		if ( isset( $_FILES[ $file_key ] ) && is_array( $_FILES[ $file_key ] ) && UPLOAD_ERR_OK === (int) $_FILES[ $file_key ]['error'] ) {
			$tmp = $_FILES[ $file_key ]['tmp_name'];
			if ( is_uploaded_file( $tmp ) ) {
				$contents = (string) file_get_contents( $tmp );
				if ( '' !== trim( $contents ) ) {
					return $contents;
				}
			}
		}

		// 3. URL fetch.
		$url_key = $which . '_url';
		if ( isset( $_POST[ $url_key ] ) && '' !== trim( (string) $_POST[ $url_key ] ) ) {
			$html = SWI_HTTP::get_with_retry( sanitize_text_field( wp_unslash( $_POST[ $url_key ] ) ) );
			if ( is_wp_error( $html ) ) {
				return $html;
			}
			return $html;
		}

		return '';
	}

	/**
	 * Build a compact preview summary of extracted product data.
	 *
	 * @param array $data Normalized product data.
	 * @return array
	 */
	private function preview_summary( $data ) {
		$summary = array(
			'type'             => isset( $data['type'] ) ? (string) $data['type'] : 'simple',
			'title'            => isset( $data['title'] ) ? (string) $data['title'] : '',
			'short_description' => isset( $data['short_description'] ) ? (string) $data['short_description'] : '',
			'description'      => isset( $data['description'] ) ? (string) $data['description'] : '',
			'price'            => isset( $data['price'] ) ? (string) $data['price'] : '',
			'sale_price'       => isset( $data['sale_price'] ) ? (string) $data['sale_price'] : '',
			'currency'         => isset( $data['currency'] ) ? (string) $data['currency'] : '',
			'sku'              => isset( $data['sku'] ) ? (string) $data['sku'] : '',
			'images_count'     => isset( $data['images'] ) ? count( (array) $data['images'] ) : 0,
			'categories'       => isset( $data['categories'] ) ? array_values( (array) $data['categories'] ) : array(),
			'variants'         => array(),
		);

		if ( ! empty( $data['variants'] ) && is_array( $data['variants'] ) ) {
			$summary['variants_count'] = count( $data['variants'] );
			foreach ( array_slice( $data['variants'], 0, 3 ) as $v ) {
				$summary['variants'][] = array(
					'attributes' => isset( $v['attributes'] ) ? $v['attributes'] : array(),
					'price'      => isset( $v['price'] ) ? $v['price'] : '',
					'sku'        => isset( $v['sku'] ) ? $v['sku'] : '',
					'image'      => isset( $v['image'] ) ? $v['image'] : '',
				);
			}
		}

		return $summary;
	}

	/**
	 * Build the "product data sheet" — our target WooCommerce product schema,
	 * filled from the scraped data. This is the structure the AI is taught to
	 * map to, and what the reviewer edits in the right-hand panel:
	 *
	 *   name, short_description, description, regular_price, sale_price,
	 *   currency, sku, images[], attributes[{name,type,values[{value,color,
	 *   image}]}], variations[{attributes,price,sale_price,sku,image}]
	 *
	 * Attributes are derived from the variant attribute keys, with color/image
	 * swatches merged in from the scraper's swatch map.
	 *
	 * @param array $data Normalized scraped product data.
	 * @return array
	 */
	private function product_sheet( $data ) {
		// ---- Attribute values (from variants) ----------------------------
		$attr_values = array(); // label => ordered unique values.
		$attr_order  = array();
		foreach ( (array) $data['variants'] as $v ) {
			foreach ( (array) $v['attributes'] as $name => $value ) {
				$name  = (string) $name;
				$value = (string) $value;
				if ( '' === $name ) {
					continue;
				}
				if ( ! isset( $attr_values[ $name ] ) ) {
					$attr_values[ $name ] = array();
					$attr_order[]         = $name;
				}
				if ( '' !== $value && ! in_array( $value, $attr_values[ $name ], true ) ) {
					$attr_values[ $name ][] = $value;
				}
			}
		}

		// ---- Swatch lookup (value/term name → color/image, attr type) -----
		$swatch_color = array();
		$swatch_image = array();
		$attr_type    = array();
		foreach ( (array) $data['swatches'] as $tax => $sw ) {
			$label = ! empty( $sw['name'] ) ? (string) $sw['name'] : (string) $tax;
			$type  = isset( $sw['type'] ) ? (string) $sw['type'] : 'select';
			if ( false !== strpos( $type, 'image' ) ) {
				$type = 'image';
			} elseif ( false !== strpos( $type, 'color' ) ) {
				$type = 'color';
			} else {
				$type = 'select';
			}
			$attr_type[ $label ] = $type;
			foreach ( (array) $sw['terms'] as $slug => $term ) {
				$term_name = ! empty( $term['name'] ) ? (string) $term['name'] : (string) $slug;
				if ( ! empty( $term['color'] ) ) {
					$swatch_color[ $term_name ] = (string) $term['color'];
				}
				if ( ! empty( $term['image'] ) ) {
					$swatch_image[ $term_name ] = (string) $term['image'];
				}
			}
		}

		// ---- Attributes ---------------------------------------------------
		$attributes = array();
		foreach ( $attr_order as $name ) {
			$values = array();
			foreach ( $attr_values[ $name ] as $value ) {
				$values[] = array(
					'value' => $value,
					'color' => isset( $swatch_color[ $value ] ) ? $swatch_color[ $value ] : '',
					'image' => isset( $swatch_image[ $value ] ) ? $swatch_image[ $value ] : '',
				);
			}
			$attributes[] = array(
				'name'   => $name,
				'type'   => isset( $attr_type[ $name ] ) ? $attr_type[ $name ] : 'select',
				'values' => $values,
			);
		}

		// ---- Variations ----------------------------------------------------
		$variations = array();
		foreach ( (array) $data['variants'] as $v ) {
			$variations[] = array(
				'attributes' => isset( $v['attributes'] ) ? $v['attributes'] : array(),
				'price'      => isset( $v['price'] ) ? (string) $v['price'] : '',
				'sale_price' => isset( $v['sale_price'] ) ? (string) $v['sale_price'] : '',
				'sku'        => isset( $v['sku'] ) ? (string) $v['sku'] : '',
				'image'      => isset( $v['image'] ) ? (string) $v['image'] : '',
			);
		}

		return array(
			'name'              => isset( $data['title'] ) ? (string) $data['title'] : '',
			'short_description' => isset( $data['short_description'] ) ? (string) $data['short_description'] : '',
			'description'       => isset( $data['description'] ) ? (string) $data['description'] : '',
			'regular_price'     => isset( $data['price'] ) ? (string) $data['price'] : '',
			'sale_price'        => isset( $data['sale_price'] ) ? (string) $data['sale_price'] : '',
			'currency'          => isset( $data['currency'] ) ? (string) $data['currency'] : '',
			'sku'               => isset( $data['sku'] ) ? (string) $data['sku'] : '',
			'images'            => isset( $data['images'] ) ? array_values( array_map( 'strval', (array) $data['images'] ) ) : array(),
			'attributes'        => $attributes,
			'variations'        => $variations,
		);
	}

	/**
	 * Option key for persisted sheet edits (keyed by domain + sample URL).
	 *
	 * @param string $domain Normalized domain.
	 * @param string $url    Sample product URL.
	 * @return string
	 */
	private function sheet_edits_key( $domain, $url ) {
		return 'swi_sheet_' . md5( $domain . '|' . $url );
	}

	/**
	 * Load persisted sheet edits for a domain + sample URL.
	 *
	 * @param string $domain Normalized domain.
	 * @param string $url    Sample product URL.
	 * @return array Array( 'sheet' => array, 'locked' => array ).
	 */
	private function get_sheet_edits( $domain, $url ) {
		$value = get_option( $this->sheet_edits_key( $domain, $url ), array() );
		if ( ! is_array( $value ) ) {
			$value = array();
		}
		return array(
			'sheet'  => isset( $value['sheet'] ) && is_array( $value['sheet'] ) ? $value['sheet'] : array(),
			'locked' => isset( $value['locked'] ) && is_array( $value['locked'] ) ? $value['locked'] : array(),
		);
	}

	/**
	 * Persist sheet edits for a domain + sample URL.
	 *
	 * @param string $domain Normalized domain.
	 * @param string $url    Sample product URL.
	 * @param array  $sheet  The (edited) product sheet.
	 * @param array  $locked Locked (correct) top-level field keys.
	 */
	private function save_sheet_edits( $domain, $url, $sheet, $locked ) {
		update_option(
			$this->sheet_edits_key( $domain, $url ),
			array(
				'sheet'  => is_array( $sheet ) ? $sheet : array(),
				'locked' => is_array( $locked ) ? array_values( array_unique( array_map( 'strval', $locked ) ) ) : array(),
			),
			false
		);
	}

	/**
	 * Overlay the user's locked (correct) values onto a freshly-built sheet.
	 *
	 * Locked keys are top-level sheet fields; the persisted value wins so a
	 * re-analyze or AI fix can't wipe out a manual correction.
	 *
	 * @param array $sheet  Freshly built product sheet.
	 * @param array $edits  Persisted edits { sheet, locked }.
	 * @return array
	 */
	private function apply_sheet_locks( $sheet, $edits ) {
		if ( empty( $edits['locked'] ) || empty( $edits['sheet'] ) ) {
			return $sheet;
		}
		foreach ( $edits['locked'] as $key ) {
			if ( isset( $edits['sheet'][ $key ] ) && isset( $sheet[ $key ] ) ) {
				$sheet[ $key ] = $edits['sheet'][ $key ];
			}
		}
		return $sheet;
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
