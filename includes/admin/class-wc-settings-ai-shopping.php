<?php
/**
 * WooCommerce Settings integration.
 *
 * Adds an "AI Shopping" tab to WooCommerce > Settings with 4 sections:
 * General, Discovery, Extensions, Endpoints.
 *
 * @package AIShopping\Admin
 */

namespace AIShopping\Admin;

defined( 'ABSPATH' ) || exit;

use AIShopping\Extensions\Extension_Detector;

/**
 * WC_Settings_AI_Shopping class.
 */
class WC_Settings_AI_Shopping extends \WC_Settings_Page {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id    = 'ai-shopping';
		$this->label = __( 'AI Shopping', 'ai-shopping' );

		// Render promo footer on every section.
		add_action( 'woocommerce_after_settings_' . $this->id, array( $this, 'render_promo_footer' ) );

		parent::__construct();
	}

	/**
	 * Get own sections.
	 *
	 * @return array
	 */
	protected function get_own_sections() {
		return array(
			''           => __( 'General', 'ai-shopping' ),
			'discovery'  => __( 'Discovery', 'ai-shopping' ),
			'extensions' => __( 'Extensions', 'ai-shopping' ),
			'endpoints'  => __( 'Endpoints', 'ai-shopping' ),
		);
	}

	/**
	 * Get settings for the default (General) section.
	 *
	 * @return array
	 */
	protected function get_settings_for_default_section() {
		return array(
			// Protocols.
			array(
				'title' => __( 'Protocols', 'ai-shopping' ),
				'type'  => 'title',
				'desc'  => __( 'Enable or disable AI commerce protocol adapters.', 'ai-shopping' ),
				'id'    => 'ais_protocols_options',
			),
			array(
				'title'   => __( 'Agentic Commerce Protocol (ACP)', 'ai-shopping' ),
				'id'      => 'ais_enable_acp',
				'type'    => 'checkbox',
				'default' => 'yes',
				'desc'    => __( 'Enable ACP endpoints (OpenAI/Stripe)', 'ai-shopping' ),
			),
			array(
				'title'   => __( 'Universal Commerce Protocol (UCP)', 'ai-shopping' ),
				'id'      => 'ais_enable_ucp',
				'type'    => 'checkbox',
				'default' => 'yes',
				'desc'    => __( 'Enable UCP endpoints (Shopify/Google)', 'ai-shopping' ),
			),
			array(
				'title'   => __( 'Model Context Protocol (MCP)', 'ai-shopping' ),
				'id'      => 'ais_enable_mcp',
				'type'    => 'checkbox',
				'default' => 'yes',
				'desc'    => __( 'Enable MCP tools (Anthropic)', 'ai-shopping' ),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'ais_protocols_options',
			),

			// Rate Limiting.
			array(
				'title' => __( 'Rate Limiting', 'ai-shopping' ),
				'type'  => 'title',
				'id'    => 'ais_rate_limit_options',
			),
			array(
				'title'             => __( 'Read requests/minute', 'ai-shopping' ),
				'id'                => 'ais_rate_limit_read',
				'type'              => 'number',
				'default'           => '60',
				'css'               => 'width:80px;',
				'custom_attributes' => array( 'min' => '0' ),
				'desc'              => __( '0 = unlimited. Default: 60.', 'ai-shopping' ),
			),
			array(
				'title'             => __( 'Write requests/minute', 'ai-shopping' ),
				'id'                => 'ais_rate_limit_write',
				'type'              => 'number',
				'default'           => '30',
				'css'               => 'width:80px;',
				'custom_attributes' => array( 'min' => '0' ),
				'desc'              => __( '0 = unlimited. Default: 30.', 'ai-shopping' ),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'ais_rate_limit_options',
			),

			// Webhooks.
			array(
				'title' => __( 'Webhooks', 'ai-shopping' ),
				'type'  => 'title',
				'id'    => 'ais_webhook_options',
			),
			array(
				'title' => __( 'Webhook URL', 'ai-shopping' ),
				'id'    => 'ais_webhook_url',
				'type'  => 'url',
				'css'   => 'width:400px;',
				'desc'  => __( 'URL to receive order status change events.', 'ai-shopping' ),
			),
			array(
				'title' => __( 'Webhook Secret', 'ai-shopping' ),
				'id'    => 'ais_webhook_secret',
				'type'  => 'text',
				'css'   => 'width:400px;',
				'desc'  => __( 'Used to HMAC-sign webhook payloads.', 'ai-shopping' ),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'ais_webhook_options',
			),

			// Security.
			array(
				'title' => __( 'Security', 'ai-shopping' ),
				'type'  => 'title',
				'id'    => 'ais_security_options',
			),
			array(
				'title'   => __( 'Allow HTTP', 'ai-shopping' ),
				'id'      => 'ais_allow_http',
				'type'    => 'checkbox',
				'default' => 'yes',
				'desc'    => __( 'Allow API access over HTTP (for local development).', 'ai-shopping' ),
			),
			array(
				'title'   => __( 'Debug Logging', 'ai-shopping' ),
				'id'      => 'ais_enable_logging',
				'type'    => 'checkbox',
				'default' => 'no',
				'desc'    => __( 'Log API requests (written to WooCommerce logs).', 'ai-shopping' ),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'ais_security_options',
			),
		);
	}

	/**
	 * Get settings for the Discovery section.
	 *
	 * @return array
	 */
	protected function get_settings_for_discovery_section() {
		return array(
			array(
				'title' => __( 'Discovery Settings', 'ai-shopping' ),
				'type'  => 'title',
				'desc'  => __( 'Control which AI discovery mechanisms are active on your store.', 'ai-shopping' ),
				'id'    => 'ais_discovery_options',
			),
			array(
				'title'   => __( 'Master Toggle', 'ai-shopping' ),
				'id'      => 'ais_enable_discovery',
				'type'    => 'checkbox',
				'default' => 'yes',
				'desc'    => __( 'Enable AI discovery layer (controls all mechanisms below)', 'ai-shopping' ),
			),
			array(
				'title'   => __( 'Schema.org Enhancement', 'ai-shopping' ),
				'id'      => 'ais_enable_schema_enhancement',
				'type'    => 'checkbox',
				'default' => 'yes',
				'desc'    => __( 'Enhance product JSON-LD with brand, GTIN, dimensions, reviews, etc.', 'ai-shopping' ),
			),
			array(
				'title'   => __( 'Agent Card', 'ai-shopping' ),
				'id'      => 'ais_enable_agent_json',
				'type'    => 'checkbox',
				'default' => 'yes',
				'desc'    => __( 'Serve A2A Agent Card at /.well-known/agent.json', 'ai-shopping' ),
			),
			array(
				'title'   => __( 'LLMs.txt', 'ai-shopping' ),
				'id'      => 'ais_enable_llms_txt',
				'type'    => 'checkbox',
				'default' => 'yes',
				'desc'    => __( 'Serve store overview at /llms.txt', 'ai-shopping' ),
			),
			array(
				'title'   => __( 'Product Feed', 'ai-shopping' ),
				'id'      => 'ais_enable_product_feed',
				'type'    => 'checkbox',
				'default' => 'yes',
				'desc'    => __( 'Serve full product catalog at /ai-shopping-feed.json', 'ai-shopping' ),
			),
			array(
				'title'   => __( 'Markdown Negotiation', 'ai-shopping' ),
				'id'      => 'ais_enable_markdown_negotiation',
				'type'    => 'checkbox',
				'default' => 'yes',
				'desc'    => __( 'Serve markdown product data when Accept: text/markdown header is present', 'ai-shopping' ),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'ais_discovery_options',
			),
		);
	}

	/**
	 * Output the settings.
	 */
	public function output() {
		global $current_section, $hide_save_button;

		switch ( $current_section ) {
			case 'extensions':
				$hide_save_button = true;
				$this->render_extensions_output();
				break;

			case 'endpoints':
				$hide_save_button = true;
				$this->render_endpoints_output();
				break;

			case 'discovery':
				// Render WC fields first, then append status dashboard.
				parent::output();
				$this->render_discovery_status();
				break;

			default:
				parent::output();
				break;
		}
	}

	/**
	 * Save settings — skip for custom sections.
	 */
	public function save() {
		global $current_section;

		if ( in_array( $current_section, array( 'extensions', 'endpoints' ), true ) ) {
			return;
		}

		parent::save();
	}

	/**
	 * Render the Extensions section.
	 */
	private function render_extensions_output() {
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
	 * Render the Endpoints section.
	 */
	private function render_endpoints_output() {
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

	/**
	 * Render the Discovery status dashboard (appended below WC fields).
	 */
	private function render_discovery_status() {
		$master_enabled = 'yes' === get_option( 'ais_enable_discovery', 'yes' );

		// Show cache cleared notice.
		if ( isset( $_GET['cache_cleared'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success is-dismissible"><p>';
			esc_html_e( 'Product feed cache cleared.', 'ai-shopping' );
			echo '</p></div>';
		}

		$mechanisms = array(
			array(
				'name'   => __( 'Schema.org Enhancement', 'ai-shopping' ),
				'option' => 'ais_enable_schema_enhancement',
				'test'   => null,
				'desc'   => __( 'Enhanced JSON-LD on product pages', 'ai-shopping' ),
			),
			array(
				'name'   => __( 'Agent Card', 'ai-shopping' ),
				'option' => 'ais_enable_agent_json',
				'test'   => home_url( '/.well-known/agent.json' ),
				'desc'   => __( 'A2A discovery at /.well-known/agent.json', 'ai-shopping' ),
			),
			array(
				'name'   => __( 'LLMs.txt', 'ai-shopping' ),
				'option' => 'ais_enable_llms_txt',
				'test'   => home_url( '/llms.txt' ),
				'desc'   => __( 'Plain text store overview at /llms.txt', 'ai-shopping' ),
			),
			array(
				'name'   => __( 'Product Feed', 'ai-shopping' ),
				'option' => 'ais_enable_product_feed',
				'test'   => home_url( '/ai-shopping-feed.json' ),
				'desc'   => __( 'Full product catalog at /ai-shopping-feed.json', 'ai-shopping' ),
			),
			array(
				'name'   => __( 'Markdown Negotiation', 'ai-shopping' ),
				'option' => 'ais_enable_markdown_negotiation',
				'test'   => null,
				'desc'   => __( 'Serves markdown on product pages when Accept: text/markdown', 'ai-shopping' ),
			),
			array(
				'name'   => __( 'HTTP Headers', 'ai-shopping' ),
				'option' => null,
				'test'   => null,
				'desc'   => __( 'X-AI-Shopping + Link headers on every response', 'ai-shopping' ),
			),
			array(
				'name'   => __( 'HTML Head Tags', 'ai-shopping' ),
				'option' => null,
				'test'   => null,
				'desc'   => __( 'link rel="ai-commerce" + meta tags in <head>', 'ai-shopping' ),
			),
			array(
				'name'   => __( 'robots.txt', 'ai-shopping' ),
				'option' => null,
				'test'   => home_url( '/robots.txt' ),
				'desc'   => __( 'Allow directives for discovery endpoints', 'ai-shopping' ),
			),
		);
		?>
		<hr />
		<h2><?php esc_html_e( 'AI Discovery Status', 'ai-shopping' ); ?></h2>
		<p><?php esc_html_e( 'These mechanisms make your store discoverable by AI agents. All are enabled by default.', 'ai-shopping' ); ?></p>

		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Mechanism', 'ai-shopping' ); ?></th>
					<th><?php esc_html_e( 'Status', 'ai-shopping' ); ?></th>
					<th><?php esc_html_e( 'Description', 'ai-shopping' ); ?></th>
					<th><?php esc_html_e( 'Test', 'ai-shopping' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $mechanisms as $mech ) : ?>
					<?php
					if ( ! $master_enabled ) {
						$status      = false;
						$status_text = __( 'Disabled (master toggle off)', 'ai-shopping' );
					} elseif ( null === $mech['option'] ) {
						$status      = true;
						$status_text = __( 'Active', 'ai-shopping' );
					} else {
						$status      = 'yes' === get_option( $mech['option'], 'yes' );
						$status_text = $status ? __( 'Enabled', 'ai-shopping' ) : __( 'Disabled', 'ai-shopping' );
					}
					?>
					<tr>
						<td><strong><?php echo esc_html( $mech['name'] ); ?></strong></td>
						<td>
							<?php if ( $status ) : ?>
								<span style="color: #00a32a; font-weight: bold;">&#10003; <?php echo esc_html( $status_text ); ?></span>
							<?php else : ?>
								<span style="color: #888;">&#8212; <?php echo esc_html( $status_text ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $mech['desc'] ); ?></td>
						<td>
							<?php if ( $mech['test'] ) : ?>
								<a href="<?php echo esc_url( $mech['test'] ); ?>" target="_blank" class="button button-small">
									<?php esc_html_e( 'Test', 'ai-shopping' ); ?>
								</a>
							<?php else : ?>
								&mdash;
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<hr />
		<h2><?php esc_html_e( 'Product Feed Cache', 'ai-shopping' ); ?></h2>
		<p><?php esc_html_e( 'The product feed is cached for 1 hour and automatically refreshed when products are added, updated, or deleted.', 'ai-shopping' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'ais_clear_feed_cache' ); ?>
			<input type="hidden" name="action" value="ais_clear_feed_cache" />
			<?php submit_button( __( 'Clear Feed Cache Now', 'ai-shopping' ), 'secondary' ); ?>
		</form>
		<?php
	}

	/**
	 * Render the promotional footer.
	 */
	public function render_promo_footer() {
		?>
		<div style="margin-top: 40px; padding: 20px 24px; background: #f9f9f9; border: 1px solid #e0e0e0; border-radius: 4px; display: flex; gap: 32px; align-items: flex-start; flex-wrap: wrap;">
			<div style="flex: 1; min-width: 200px;">
				<p style="margin: 0 0 4px; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; color: #999;">Built by</p>
				<p style="margin: 0 0 6px;">
					<a href="https://211j.com" target="_blank" rel="noopener" style="font-weight: 600; color: #1d2327; text-decoration: none; font-size: 14px;">211j</a>
				</p>
				<p style="margin: 0; color: #646970; font-size: 13px;">WordPress &amp; WooCommerce development. We build tools that make stores smarter.</p>
			</div>
			<div style="flex: 1; min-width: 200px;">
				<p style="margin: 0 0 4px; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; color: #999;">Also from us</p>
				<p style="margin: 0 0 6px;">
					<a href="https://uphost.ly" target="_blank" rel="noopener" style="font-weight: 600; color: #1d2327; text-decoration: none; font-size: 14px;">Uphost</a>
				</p>
				<p style="margin: 0; color: #646970; font-size: 13px;">Managed WordPress hosting with built-in support. Fast, reliable, human.</p>
			</div>
		</div>
		<?php
	}
}
