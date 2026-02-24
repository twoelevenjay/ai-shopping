<?php
/**
 * Admin settings page.
 *
 * @package AIShopping\Admin
 */

namespace AIShopping\Admin;

defined( 'ABSPATH' ) || exit;

use AIShopping\Security\Auth;
use AIShopping\Extensions\Extension_Detector;

/**
 * Admin settings page: API keys, protocol toggles, extension report, webhooks.
 */
class Admin_Page {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_ais_generate_key', array( $this, 'handle_generate_key' ) );
		add_action( 'admin_post_ais_revoke_key', array( $this, 'handle_revoke_key' ) );
		add_action( 'admin_post_ais_delete_key', array( $this, 'handle_delete_key' ) );
	}

	/**
	 * Add the admin menu item.
	 */
	public function add_menu() {
		add_menu_page(
			__( 'AI Shopping', 'ai-shopping' ),
			__( 'AI Shopping', 'ai-shopping' ),
			'manage_woocommerce',
			'ai-shopping',
			array( $this, 'render_page' ),
			'dashicons-rest-api',
			58
		);
	}

	/**
	 * Register settings.
	 */
	public function register_settings() {
		register_setting( 'ais_settings', 'ais_enable_acp' );
		register_setting( 'ais_settings', 'ais_enable_ucp' );
		register_setting( 'ais_settings', 'ais_enable_mcp' );
		register_setting( 'ais_settings', 'ais_rate_limit_read' );
		register_setting( 'ais_settings', 'ais_rate_limit_write' );
		register_setting( 'ais_settings', 'ais_webhook_url' );
		register_setting( 'ais_settings', 'ais_webhook_secret' );
		register_setting( 'ais_settings', 'ais_enable_logging' );
		register_setting( 'ais_settings', 'ais_allow_http' );
	}

	/**
	 * Handle API key generation.
	 */
	public function handle_generate_key() {
		check_admin_referer( 'ais_generate_key' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'ai-shopping' ) );
		}

		$label       = sanitize_text_field( wp_unslash( $_POST['key_label'] ?? '' ) );
		$permissions = sanitize_text_field( wp_unslash( $_POST['key_permissions'] ?? 'read' ) );

		$result = Auth::generate_key( $label, $permissions );

		// Store the new key temporarily so we can display it.
		set_transient( 'ais_new_key_' . get_current_user_id(), $result, 60 );

		wp_safe_redirect( admin_url( 'admin.php?page=ai-shopping&tab=keys&new_key=1' ) );
		exit;
	}

	/**
	 * Handle API key revocation.
	 */
	public function handle_revoke_key() {
		check_admin_referer( 'ais_revoke_key' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'ai-shopping' ) );
		}

		$key_id = (int) ( $_POST['key_id'] ?? 0 );
		Auth::revoke_key( $key_id );

		wp_safe_redirect( admin_url( 'admin.php?page=ai-shopping&tab=keys' ) );
		exit;
	}

	/**
	 * Handle API key deletion.
	 */
	public function handle_delete_key() {
		check_admin_referer( 'ais_delete_key' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'ai-shopping' ) );
		}

		$key_id = (int) ( $_POST['key_id'] ?? 0 );
		Auth::delete_key( $key_id );

		wp_safe_redirect( admin_url( 'admin.php?page=ai-shopping&tab=keys' ) );
		exit;
	}

	/**
	 * Render the settings page.
	 */
	public function render_page() {
		$active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'settings';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'AI Shopping for WooCommerce', 'ai-shopping' ); ?></h1>

			<nav class="nav-tab-wrapper">
				<a href="?page=ai-shopping&tab=settings" class="nav-tab <?php echo 'settings' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Settings', 'ai-shopping' ); ?>
				</a>
				<a href="?page=ai-shopping&tab=keys" class="nav-tab <?php echo 'keys' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'API Keys', 'ai-shopping' ); ?>
				</a>
				<a href="?page=ai-shopping&tab=extensions" class="nav-tab <?php echo 'extensions' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Extensions', 'ai-shopping' ); ?>
				</a>
				<a href="?page=ai-shopping&tab=endpoints" class="nav-tab <?php echo 'endpoints' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'API Endpoints', 'ai-shopping' ); ?>
				</a>
			</nav>

			<div class="tab-content" style="margin-top: 20px;">
				<?php
				switch ( $active_tab ) {
					case 'keys':
						$this->render_keys_tab();
						break;
					case 'extensions':
						$this->render_extensions_tab();
						break;
					case 'endpoints':
						$this->render_endpoints_tab();
						break;
					default:
						$this->render_settings_tab();
						break;
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the settings tab.
	 */
	private function render_settings_tab() {
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'ais_settings' ); ?>

			<h2><?php esc_html_e( 'Protocol Toggles', 'ai-shopping' ); ?></h2>
			<table class="form-table">
				<tr>
					<th><?php esc_html_e( 'Agentic Commerce Protocol (ACP)', 'ai-shopping' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="ais_enable_acp" value="yes" <?php checked( get_option( 'ais_enable_acp', 'yes' ), 'yes' ); ?> />
							<?php esc_html_e( 'Enable ACP endpoints (OpenAI/Stripe)', 'ai-shopping' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Universal Commerce Protocol (UCP)', 'ai-shopping' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="ais_enable_ucp" value="yes" <?php checked( get_option( 'ais_enable_ucp', 'yes' ), 'yes' ); ?> />
							<?php esc_html_e( 'Enable UCP endpoints (Shopify/Google)', 'ai-shopping' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Model Context Protocol (MCP)', 'ai-shopping' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="ais_enable_mcp" value="yes" <?php checked( get_option( 'ais_enable_mcp', 'yes' ), 'yes' ); ?> />
							<?php esc_html_e( 'Enable MCP tools (Anthropic)', 'ai-shopping' ); ?>
						</label>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Rate Limiting', 'ai-shopping' ); ?></h2>
			<table class="form-table">
				<tr>
					<th><?php esc_html_e( 'Read requests/minute', 'ai-shopping' ); ?></th>
					<td>
						<input type="number" name="ais_rate_limit_read" value="<?php echo esc_attr( get_option( 'ais_rate_limit_read', 60 ) ); ?>" min="0" class="small-text" />
						<p class="description"><?php esc_html_e( '0 = unlimited. Default: 60.', 'ai-shopping' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Write requests/minute', 'ai-shopping' ); ?></th>
					<td>
						<input type="number" name="ais_rate_limit_write" value="<?php echo esc_attr( get_option( 'ais_rate_limit_write', 30 ) ); ?>" min="0" class="small-text" />
						<p class="description"><?php esc_html_e( '0 = unlimited. Default: 30.', 'ai-shopping' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Webhooks', 'ai-shopping' ); ?></h2>
			<table class="form-table">
				<tr>
					<th><?php esc_html_e( 'Webhook URL', 'ai-shopping' ); ?></th>
					<td>
						<input type="url" name="ais_webhook_url" value="<?php echo esc_attr( get_option( 'ais_webhook_url', '' ) ); ?>" class="regular-text" />
						<p class="description"><?php esc_html_e( 'URL to receive order status change events.', 'ai-shopping' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Webhook Secret', 'ai-shopping' ); ?></th>
					<td>
						<input type="text" name="ais_webhook_secret" value="<?php echo esc_attr( get_option( 'ais_webhook_secret', '' ) ); ?>" class="regular-text" />
						<p class="description"><?php esc_html_e( 'Used to HMAC-sign webhook payloads.', 'ai-shopping' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Security', 'ai-shopping' ); ?></h2>
			<table class="form-table">
				<tr>
					<th><?php esc_html_e( 'Allow HTTP', 'ai-shopping' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="ais_allow_http" value="yes" <?php checked( get_option( 'ais_allow_http', 'yes' ), 'yes' ); ?> />
							<?php esc_html_e( 'Allow API access over HTTP (for local development).', 'ai-shopping' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Debug Logging', 'ai-shopping' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="ais_enable_logging" value="yes" <?php checked( get_option( 'ais_enable_logging', 'no' ), 'yes' ); ?> />
							<?php esc_html_e( 'Log API requests (written to WooCommerce logs).', 'ai-shopping' ); ?>
						</label>
					</td>
				</tr>
			</table>

			<?php submit_button(); ?>
		</form>
		<?php
	}

	/**
	 * Render the API keys tab.
	 */
	private function render_keys_tab() {
		$keys = Auth::list_keys();

		// Check for newly generated key.
		$new_key = get_transient( 'ais_new_key_' . get_current_user_id() );
		if ( $new_key && isset( $_GET['new_key'] ) ) {
			delete_transient( 'ais_new_key_' . get_current_user_id() );
			?>
			<div class="notice notice-success">
				<p><strong><?php esc_html_e( 'API key generated. Copy these values now — the secret will not be shown again.', 'ai-shopping' ); ?></strong></p>
				<table class="form-table">
					<tr>
						<th><?php esc_html_e( 'Consumer Key', 'ai-shopping' ); ?></th>
						<td><code><?php echo esc_html( $new_key['consumer_key'] ); ?></code></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Consumer Secret (Bearer Token)', 'ai-shopping' ); ?></th>
						<td><code style="font-weight: bold; color: #d63638;"><?php echo esc_html( $new_key['consumer_secret'] ); ?></code></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Webhook Secret', 'ai-shopping' ); ?></th>
						<td><code><?php echo esc_html( $new_key['webhook_secret'] ); ?></code></td>
					</tr>
				</table>
				<p><?php esc_html_e( 'Use the Consumer Secret as Bearer token: Authorization: Bearer {consumer_secret}', 'ai-shopping' ); ?></p>
			</div>
			<?php
		}
		?>

		<h2><?php esc_html_e( 'Generate New API Key', 'ai-shopping' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'ais_generate_key' ); ?>
			<input type="hidden" name="action" value="ais_generate_key" />
			<table class="form-table">
				<tr>
					<th><label for="key_label"><?php esc_html_e( 'Label', 'ai-shopping' ); ?></label></th>
					<td><input type="text" name="key_label" id="key_label" class="regular-text" required placeholder="<?php esc_attr_e( 'e.g., ChatGPT Agent', 'ai-shopping' ); ?>" /></td>
				</tr>
				<tr>
					<th><label for="key_permissions"><?php esc_html_e( 'Permissions', 'ai-shopping' ); ?></label></th>
					<td>
						<select name="key_permissions" id="key_permissions">
							<option value="read"><?php esc_html_e( 'Read only', 'ai-shopping' ); ?></option>
							<option value="read_write"><?php esc_html_e( 'Read / Write', 'ai-shopping' ); ?></option>
							<option value="full"><?php esc_html_e( 'Full access', 'ai-shopping' ); ?></option>
						</select>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Generate API Key', 'ai-shopping' ), 'primary' ); ?>
		</form>

		<h2><?php esc_html_e( 'Existing API Keys', 'ai-shopping' ); ?></h2>
		<?php if ( empty( $keys ) ) : ?>
			<p><?php esc_html_e( 'No API keys generated yet.', 'ai-shopping' ); ?></p>
		<?php else : ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'ID', 'ai-shopping' ); ?></th>
						<th><?php esc_html_e( 'Label', 'ai-shopping' ); ?></th>
						<th><?php esc_html_e( 'Permissions', 'ai-shopping' ); ?></th>
						<th><?php esc_html_e( 'Last Used', 'ai-shopping' ); ?></th>
						<th><?php esc_html_e( 'Created', 'ai-shopping' ); ?></th>
						<th><?php esc_html_e( 'Status', 'ai-shopping' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'ai-shopping' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $keys as $key ) : ?>
						<tr>
							<td><?php echo esc_html( $key['id'] ); ?></td>
							<td><?php echo esc_html( $key['label'] ); ?></td>
							<td><code><?php echo esc_html( $key['permissions'] ); ?></code></td>
							<td><?php echo esc_html( $key['last_used'] ?: '—' ); ?></td>
							<td><?php echo esc_html( $key['created_at'] ); ?></td>
							<td>
								<?php if ( $key['is_revoked'] ) : ?>
									<span style="color: #d63638;"><?php esc_html_e( 'Revoked', 'ai-shopping' ); ?></span>
								<?php else : ?>
									<span style="color: #00a32a;"><?php esc_html_e( 'Active', 'ai-shopping' ); ?></span>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( ! $key['is_revoked'] ) : ?>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display: inline;">
										<?php wp_nonce_field( 'ais_revoke_key' ); ?>
										<input type="hidden" name="action" value="ais_revoke_key" />
										<input type="hidden" name="key_id" value="<?php echo esc_attr( $key['id'] ); ?>" />
										<button type="submit" class="button button-small" onclick="return confirm('<?php esc_attr_e( 'Revoke this key?', 'ai-shopping' ); ?>');">
											<?php esc_html_e( 'Revoke', 'ai-shopping' ); ?>
										</button>
									</form>
								<?php endif; ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display: inline;">
									<?php wp_nonce_field( 'ais_delete_key' ); ?>
									<input type="hidden" name="action" value="ais_delete_key" />
									<input type="hidden" name="key_id" value="<?php echo esc_attr( $key['id'] ); ?>" />
									<button type="submit" class="button button-small button-link-delete" onclick="return confirm('<?php esc_attr_e( 'Permanently delete this key?', 'ai-shopping' ); ?>');">
										<?php esc_html_e( 'Delete', 'ai-shopping' ); ?>
									</button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render the extensions tab.
	 */
	private function render_extensions_tab() {
		$results = Extension_Detector::get_scan_results();
		?>
		<h2><?php esc_html_e( 'Extension Compatibility Report', 'ai-shopping' ); ?></h2>
		<p><?php esc_html_e( 'AI Shopping automatically detects and integrates with these WooCommerce extensions.', 'ai-shopping' ); ?></p>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Extension', 'ai-shopping' ); ?></th>
					<th><?php esc_html_e( 'Status', 'ai-shopping' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $results as $slug => $data ) : ?>
					<tr>
						<td><?php echo esc_html( $data['name'] ); ?></td>
						<td>
							<?php if ( $data['active'] ) : ?>
								<span style="color: #00a32a; font-weight: bold;">&#10003; <?php esc_html_e( 'Active — Integrated', 'ai-shopping' ); ?></span>
							<?php else : ?>
								<span style="color: #888;">&#8212; <?php esc_html_e( 'Not installed', 'ai-shopping' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render the endpoints tab.
	 */
	private function render_endpoints_tab() {
		$base = rest_url( 'ai-shopping/v1' );
		?>
		<h2><?php esc_html_e( 'API Endpoints Reference', 'ai-shopping' ); ?></h2>
		<p>
			<?php
			printf(
				/* translators: %s: API base URL */
				esc_html__( 'Base URL: %s', 'ai-shopping' ),
				'<code>' . esc_url( $base ) . '</code>'
			);
			?>
		</p>

		<h3><?php esc_html_e( 'Core Storefront API', 'ai-shopping' ); ?></h3>
		<table class="widefat striped">
			<thead><tr><th><?php esc_html_e( 'Method', 'ai-shopping' ); ?></th><th><?php esc_html_e( 'Endpoint', 'ai-shopping' ); ?></th><th><?php esc_html_e( 'Description', 'ai-shopping' ); ?></th></tr></thead>
			<tbody>
				<tr><td><code>GET</code></td><td><code>/products</code></td><td><?php esc_html_e( 'Search and filter products', 'ai-shopping' ); ?></td></tr>
				<tr><td><code>GET</code></td><td><code>/products/{id}</code></td><td><?php esc_html_e( 'Product detail with variations', 'ai-shopping' ); ?></td></tr>
				<tr><td><code>GET</code></td><td><code>/categories</code></td><td><?php esc_html_e( 'Product categories', 'ai-shopping' ); ?></td></tr>
				<tr><td><code>POST</code></td><td><code>/cart</code></td><td><?php esc_html_e( 'Create cart session', 'ai-shopping' ); ?></td></tr>
				<tr><td><code>GET</code></td><td><code>/cart</code></td><td><?php esc_html_e( 'Get cart with totals', 'ai-shopping' ); ?></td></tr>
				<tr><td><code>POST</code></td><td><code>/cart/items</code></td><td><?php esc_html_e( 'Add item to cart', 'ai-shopping' ); ?></td></tr>
				<tr><td><code>POST</code></td><td><code>/checkout/order</code></td><td><?php esc_html_e( 'Place order', 'ai-shopping' ); ?></td></tr>
				<tr><td><code>GET</code></td><td><code>/orders/{id}</code></td><td><?php esc_html_e( 'Order details', 'ai-shopping' ); ?></td></tr>
				<tr><td><code>GET</code></td><td><code>/store</code></td><td><?php esc_html_e( 'Store configuration', 'ai-shopping' ); ?></td></tr>
			</tbody>
		</table>

		<h3><?php esc_html_e( 'ACP (Agentic Commerce Protocol)', 'ai-shopping' ); ?></h3>
		<table class="widefat striped">
			<thead><tr><th><?php esc_html_e( 'Method', 'ai-shopping' ); ?></th><th><?php esc_html_e( 'Endpoint', 'ai-shopping' ); ?></th><th><?php esc_html_e( 'Description', 'ai-shopping' ); ?></th></tr></thead>
			<tbody>
				<tr><td><code>POST</code></td><td><code>/acp/checkout</code></td><td><?php esc_html_e( 'Create ACP checkout', 'ai-shopping' ); ?></td></tr>
				<tr><td><code>POST</code></td><td><code>/acp/checkout/{id}</code></td><td><?php esc_html_e( 'Update checkout', 'ai-shopping' ); ?></td></tr>
				<tr><td><code>POST</code></td><td><code>/acp/checkout/{id}/complete</code></td><td><?php esc_html_e( 'Complete checkout', 'ai-shopping' ); ?></td></tr>
				<tr><td><code>DELETE</code></td><td><code>/acp/checkout/{id}</code></td><td><?php esc_html_e( 'Cancel checkout', 'ai-shopping' ); ?></td></tr>
			</tbody>
		</table>

		<h3><?php esc_html_e( 'UCP (Universal Commerce Protocol)', 'ai-shopping' ); ?></h3>
		<table class="widefat striped">
			<thead><tr><th><?php esc_html_e( 'Method', 'ai-shopping' ); ?></th><th><?php esc_html_e( 'Endpoint', 'ai-shopping' ); ?></th><th><?php esc_html_e( 'Description', 'ai-shopping' ); ?></th></tr></thead>
			<tbody>
				<tr><td></td><td><code>/.well-known/ucp</code></td><td><?php esc_html_e( 'Merchant profile', 'ai-shopping' ); ?></td></tr>
				<tr><td><code>POST</code></td><td><code>/ucp/negotiate</code></td><td><?php esc_html_e( 'Capability negotiation', 'ai-shopping' ); ?></td></tr>
				<tr><td><code>GET</code></td><td><code>/ucp/catalog/search</code></td><td><?php esc_html_e( 'Product search', 'ai-shopping' ); ?></td></tr>
				<tr><td><code>POST</code></td><td><code>/ucp/checkout</code></td><td><?php esc_html_e( 'Create UCP session', 'ai-shopping' ); ?></td></tr>
			</tbody>
		</table>

		<h3><?php esc_html_e( 'MCP (Model Context Protocol)', 'ai-shopping' ); ?></h3>
		<table class="widefat striped">
			<thead><tr><th><?php esc_html_e( 'Method', 'ai-shopping' ); ?></th><th><?php esc_html_e( 'Endpoint', 'ai-shopping' ); ?></th><th><?php esc_html_e( 'Description', 'ai-shopping' ); ?></th></tr></thead>
			<tbody>
				<tr><td><code>GET</code></td><td><code>/mcp/tools</code></td><td><?php esc_html_e( 'List available MCP tools', 'ai-shopping' ); ?></td></tr>
				<tr><td><code>POST</code></td><td><code>/mcp/tools/{tool}</code></td><td><?php esc_html_e( 'Execute MCP tool', 'ai-shopping' ); ?></td></tr>
			</tbody>
		</table>
		<?php
	}
}
