<?php
/**
 * Model Context Protocol (MCP) adapter.
 *
 * @package AIShopping\Protocols
 */

namespace AIShopping\Protocols;

defined( 'ABSPATH' ) || exit;

use AIShopping\Api\REST_Controller;

/**
 * Registers MCP tools and serves the MCP tool manifest.
 *
 * Exposes a REST endpoint that returns the MCP tool definitions so that
 * MCP clients can discover what tools are available. Also integrates with
 * the WordPress Abilities API if available.
 */
class MCP_Adapter extends REST_Controller {

	/**
	 * Register MCP routes.
	 */
	public function register_routes() {
		$ns = $this->namespace;

		// MCP tool manifest.
		register_rest_route(
			$ns,
			'/mcp/tools',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_tools' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
			)
		);

		// MCP tool execution.
		register_rest_route(
			$ns,
			'/mcp/tools/(?P<tool>[a-z_]+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'execute_tool' ),
				'permission_callback' => array( $this, 'check_write_permission' ),
			)
		);
	}

	/**
	 * Return the MCP tool manifest.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function list_tools( $request ) {
		$tools = $this->get_tool_definitions();

		$envelope = array(
			'success' => true,
			'data'    => array( 'tools' => $tools ),
			'meta'    => $this->get_meta( 'mcp' ),
		);

		return new \WP_REST_Response( $envelope, 200 );
	}

	/**
	 * Execute an MCP tool.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function execute_tool( $request ) {
		$tool_name = sanitize_text_field( $request['tool'] );
		$params    = $request->get_json_params();

		$tools = $this->get_tool_map();
		if ( ! isset( $tools[ $tool_name ] ) ) {
			return $this->error_response(
				'tool_not_found',
				sprintf(
					/* translators: %s: tool name */
					__( 'MCP tool "%s" not found. Use GET /mcp/tools to see available tools.', 'ai-shopping' ),
					$tool_name
				),
				404,
				$request
			);
		}

		$handler = $tools[ $tool_name ];
		$result  = call_user_func( $handler, $params, $request );

		$envelope = array(
			'success' => true,
			'data'    => $result,
			'meta'    => $this->get_meta( 'mcp' ),
		);

		return new \WP_REST_Response( $envelope, 200 );
	}

	/**
	 * Get MCP tool definitions for the manifest.
	 *
	 * @return array
	 */
	private function get_tool_definitions() {
		return array(
			array(
				'name'        => 'search_products',
				'description' => 'Search and filter products in the store catalog.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'search'     => array( 'type' => 'string', 'description' => 'Search query' ),
						'category'   => array( 'type' => 'string', 'description' => 'Category slug' ),
						'min_price'  => array( 'type' => 'number', 'description' => 'Minimum price' ),
						'max_price'  => array( 'type' => 'number', 'description' => 'Maximum price' ),
						'on_sale'    => array( 'type' => 'boolean', 'description' => 'Only show sale items' ),
						'orderby'    => array( 'type' => 'string', 'enum' => array( 'date', 'price', 'popularity', 'rating', 'title' ) ),
						'per_page'   => array( 'type' => 'integer', 'description' => 'Results per page (max 100)', 'default' => 20 ),
						'page'       => array( 'type' => 'integer', 'description' => 'Page number', 'default' => 1 ),
					),
				),
			),
			array(
				'name'        => 'get_product',
				'description' => 'Get full details for a specific product including variations, attributes, and related products.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'product_id' => array( 'type' => 'integer', 'description' => 'Product ID' ),
					),
					'required'   => array( 'product_id' ),
				),
			),
			array(
				'name'        => 'list_categories',
				'description' => 'List all product categories with hierarchy.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'hide_empty' => array( 'type' => 'boolean', 'description' => 'Hide empty categories', 'default' => true ),
					),
				),
			),
			array(
				'name'        => 'get_product_variations',
				'description' => 'Get all variations for a variable product.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'product_id' => array( 'type' => 'integer', 'description' => 'Variable product ID' ),
					),
					'required'   => array( 'product_id' ),
				),
			),
			array(
				'name'        => 'create_cart',
				'description' => 'Create a new shopping cart session. Returns a cart token for subsequent operations.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => new \stdClass(),
				),
			),
			array(
				'name'        => 'add_to_cart',
				'description' => 'Add a product to the cart.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'cart_token'   => array( 'type' => 'string', 'description' => 'Cart session token' ),
						'product_id'   => array( 'type' => 'integer', 'description' => 'Product ID' ),
						'variation_id' => array( 'type' => 'integer', 'description' => 'Variation ID (for variable products)' ),
						'quantity'     => array( 'type' => 'integer', 'description' => 'Quantity', 'default' => 1 ),
					),
					'required'   => array( 'cart_token', 'product_id' ),
				),
			),
			array(
				'name'        => 'get_cart',
				'description' => 'Get current cart contents with calculated totals.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'cart_token' => array( 'type' => 'string', 'description' => 'Cart session token' ),
					),
					'required'   => array( 'cart_token' ),
				),
			),
			array(
				'name'        => 'get_store_info',
				'description' => 'Get store configuration: name, currency, capabilities, supported extensions, payment methods.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => new \stdClass(),
				),
			),
			array(
				'name'        => 'get_shipping_methods',
				'description' => 'Get available shipping methods for the current cart and address.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'cart_token' => array( 'type' => 'string', 'description' => 'Cart session token' ),
					),
					'required'   => array( 'cart_token' ),
				),
			),
			array(
				'name'        => 'get_payment_gateways',
				'description' => 'List available payment gateways.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => new \stdClass(),
				),
			),
			array(
				'name'        => 'place_order',
				'description' => 'Place an order from the current cart with addresses and payment method.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'cart_token'       => array( 'type' => 'string', 'description' => 'Cart session token' ),
						'billing_address'  => array(
							'type'       => 'object',
							'properties' => array(
								'first_name' => array( 'type' => 'string' ),
								'last_name'  => array( 'type' => 'string' ),
								'email'      => array( 'type' => 'string' ),
								'country'    => array( 'type' => 'string', 'description' => 'ISO 3166-1 alpha-2' ),
								'state'      => array( 'type' => 'string' ),
								'city'       => array( 'type' => 'string' ),
								'postcode'   => array( 'type' => 'string' ),
								'address_1'  => array( 'type' => 'string' ),
							),
							'required'   => array( 'first_name', 'last_name', 'email', 'country' ),
						),
						'payment_method'   => array( 'type' => 'string', 'description' => 'Payment gateway ID' ),
					),
					'required'   => array( 'cart_token', 'billing_address', 'payment_method' ),
				),
			),
			array(
				'name'        => 'get_order',
				'description' => 'Get order details by order ID.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'order_id' => array( 'type' => 'integer', 'description' => 'Order ID' ),
					),
					'required'   => array( 'order_id' ),
				),
			),
		);
	}

	/**
	 * Get tool name to handler mapping.
	 *
	 * @return array
	 */
	private function get_tool_map() {
		return array(
			'search_products'       => array( $this, 'tool_search_products' ),
			'get_product'           => array( $this, 'tool_get_product' ),
			'list_categories'       => array( $this, 'tool_list_categories' ),
			'get_product_variations' => array( $this, 'tool_get_variations' ),
			'create_cart'           => array( $this, 'tool_create_cart' ),
			'add_to_cart'           => array( $this, 'tool_add_to_cart' ),
			'get_cart'              => array( $this, 'tool_get_cart' ),
			'get_store_info'        => array( $this, 'tool_get_store_info' ),
			'get_shipping_methods'  => array( $this, 'tool_get_shipping_methods' ),
			'get_payment_gateways'  => array( $this, 'tool_get_payment_gateways' ),
			'place_order'           => array( $this, 'tool_place_order' ),
			'get_order'             => array( $this, 'tool_get_order' ),
		);
	}

	/**
	 * Tool: Search products.
	 *
	 * @param array $params Tool parameters.
	 * @return array
	 */
	public function tool_search_products( $params ) {
		$request = new \WP_REST_Request( 'GET' );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		$api      = new \AIShopping\Api\Products();
		$response = $api->list_products( $request );
		return $response->get_data()['data'] ?? $response->get_data();
	}

	/**
	 * Tool: Get product.
	 *
	 * @param array $params Tool parameters.
	 * @return array
	 */
	public function tool_get_product( $params ) {
		$request = new \WP_REST_Request( 'GET' );
		$request['id'] = (int) ( $params['product_id'] ?? 0 );
		$api      = new \AIShopping\Api\Products();
		$response = $api->get_product( $request );
		return $response->get_data()['data'] ?? $response->get_data();
	}

	/**
	 * Tool: List categories.
	 *
	 * @param array $params Tool parameters.
	 * @return array
	 */
	public function tool_list_categories( $params ) {
		$request = new \WP_REST_Request( 'GET' );
		if ( isset( $params['hide_empty'] ) ) {
			$request->set_param( 'hide_empty', $params['hide_empty'] ? 'true' : 'false' );
		}
		$api      = new \AIShopping\Api\Products();
		$response = $api->list_categories( $request );
		return $response->get_data()['data'] ?? $response->get_data();
	}

	/**
	 * Tool: Get variations.
	 *
	 * @param array $params Tool parameters.
	 * @return array
	 */
	public function tool_get_variations( $params ) {
		$request = new \WP_REST_Request( 'GET' );
		$request['id'] = (int) ( $params['product_id'] ?? 0 );
		$api      = new \AIShopping\Api\Products();
		$response = $api->get_variations( $request );
		return $response->get_data()['data'] ?? $response->get_data();
	}

	/**
	 * Tool: Create cart.
	 *
	 * @param array            $params  Tool parameters.
	 * @param \WP_REST_Request $request The request.
	 * @return array
	 */
	public function tool_create_cart( $params, $request = null ) {
		$key_row = $request ? $request->get_param( '_ais_key' ) : null;
		$token   = \AIShopping\Cart\Cart_Session::create( $key_row ? $key_row['id'] : 0 );
		return array( 'cart_token' => $token );
	}

	/**
	 * Tool: Add to cart.
	 *
	 * @param array $params Tool parameters.
	 * @return array
	 */
	public function tool_add_to_cart( $params ) {
		$token   = $params['cart_token'] ?? '';
		$session = \AIShopping\Cart\Cart_Session::load( $token );
		if ( ! $session ) {
			return array( 'error' => 'Cart not found' );
		}

		$cart_data    = $session['cart_data'];
		$product_id   = (int) ( $params['product_id'] ?? 0 );
		$variation_id = (int) ( $params['variation_id'] ?? 0 );
		$quantity     = max( 1, (int) ( $params['quantity'] ?? 1 ) );
		$item_key     = md5( $product_id . '-' . $variation_id );

		$cart_data['items'][] = array(
			'_key'         => $item_key,
			'product_id'   => $product_id,
			'variation_id' => $variation_id,
			'variation'    => array(),
			'quantity'     => $quantity,
		);

		\AIShopping\Cart\Cart_Session::save( $token, $cart_data );
		return \AIShopping\Cart\Cart_Session::calculate_totals( $cart_data, $session['customer_data'] );
	}

	/**
	 * Tool: Get cart.
	 *
	 * @param array $params Tool parameters.
	 * @return array
	 */
	public function tool_get_cart( $params ) {
		$session = \AIShopping\Cart\Cart_Session::load( $params['cart_token'] ?? '' );
		if ( ! $session ) {
			return array( 'error' => 'Cart not found' );
		}
		return \AIShopping\Cart\Cart_Session::calculate_totals( $session['cart_data'], $session['customer_data'] );
	}

	/**
	 * Tool: Get store info.
	 *
	 * @param array $params Tool parameters.
	 * @return array
	 */
	public function tool_get_store_info( $params ) {
		$request  = new \WP_REST_Request( 'GET' );
		$api      = new \AIShopping\Api\Store();
		$response = $api->get_store_info( $request );
		return $response->get_data()['data'] ?? $response->get_data();
	}

	/**
	 * Tool: Get shipping methods.
	 *
	 * @param array $params Tool parameters.
	 * @return array
	 */
	public function tool_get_shipping_methods( $params ) {
		$request = new \WP_REST_Request( 'GET' );
		$request->set_header( 'X-Cart-Token', $params['cart_token'] ?? '' );
		$api      = new \AIShopping\Api\Checkout();
		$response = $api->get_shipping_methods( $request );
		return $response->get_data()['data'] ?? $response->get_data();
	}

	/**
	 * Tool: Get payment gateways.
	 *
	 * @param array $params Tool parameters.
	 * @return array
	 */
	public function tool_get_payment_gateways( $params ) {
		$request  = new \WP_REST_Request( 'GET' );
		$api      = new \AIShopping\Api\Checkout();
		$response = $api->get_payment_methods( $request );
		return $response->get_data()['data'] ?? $response->get_data();
	}

	/**
	 * Tool: Place order.
	 *
	 * @param array $params Tool parameters.
	 * @return array
	 */
	public function tool_place_order( $params ) {
		$token   = $params['cart_token'] ?? '';
		$session = \AIShopping\Cart\Cart_Session::load( $token );
		if ( ! $session ) {
			return array( 'error' => 'Cart not found' );
		}

		// Set customer data.
		$customer = $session['customer_data'];
		if ( ! empty( $params['billing_address'] ) ) {
			$customer['billing_address'] = array_map( 'sanitize_text_field', $params['billing_address'] );
		}
		if ( ! empty( $params['shipping_address'] ) ) {
			$customer['shipping_address'] = array_map( 'sanitize_text_field', $params['shipping_address'] );
		}
		if ( ! empty( $params['payment_method'] ) ) {
			$customer['payment_method'] = sanitize_text_field( $params['payment_method'] );
		}

		\AIShopping\Cart\Cart_Session::save_customer_data( $token, $customer );

		// Create order.
		$calculated = \AIShopping\Cart\Cart_Session::calculate_totals( $session['cart_data'], $customer );
		$order      = wc_create_order();

		foreach ( $calculated['items'] as $item ) {
			$product = wc_get_product( $item['variation_id'] ?: $item['product_id'] );
			if ( $product ) {
				$order->add_product( $product, $item['quantity'] );
			}
		}

		if ( ! empty( $customer['billing_address'] ) ) {
			$order->set_address( $customer['billing_address'], 'billing' );
		}
		if ( ! empty( $customer['shipping_address'] ) ) {
			$order->set_address( $customer['shipping_address'], 'shipping' );
		}
		if ( ! empty( $customer['payment_method'] ) ) {
			$order->set_payment_method( $customer['payment_method'] );
		}

		$order->calculate_totals();
		$order->set_status( 'processing' );
		$order->add_order_note( __( 'Order placed via MCP (Model Context Protocol).', 'ai-shopping' ) );
		$order->save();

		\AIShopping\Cart\Cart_Session::delete( $token );

		return array(
			'order_id' => $order->get_id(),
			'status'   => $order->get_status(),
			'total'    => (float) $order->get_total(),
		);
	}

	/**
	 * Tool: Get order.
	 *
	 * @param array $params Tool parameters.
	 * @return array
	 */
	public function tool_get_order( $params ) {
		$request = new \WP_REST_Request( 'GET' );
		$request['id'] = (int) ( $params['order_id'] ?? 0 );
		$api      = new \AIShopping\Api\Orders();
		$response = $api->get_order( $request );
		return $response->get_data()['data'] ?? $response->get_data();
	}
}
