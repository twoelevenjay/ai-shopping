<?php
/**
 * AI Discovery Layer — central orchestrator.
 *
 * Registers all hooks that make the store discoverable by AI agents:
 * rewrite rules, content negotiation, head tags, HTTP headers, robots.txt,
 * and dynamic endpoints (agent.json, llms.txt, product feed).
 *
 * @package AIShopping\Discovery
 */

namespace AIShopping\Discovery;

defined( 'ABSPATH' ) || exit;

/**
 * Discovery orchestrator.
 */
class Discovery {

	/**
	 * Constructor — wire up all discovery hooks.
	 */
	public function __construct() {
		if ( 'yes' !== get_option( 'ais_enable_discovery', 'yes' ) ) {
			return;
		}

		// Schema.org enhancement.
		if ( 'yes' === get_option( 'ais_enable_schema_enhancement', 'yes' ) ) {
			new Schema_Enhancer();
		}

		// Rewrite rules for discovery endpoints.
		add_action( 'init', array( $this, 'register_rewrite_rules' ) );

		// Content negotiation (priority 1 — before other template_redirect handlers).
		add_action( 'template_redirect', array( $this, 'handle_content_negotiation' ), 1 );

		// Discovery endpoints (priority 10).
		add_action( 'template_redirect', array( $this, 'handle_discovery_endpoints' ), 10 );

		// HTML head tags.
		add_action( 'wp_head', array( $this, 'render_head_tags' ), 1 );

		// HTTP response headers.
		add_action( 'send_headers', array( $this, 'send_discovery_headers' ) );

		// robots.txt additions.
		add_filter( 'robots_txt', array( $this, 'filter_robots_txt' ), 100, 2 );

		// Product feed cache invalidation.
		add_action( 'woocommerce_update_product', array( $this, 'invalidate_product_feed_cache' ) );
		add_action( 'woocommerce_new_product', array( $this, 'invalidate_product_feed_cache' ) );
		add_action( 'woocommerce_delete_product', array( $this, 'invalidate_product_feed_cache' ) );
		add_action( 'woocommerce_trash_product', array( $this, 'invalidate_product_feed_cache' ) );
	}

	/**
	 * Register rewrite rules for discovery endpoints.
	 */
	public function register_rewrite_rules() {
		// /.well-known/agent.json
		add_rewrite_rule( '^\.well-known/agent\.json/?$', 'index.php?ais_discovery_endpoint=agent_json', 'top' );

		// /llms.txt
		add_rewrite_rule( '^llms\.txt/?$', 'index.php?ais_discovery_endpoint=llms_txt', 'top' );

		// /ai-shopping-feed.json
		add_rewrite_rule( '^ai-shopping-feed\.json/?$', 'index.php?ais_discovery_endpoint=product_feed', 'top' );

		add_filter(
			'query_vars',
			function ( $vars ) {
				$vars[] = 'ais_discovery_endpoint';
				return $vars;
			}
		);
	}

	/**
	 * Handle Accept: text/markdown content negotiation on product pages.
	 */
	public function handle_content_negotiation() {
		if ( 'yes' !== get_option( 'ais_enable_markdown_negotiation', 'yes' ) ) {
			return;
		}

		if ( ! isset( $_SERVER['HTTP_ACCEPT'] ) ) {
			return;
		}

		$accept = sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT'] ) );
		if ( false === strpos( $accept, 'text/markdown' ) ) {
			return;
		}

		// Only product-related pages.
		if ( ! is_singular( 'product' ) && ! is_shop() && ! is_product_taxonomy() ) {
			return;
		}

		if ( is_singular( 'product' ) ) {
			$this->serve_product_markdown();
		} elseif ( is_shop() || is_product_taxonomy() ) {
			$this->serve_catalog_markdown();
		}
	}

	/**
	 * Handle discovery endpoint requests.
	 */
	public function handle_discovery_endpoints() {
		$endpoint = get_query_var( 'ais_discovery_endpoint' );
		if ( ! $endpoint ) {
			return;
		}

		switch ( $endpoint ) {
			case 'agent_json':
				$this->serve_agent_json();
				break;
			case 'llms_txt':
				$this->serve_llms_txt();
				break;
			case 'product_feed':
				$this->serve_product_feed();
				break;
		}
	}

	/**
	 * Render discovery link/meta tags in <head>.
	 */
	public function render_head_tags() {
		$agent_url = home_url( '/.well-known/agent.json' );
		$rest_url  = rest_url( 'ai-shopping/v1' );

		$protocols = array();
		if ( 'yes' === get_option( 'ais_enable_acp', 'yes' ) ) {
			$protocols[] = 'acp';
		}
		if ( 'yes' === get_option( 'ais_enable_ucp', 'yes' ) ) {
			$protocols[] = 'ucp';
		}
		if ( 'yes' === get_option( 'ais_enable_mcp', 'yes' ) ) {
			$protocols[] = 'mcp';
		}

		echo '<link rel="ai-commerce" href="' . esc_url( $agent_url ) . '" />' . "\n";
		echo '<meta name="ai-shopping-api" content="' . esc_url( $rest_url ) . '" />' . "\n";
		if ( ! empty( $protocols ) ) {
			echo '<meta name="ai-shopping-protocols" content="' . esc_attr( implode( ',', $protocols ) ) . '" />' . "\n";
		}
	}

	/**
	 * Send discovery HTTP headers on every WordPress response.
	 */
	public function send_discovery_headers() {
		if ( headers_sent() ) {
			return;
		}

		$protocols = array();
		if ( 'yes' === get_option( 'ais_enable_acp', 'yes' ) ) {
			$protocols[] = 'acp';
		}
		if ( 'yes' === get_option( 'ais_enable_ucp', 'yes' ) ) {
			$protocols[] = 'ucp';
		}
		if ( 'yes' === get_option( 'ais_enable_mcp', 'yes' ) ) {
			$protocols[] = 'mcp';
		}

		header( 'X-AI-Shopping: v' . AIS_VERSION . '; protocols=' . implode( ',', $protocols ) );
		header( 'Link: <' . home_url( '/.well-known/agent.json' ) . '>; rel="ai-commerce"', false );
	}

	/**
	 * Append AI discovery directives to robots.txt.
	 *
	 * @param string $output  Current robots.txt content.
	 * @param bool   $public  Whether the site is public.
	 * @return string
	 */
	public function filter_robots_txt( $output, $public ) {
		if ( ! $public ) {
			return $output;
		}

		$additions  = "\n# AI Shopping Discovery\n";
		$additions .= "Allow: /.well-known/agent.json\n";
		$additions .= "Allow: /.well-known/ucp\n";
		$additions .= "Allow: /llms.txt\n";
		$additions .= "Allow: /ai-shopping-feed.json\n";

		return $output . $additions;
	}

	/**
	 * Invalidate the product feed transient cache.
	 */
	public function invalidate_product_feed_cache() {
		delete_transient( 'ais_product_feed' );
	}

	/**
	 * Serve /.well-known/agent.json (A2A Agent Card).
	 */
	private function serve_agent_json() {
		if ( 'yes' !== get_option( 'ais_enable_agent_json', 'yes' ) ) {
			status_header( 404 );
			exit;
		}

		$rest_base = rest_url( 'ai-shopping/v1' );

		$skills = array(
			array(
				'id'          => 'shopping',
				'name'        => 'Product Shopping',
				'description' => 'Browse products, search catalog, view details, check availability and pricing.',
				'tags'        => array( 'ecommerce', 'shopping', 'products' ),
				'examples'    => array(
					'Search for products',
					'Get product details',
					'Check product availability',
				),
			),
			array(
				'id'          => 'cart',
				'name'        => 'Cart Management',
				'description' => 'Create and manage shopping carts, add/remove items, apply coupons.',
				'tags'        => array( 'ecommerce', 'cart' ),
				'examples'    => array(
					'Add item to cart',
					'View cart contents',
					'Apply coupon code',
				),
			),
			array(
				'id'          => 'checkout',
				'name'        => 'Checkout',
				'description' => 'Complete purchases with shipping and payment processing.',
				'tags'        => array( 'ecommerce', 'checkout', 'payment' ),
				'examples'    => array(
					'Place an order',
					'Get shipping options',
				),
			),
		);

		$protocols = array();
		if ( 'yes' === get_option( 'ais_enable_acp', 'yes' ) ) {
			$protocols['acp'] = array(
				'enabled'  => true,
				'base_url' => $rest_base . '/acp',
			);
		}
		if ( 'yes' === get_option( 'ais_enable_ucp', 'yes' ) ) {
			$protocols['ucp'] = array(
				'enabled'    => true,
				'well_known' => '/.well-known/ucp',
			);
		}
		if ( 'yes' === get_option( 'ais_enable_mcp', 'yes' ) ) {
			$protocols['mcp'] = array(
				'enabled'  => true,
				'base_url' => $rest_base . '/mcp',
			);
		}

		$card = array(
			'name'           => get_bloginfo( 'name' ),
			'description'    => get_bloginfo( 'description' ),
			'url'            => home_url(),
			'version'        => AIS_VERSION,
			'capabilities'   => array(
				'streaming' => false,
			),
			'authentication' => array(
				'schemes' => array(
					array(
						'scheme' => 'bearer',
						'description' => 'API key as Bearer token. Generate keys in WP Admin > AI Shopping > API Keys.',
					),
				),
			),
			'defaultInputModes'  => array( 'application/json' ),
			'defaultOutputModes' => array( 'application/json' ),
			'skills'             => $skills,
			'protocols'          => $protocols,
			'api_base'           => $rest_base,
			'discovery'          => array(
				'llms_txt'     => home_url( '/llms.txt' ),
				'product_feed' => home_url( '/ai-shopping-feed.json' ),
			),
		);

		/**
		 * Filter the A2A Agent Card data.
		 *
		 * @param array $card Agent card data.
		 */
		$card = apply_filters( 'ais_agent_card', $card );

		status_header( 200 );
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Access-Control-Allow-Origin: *' );
		header( 'Cache-Control: public, max-age=3600' );
		echo wp_json_encode( $card, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		exit;
	}

	/**
	 * Serve /llms.txt — plain text store overview for LLMs.
	 */
	private function serve_llms_txt() {
		if ( 'yes' !== get_option( 'ais_enable_llms_txt', 'yes' ) ) {
			status_header( 404 );
			exit;
		}

		$name        = get_bloginfo( 'name' );
		$description = get_bloginfo( 'description' );
		$rest_base   = rest_url( 'ai-shopping/v1' );
		$currency    = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD';

		$output  = "# {$name}\n";
		$output .= "> {$description}\n\n";

		// Store basics.
		$output .= "## Store Info\n";
		$output .= "- URL: " . home_url() . "\n";
		$output .= "- Currency: {$currency}\n";
		$output .= "- Powered by: WooCommerce + AI Shopping plugin\n\n";

		// Product categories.
		$categories = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => true,
				'parent'     => 0,
			)
		);

		if ( ! is_wp_error( $categories ) && ! empty( $categories ) ) {
			$output .= "## Product Categories\n";
			foreach ( $categories as $cat ) {
				$output .= "- {$cat->name} ({$cat->count} products)\n";
			}
			$output .= "\n";
		}

		// Product count.
		$product_count = wp_count_posts( 'product' );
		$published     = isset( $product_count->publish ) ? $product_count->publish : 0;
		$output       .= "## Catalog\n";
		$output       .= "- Total published products: {$published}\n";
		$output       .= "- Product feed: " . home_url( '/ai-shopping-feed.json' ) . "\n\n";

		// API access.
		$output .= "## API Access\n";
		$output .= "- Base URL: {$rest_base}\n";
		$output .= "- Authentication: Bearer token (API key)\n";
		$output .= "- Agent Card: " . home_url( '/.well-known/agent.json' ) . "\n\n";

		// Protocols.
		$output .= "### Protocols\n";
		if ( 'yes' === get_option( 'ais_enable_acp', 'yes' ) ) {
			$output .= "- ACP (Agentic Commerce Protocol): {$rest_base}/acp/\n";
		}
		if ( 'yes' === get_option( 'ais_enable_ucp', 'yes' ) ) {
			$output .= "- UCP (Universal Commerce Protocol): " . home_url( '/.well-known/ucp' ) . "\n";
		}
		if ( 'yes' === get_option( 'ais_enable_mcp', 'yes' ) ) {
			$output .= "- MCP (Model Context Protocol): {$rest_base}/mcp/tools\n";
		}

		$output .= "\n### Key Endpoints\n";
		$output .= "- Products: GET {$rest_base}/products\n";
		$output .= "- Product detail: GET {$rest_base}/products/{id}\n";
		$output .= "- Categories: GET {$rest_base}/categories\n";
		$output .= "- Cart: POST {$rest_base}/cart\n";
		$output .= "- Checkout: POST {$rest_base}/checkout/order\n";
		$output .= "- Store info: GET {$rest_base}/store\n";

		/**
		 * Filter the llms.txt output.
		 *
		 * @param string $output The llms.txt content.
		 */
		$output = apply_filters( 'ais_llms_txt', $output );

		status_header( 200 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Access-Control-Allow-Origin: *' );
		header( 'Cache-Control: public, max-age=3600' );
		echo $output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Serve /ai-shopping-feed.json — full product catalog.
	 */
	private function serve_product_feed() {
		if ( 'yes' !== get_option( 'ais_enable_product_feed', 'yes' ) ) {
			status_header( 404 );
			exit;
		}

		$feed = Product_Feed::generate();

		status_header( 200 );
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Access-Control-Allow-Origin: *' );
		header( 'Cache-Control: public, max-age=3600' );
		echo wp_json_encode( $feed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		exit;
	}

	/**
	 * Serve markdown for a single product page.
	 */
	private function serve_product_markdown() {
		global $post;

		$product = wc_get_product( $post->ID );
		if ( ! $product ) {
			return; // Fall through to normal HTML.
		}

		$name     = $product->get_name();
		$price    = $product->get_price();
		$currency = get_woocommerce_currency();
		$desc     = wp_strip_all_tags( $product->get_description() );
		$short    = wp_strip_all_tags( $product->get_short_description() );
		$sku      = $product->get_sku();
		$stock    = $product->get_stock_status();
		$url      = $product->get_permalink();
		$rating   = $product->get_average_rating();
		$reviews  = $product->get_review_count();

		$md  = "# {$name}\n\n";

		if ( $short ) {
			$md .= "{$short}\n\n";
		}

		$md .= "## Details\n";
		$md .= "- **Price:** {$price} {$currency}";
		if ( $product->is_on_sale() ) {
			$md .= " (on sale, regular: {$product->get_regular_price()} {$currency})";
		}
		$md .= "\n";
		$md .= "- **Availability:** {$stock}\n";
		if ( $sku ) {
			$md .= "- **SKU:** {$sku}\n";
		}
		if ( $rating && $rating > 0 ) {
			$md .= "- **Rating:** {$rating}/5 ({$reviews} reviews)\n";
		}
		$md .= "- **URL:** {$url}\n";
		$md .= "- **Product ID:** {$product->get_id()}\n";

		// Categories.
		$cats = wp_get_post_terms( $product->get_id(), 'product_cat', array( 'fields' => 'names' ) );
		if ( ! is_wp_error( $cats ) && ! empty( $cats ) ) {
			$md .= "- **Categories:** " . implode( ', ', $cats ) . "\n";
		}

		// Attributes.
		$attributes = $product->get_attributes();
		if ( ! empty( $attributes ) ) {
			$md .= "\n## Attributes\n";
			foreach ( $attributes as $attr ) {
				if ( is_a( $attr, 'WC_Product_Attribute' ) ) {
					$label   = wc_attribute_label( $attr->get_name() );
					$options = $attr->is_taxonomy()
						? wp_get_post_terms( $product->get_id(), $attr->get_name(), array( 'fields' => 'names' ) )
						: $attr->get_options();
					if ( ! is_wp_error( $options ) && ! empty( $options ) ) {
						$md .= "- **{$label}:** " . implode( ', ', $options ) . "\n";
					}
				}
			}
		}

		// Description.
		if ( $desc ) {
			$md .= "\n## Description\n{$desc}\n";
		}

		// Variations.
		if ( $product->is_type( 'variable' ) ) {
			$md .= "\n## Variations\n";
			foreach ( $product->get_children() as $var_id ) {
				$var = wc_get_product( $var_id );
				if ( ! $var ) {
					continue;
				}
				$var_attrs = $var->get_attributes();
				$parts     = array();
				foreach ( $var_attrs as $key => $val ) {
					$parts[] = wc_attribute_label( $key ) . ': ' . $val;
				}
				$md .= '- ' . implode( ', ', $parts ) . " — {$var->get_price()} {$currency} ({$var->get_stock_status()})\n";
			}
		}

		// API info.
		$md .= "\n---\n";
		$md .= "**API:** " . rest_url( 'ai-shopping/v1/products/' . $product->get_id() ) . "\n";
		$md .= "**Agent Card:** " . home_url( '/.well-known/agent.json' ) . "\n";

		status_header( 200 );
		header( 'Content-Type: text/markdown; charset=utf-8' );
		header( 'Access-Control-Allow-Origin: *' );
		echo $md; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Serve markdown for shop/catalog/taxonomy pages.
	 */
	private function serve_catalog_markdown() {
		$name     = get_bloginfo( 'name' );
		$currency = get_woocommerce_currency();

		$md = "# {$name} — Product Catalog\n\n";

		// If on a taxonomy page, show category info.
		if ( is_product_taxonomy() ) {
			$term = get_queried_object();
			if ( $term ) {
				$md  = "# {$name} — {$term->name}\n\n";
				if ( $term->description ) {
					$md .= "{$term->description}\n\n";
				}
			}
		}

		// List products on this page.
		$md .= "## Products\n\n";

		if ( have_posts() ) {
			while ( have_posts() ) {
				the_post();
				$product = wc_get_product( get_the_ID() );
				if ( ! $product ) {
					continue;
				}
				$md .= "### {$product->get_name()}\n";
				$md .= "- Price: {$product->get_price()} {$currency}\n";
				$md .= "- Availability: {$product->get_stock_status()}\n";
				$md .= "- URL: {$product->get_permalink()}\n";
				$short = wp_strip_all_tags( $product->get_short_description() );
				if ( $short ) {
					$md .= "- {$short}\n";
				}
				$md .= "\n";
			}
			wp_reset_postdata();
		}

		$md .= "---\n";
		$md .= "**Full catalog feed:** " . home_url( '/ai-shopping-feed.json' ) . "\n";
		$md .= "**API:** " . rest_url( 'ai-shopping/v1/products' ) . "\n";

		status_header( 200 );
		header( 'Content-Type: text/markdown; charset=utf-8' );
		header( 'Access-Control-Allow-Origin: *' );
		echo $md; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}
}
