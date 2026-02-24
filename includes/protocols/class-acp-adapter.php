<?php
/**
 * Agentic Commerce Protocol (ACP) adapter.
 *
 * @package AIShopping\Protocols
 */

namespace AIShopping\Protocols;

defined( 'ABSPATH' ) || exit;

use AIShopping\Api\REST_Controller;
use AIShopping\Cart\Cart_Session;

/**
 * Maps ACP's 4-endpoint checkout model to the internal API.
 *
 * ACP endpoints:
 * - POST   /acp/checkout            — Create checkout
 * - POST   /acp/checkout/{id}       — Update checkout
 * - POST   /acp/checkout/{id}/complete — Complete checkout
 * - DELETE /acp/checkout/{id}       — Cancel checkout
 */
class ACP_Adapter extends REST_Controller {

	/**
	 * Register ACP routes.
	 */
	public function register_routes() {
		$ns = $this->namespace;

		// Product discovery (ACP agents need this too).
		register_rest_route(
			$ns,
			'/acp/products',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'search_products' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
			)
		);

		register_rest_route(
			$ns,
			'/acp/checkout',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_checkout' ),
				'permission_callback' => array( $this, 'check_write_permission' ),
			)
		);

		register_rest_route(
			$ns,
			'/acp/checkout/(?P<id>[a-zA-Z0-9]+)',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'update_checkout' ),
					'permission_callback' => array( $this, 'check_write_permission' ),
				),
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_checkout' ),
					'permission_callback' => array( $this, 'check_read_permission' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'cancel_checkout' ),
					'permission_callback' => array( $this, 'check_write_permission' ),
				),
			)
		);

		register_rest_route(
			$ns,
			'/acp/checkout/(?P<id>[a-zA-Z0-9]+)/complete',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'complete_checkout' ),
				'permission_callback' => array( $this, 'check_write_permission' ),
			)
		);
	}

	/**
	 * Search products (ACP wrapper).
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function search_products( $request ) {
		$products_api = new \AIShopping\Api\Products();
		$response     = $products_api->list_products( $request );
		return $response;
	}

	/**
	 * Create a new ACP checkout session.
	 *
	 * Accepts product SKU(s)/ID(s), creates a cart + checkout state.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function create_checkout( $request ) {
		$key_row = $request->get_param( '_ais_key' );
		$items   = $request->get_param( 'items' );

		if ( empty( $items ) || ! is_array( $items ) ) {
			return $this->error_response(
				'missing_items',
				__( 'Missing required field "items". Provide an array of {product_id, quantity} or {sku, quantity}.', 'ai-shopping' ),
				400,
				$request
			);
		}

		// Create cart session.
		$token     = Cart_Session::create( $key_row ? $key_row['id'] : 0 );
		$cart_data = array( 'items' => array(), 'coupons' => array() );

		foreach ( $items as $item ) {
			$product_id   = 0;
			$variation_id = 0;

			if ( ! empty( $item['product_id'] ) ) {
				$product_id = (int) $item['product_id'];
			} elseif ( ! empty( $item['sku'] ) ) {
				$product_id = wc_get_product_id_by_sku( sanitize_text_field( $item['sku'] ) );
			}

			if ( ! empty( $item['variation_id'] ) ) {
				$variation_id = (int) $item['variation_id'];
			}

			if ( ! $product_id ) {
				continue;
			}

			$quantity = max( 1, (int) ( $item['quantity'] ?? 1 ) );
			$item_key = md5( $product_id . '-' . $variation_id );

			$cart_data['items'][] = array(
				'_key'         => $item_key,
				'product_id'   => $product_id,
				'variation_id' => $variation_id,
				'variation'    => array(),
				'quantity'     => $quantity,
			);
		}

		Cart_Session::save( $token, $cart_data );
		$calculated = Cart_Session::calculate_totals( $cart_data );

		// Get available payment methods.
		$gateways = WC()->payment_gateways()->get_available_payment_gateways();
		$payment_methods = array();
		foreach ( $gateways as $gateway ) {
			$payment_methods[] = array(
				'id'    => $gateway->id,
				'title' => $gateway->get_title(),
			);
		}

		$response = array(
			'checkout_id'      => $token,
			'status'           => 'open',
			'line_items'       => $calculated['items'],
			'subtotal'         => $calculated['subtotal'],
			'tax_total'        => $calculated['tax_total'],
			'shipping_total'   => $calculated['shipping_total'],
			'total'            => $calculated['total'],
			'currency'         => $calculated['currency'],
			'payment_methods'  => $payment_methods,
			'needs_shipping'   => $calculated['needs_shipping'],
			'fulfillment_options' => $calculated['needs_shipping'] ? array( 'shipping' ) : array( 'digital' ),
		);

		$envelope = array(
			'success' => true,
			'data'    => $response,
			'meta'    => $this->get_meta( 'acp' ),
		);

		$rest_response = new \WP_REST_Response( $envelope, 201 );
		$rest_response->header( 'X-Checkout-ID', $token );
		$this->add_rate_headers( $rest_response, $request );
		return $rest_response;
	}

	/**
	 * Get checkout state.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function get_checkout( $request ) {
		$session = Cart_Session::load( sanitize_text_field( $request['id'] ) );
		if ( ! $session ) {
			return $this->error_response( 'checkout_not_found', __( 'Checkout session not found or expired.', 'ai-shopping' ), 404, $request );
		}

		$calculated = Cart_Session::calculate_totals( $session['cart_data'], $session['customer_data'] );

		$data = array(
			'checkout_id'    => $session['token'],
			'status'         => 'open',
			'line_items'     => $calculated['items'],
			'subtotal'       => $calculated['subtotal'],
			'tax_total'      => $calculated['tax_total'],
			'shipping_total' => $calculated['shipping_total'],
			'total'          => $calculated['total'],
			'currency'       => $calculated['currency'],
			'customer'       => $session['customer_data'],
		);

		$envelope = array(
			'success' => true,
			'data'    => $data,
			'meta'    => $this->get_meta( 'acp' ),
		);

		return new \WP_REST_Response( $envelope, 200 );
	}

	/**
	 * Update an ACP checkout (quantities, addresses, shipping, coupons).
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function update_checkout( $request ) {
		$token   = sanitize_text_field( $request['id'] );
		$session = Cart_Session::load( $token );

		if ( ! $session ) {
			return $this->error_response( 'checkout_not_found', __( 'Checkout session not found or expired.', 'ai-shopping' ), 404, $request );
		}

		$cart_data = $session['cart_data'];
		$customer  = $session['customer_data'];

		// Update line items if provided.
		$items = $request->get_param( 'items' );
		if ( is_array( $items ) ) {
			$cart_data['items'] = array();
			foreach ( $items as $item ) {
				$product_id   = (int) ( $item['product_id'] ?? 0 );
				$variation_id = (int) ( $item['variation_id'] ?? 0 );
				$quantity     = max( 1, (int) ( $item['quantity'] ?? 1 ) );

				if ( $product_id ) {
					$cart_data['items'][] = array(
						'_key'         => md5( $product_id . '-' . $variation_id ),
						'product_id'   => $product_id,
						'variation_id' => $variation_id,
						'variation'    => array(),
						'quantity'     => $quantity,
					);
				}
			}
		}

		// Update addresses.
		$billing = $request->get_param( 'billing_address' );
		if ( is_array( $billing ) ) {
			$customer['billing_address'] = array_map( 'sanitize_text_field', $billing );
		}

		$shipping = $request->get_param( 'shipping_address' );
		if ( is_array( $shipping ) ) {
			$customer['shipping_address'] = array_map( 'sanitize_text_field', $shipping );
		}

		// Shipping method.
		$shipping_method = $request->get_param( 'shipping_method' );
		if ( $shipping_method ) {
			$customer['shipping_method'] = sanitize_text_field( $shipping_method );
		}

		// Payment method.
		$payment_method = $request->get_param( 'payment_method' );
		if ( $payment_method ) {
			$customer['payment_method'] = sanitize_text_field( $payment_method );
		}

		// Discount code.
		$discount_code = $request->get_param( 'discount_code' );
		if ( $discount_code ) {
			$cart_data['coupons'][] = sanitize_text_field( $discount_code );
			$cart_data['coupons']   = array_unique( $cart_data['coupons'] );
		}

		Cart_Session::save( $token, $cart_data );
		Cart_Session::save_customer_data( $token, $customer );

		$calculated = Cart_Session::calculate_totals( $cart_data, $customer );

		$data = array(
			'checkout_id'    => $token,
			'status'         => 'open',
			'line_items'     => $calculated['items'],
			'subtotal'       => $calculated['subtotal'],
			'tax_total'      => $calculated['tax_total'],
			'shipping_total' => $calculated['shipping_total'],
			'discount_total' => $calculated['discount_total'],
			'total'          => $calculated['total'],
			'currency'       => $calculated['currency'],
			'customer'       => $customer,
		);

		$envelope = array(
			'success' => true,
			'data'    => $data,
			'meta'    => $this->get_meta( 'acp' ),
		);

		return new \WP_REST_Response( $envelope, 200 );
	}

	/**
	 * Complete checkout and place order.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function complete_checkout( $request ) {
		$token   = sanitize_text_field( $request['id'] );
		$session = Cart_Session::load( $token );

		if ( ! $session ) {
			return $this->error_response( 'checkout_not_found', __( 'Checkout session not found or expired.', 'ai-shopping' ), 404, $request );
		}

		$cart_data = $session['cart_data'];
		$customer  = $session['customer_data'];

		if ( empty( $cart_data['items'] ) ) {
			return $this->error_response( 'empty_cart', __( 'Checkout has no items.', 'ai-shopping' ), 400, $request );
		}

		// Calculate final totals.
		$calculated = Cart_Session::calculate_totals( $cart_data, $customer );

		// Create WooCommerce order.
		$order = wc_create_order();

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

		$payment_method = $request->get_param( 'payment_method' ) ?: ( $customer['payment_method'] ?? '' );
		if ( $payment_method ) {
			$order->set_payment_method( sanitize_text_field( $payment_method ) );
		}

		if ( ! empty( $cart_data['coupons'] ) ) {
			foreach ( $cart_data['coupons'] as $code ) {
				$order->apply_coupon( $code );
			}
		}

		$order->calculate_totals();
		$order->set_status( 'processing' );
		$order->add_order_note( __( 'Order placed via ACP (Agentic Commerce Protocol).', 'ai-shopping' ) );
		$order->save();

		// Clean up checkout session.
		Cart_Session::delete( $token );

		$data = array(
			'checkout_id'  => $token,
			'status'       => 'complete',
			'order_id'     => $order->get_id(),
			'order_key'    => $order->get_order_key(),
			'order_status' => $order->get_status(),
			'total'        => (float) $order->get_total(),
			'currency'     => $order->get_currency(),
			'date_created' => $order->get_date_created() ? $order->get_date_created()->date( 'c' ) : null,
		);

		$envelope = array(
			'success' => true,
			'data'    => $data,
			'meta'    => $this->get_meta( 'acp' ),
		);

		return new \WP_REST_Response( $envelope, 200 );
	}

	/**
	 * Cancel a checkout.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function cancel_checkout( $request ) {
		$token   = sanitize_text_field( $request['id'] );
		$session = Cart_Session::load( $token );

		if ( ! $session ) {
			return $this->error_response( 'checkout_not_found', __( 'Checkout session not found or expired.', 'ai-shopping' ), 404, $request );
		}

		Cart_Session::delete( $token );

		$envelope = array(
			'success' => true,
			'data'    => array(
				'checkout_id' => $token,
				'status'      => 'canceled',
				'message'     => __( 'Checkout canceled and cart released.', 'ai-shopping' ),
			),
			'meta'    => $this->get_meta( 'acp' ),
		);

		return new \WP_REST_Response( $envelope, 200 );
	}
}
