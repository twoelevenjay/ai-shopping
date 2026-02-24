<?php
/**
 * Cart REST API endpoint.
 *
 * @package AIShopping\Api
 */

namespace AIShopping\Api;

defined( 'ABSPATH' ) || exit;

use AIShopping\Cart\Cart_Session;

/**
 * Headless cart management: create, add items, update, remove, coupons.
 */
class Cart extends REST_Controller {

	/**
	 * Register routes.
	 */
	public function register_routes() {
		$ns = $this->namespace;

		// Create cart.
		register_rest_route(
			$ns,
			'/cart',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create_cart' ),
					'permission_callback' => array( $this, 'check_write_permission' ),
				),
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_cart' ),
					'permission_callback' => array( $this, 'check_read_permission' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'empty_cart' ),
					'permission_callback' => array( $this, 'check_write_permission' ),
				),
			)
		);

		// Cart items.
		register_rest_route(
			$ns,
			'/cart/items',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'add_item' ),
				'permission_callback' => array( $this, 'check_write_permission' ),
			)
		);

		register_rest_route(
			$ns,
			'/cart/items/(?P<key>[a-zA-Z0-9]+)',
			array(
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'check_write_permission' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'remove_item' ),
					'permission_callback' => array( $this, 'check_write_permission' ),
				),
			)
		);

		// Coupons.
		register_rest_route(
			$ns,
			'/cart/coupons',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'apply_coupon' ),
				'permission_callback' => array( $this, 'check_write_permission' ),
			)
		);

		register_rest_route(
			$ns,
			'/cart/coupons/(?P<code>[a-zA-Z0-9_-]+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'remove_coupon' ),
				'permission_callback' => array( $this, 'check_write_permission' ),
			)
		);
	}

	/**
	 * Create a new cart session.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function create_cart( $request ) {
		$key_row = $request->get_param( '_ais_key' );
		$key_id  = $key_row ? $key_row['id'] : 0;

		$token = Cart_Session::create( $key_id );

		return $this->success(
			array(
				'cart_token' => $token,
				'message'    => __( 'Cart created. Include this token in the X-Cart-Token header for all cart operations.', 'ai-shopping' ),
			),
			$request,
			201
		);
	}

	/**
	 * Get cart contents with calculated totals.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function get_cart( $request ) {
		$session = $this->load_session( $request );
		if ( is_wp_error( $session ) ) {
			return $this->error_response( $session->get_error_code(), $session->get_error_message(), 400, $request );
		}

		$calculated = Cart_Session::calculate_totals( $session['cart_data'], $session['customer_data'] );

		return $this->success( $calculated, $request );
	}

	/**
	 * Add an item to the cart.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function add_item( $request ) {
		$session = $this->load_session( $request );
		if ( is_wp_error( $session ) ) {
			return $this->error_response( $session->get_error_code(), $session->get_error_message(), 400, $request );
		}

		$product_id   = (int) $request->get_param( 'product_id' );
		$variation_id = (int) $request->get_param( 'variation_id' );
		$quantity     = max( 1, (int) $request->get_param( 'quantity' ) ?: 1 );

		if ( ! $product_id ) {
			return $this->error_response(
				'missing_product_id',
				__( 'Missing required field "product_id". Provide the WooCommerce product ID as an integer.', 'ai-shopping' ),
				400,
				$request
			);
		}

		$product = wc_get_product( $variation_id ?: $product_id );
		if ( ! $product ) {
			return $this->error_response( 'product_not_found', __( 'Product not found.', 'ai-shopping' ), 404, $request );
		}

		if ( ! $product->is_purchasable() ) {
			return $this->error_response( 'not_purchasable', __( 'This product cannot be purchased.', 'ai-shopping' ), 400, $request );
		}

		if ( ! $product->is_in_stock() ) {
			return $this->error_response( 'out_of_stock', __( 'This product is out of stock.', 'ai-shopping' ), 400, $request );
		}

		$cart_data = $session['cart_data'];

		// Build variation attributes.
		$variation = array();
		if ( $variation_id ) {
			$var_product = wc_get_product( $variation_id );
			if ( $var_product && $var_product->is_type( 'variation' ) ) {
				$variation = $var_product->get_attributes();
			}
		}

		// Generate a cart item key.
		$item_key = md5( $product_id . '-' . $variation_id . '-' . wp_json_encode( $variation ) );

		// Check if item already exists, update quantity.
		$found = false;
		foreach ( $cart_data['items'] as &$item ) {
			if ( isset( $item['_key'] ) && $item['_key'] === $item_key ) {
				$item['quantity'] += $quantity;
				$found = true;
				break;
			}
		}
		unset( $item );

		if ( ! $found ) {
			$cart_data['items'][] = array(
				'_key'         => $item_key,
				'product_id'   => $product_id,
				'variation_id' => $variation_id,
				'variation'    => $variation,
				'quantity'     => $quantity,
			);
		}

		Cart_Session::save( $session['token'], $cart_data );

		// Return calculated cart.
		$calculated = Cart_Session::calculate_totals( $cart_data, $session['customer_data'] );

		return $this->success( $calculated, $request, 201 );
	}

	/**
	 * Update a cart item's quantity.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function update_item( $request ) {
		$session = $this->load_session( $request );
		if ( is_wp_error( $session ) ) {
			return $this->error_response( $session->get_error_code(), $session->get_error_message(), 400, $request );
		}

		$item_key = sanitize_text_field( $request['key'] );
		$quantity = (int) $request->get_param( 'quantity' );

		if ( $quantity < 1 ) {
			return $this->error_response(
				'invalid_quantity',
				__( 'Quantity must be at least 1. To remove an item, use DELETE /cart/items/{key}.', 'ai-shopping' ),
				400,
				$request
			);
		}

		$cart_data = $session['cart_data'];
		$found     = false;

		foreach ( $cart_data['items'] as &$item ) {
			if ( ( isset( $item['_key'] ) && $item['_key'] === $item_key ) ) {
				$item['quantity'] = $quantity;
				$found = true;
				break;
			}
		}
		unset( $item );

		if ( ! $found ) {
			return $this->error_response( 'item_not_found', __( 'Cart item not found.', 'ai-shopping' ), 404, $request );
		}

		Cart_Session::save( $session['token'], $cart_data );
		$calculated = Cart_Session::calculate_totals( $cart_data, $session['customer_data'] );

		return $this->success( $calculated, $request );
	}

	/**
	 * Remove an item from the cart.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function remove_item( $request ) {
		$session = $this->load_session( $request );
		if ( is_wp_error( $session ) ) {
			return $this->error_response( $session->get_error_code(), $session->get_error_message(), 400, $request );
		}

		$item_key  = sanitize_text_field( $request['key'] );
		$cart_data = $session['cart_data'];
		$original  = count( $cart_data['items'] );

		$cart_data['items'] = array_values(
			array_filter(
				$cart_data['items'],
				function ( $item ) use ( $item_key ) {
					return ! ( isset( $item['_key'] ) && $item['_key'] === $item_key );
				}
			)
		);

		if ( count( $cart_data['items'] ) === $original ) {
			return $this->error_response( 'item_not_found', __( 'Cart item not found.', 'ai-shopping' ), 404, $request );
		}

		Cart_Session::save( $session['token'], $cart_data );
		$calculated = Cart_Session::calculate_totals( $cart_data, $session['customer_data'] );

		return $this->success( $calculated, $request );
	}

	/**
	 * Empty the cart.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function empty_cart( $request ) {
		$token = Cart_Session::get_token_from_request( $request );
		if ( ! $token ) {
			return $this->error_response(
				'missing_cart_token',
				__( 'Missing X-Cart-Token header. Create a cart first with POST /cart.', 'ai-shopping' ),
				400,
				$request
			);
		}

		Cart_Session::delete( $token );

		return $this->success(
			array( 'message' => __( 'Cart emptied.', 'ai-shopping' ) ),
			$request
		);
	}

	/**
	 * Apply a coupon code.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function apply_coupon( $request ) {
		$session = $this->load_session( $request );
		if ( is_wp_error( $session ) ) {
			return $this->error_response( $session->get_error_code(), $session->get_error_message(), 400, $request );
		}

		$code = sanitize_text_field( $request->get_param( 'code' ) );
		if ( ! $code ) {
			return $this->error_response(
				'missing_coupon_code',
				__( 'Missing required field "code". Provide a valid coupon code string.', 'ai-shopping' ),
				400,
				$request
			);
		}

		// Validate coupon exists.
		$coupon = new \WC_Coupon( $code );
		if ( ! $coupon->get_id() ) {
			return $this->error_response( 'invalid_coupon', __( 'Coupon code not found.', 'ai-shopping' ), 404, $request );
		}

		$cart_data = $session['cart_data'];
		if ( ! in_array( $code, $cart_data['coupons'], true ) ) {
			$cart_data['coupons'][] = $code;
		}

		Cart_Session::save( $session['token'], $cart_data );
		$calculated = Cart_Session::calculate_totals( $cart_data, $session['customer_data'] );

		return $this->success( $calculated, $request );
	}

	/**
	 * Remove a coupon.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function remove_coupon( $request ) {
		$session = $this->load_session( $request );
		if ( is_wp_error( $session ) ) {
			return $this->error_response( $session->get_error_code(), $session->get_error_message(), 400, $request );
		}

		$code      = sanitize_text_field( $request['code'] );
		$cart_data = $session['cart_data'];
		$cart_data['coupons'] = array_values(
			array_filter(
				$cart_data['coupons'],
				function ( $c ) use ( $code ) {
					return $c !== $code;
				}
			)
		);

		Cart_Session::save( $session['token'], $cart_data );
		$calculated = Cart_Session::calculate_totals( $cart_data, $session['customer_data'] );

		return $this->success( $calculated, $request );
	}

	/**
	 * Load the cart session from the request token.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return array|\WP_Error
	 */
	private function load_session( $request ) {
		$token = Cart_Session::get_token_from_request( $request );
		if ( ! $token ) {
			return new \WP_Error(
				'missing_cart_token',
				__( 'Missing X-Cart-Token header. Create a cart first with POST /cart.', 'ai-shopping' )
			);
		}

		$session = Cart_Session::load( $token );
		if ( ! $session ) {
			return new \WP_Error(
				'cart_not_found',
				__( 'Cart session not found or expired. Create a new cart with POST /cart.', 'ai-shopping' )
			);
		}

		return $session;
	}
}
