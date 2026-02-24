<?php
/**
 * Checkout REST API endpoint.
 *
 * @package AIShopping\Api
 */

namespace AIShopping\Api;

defined( 'ABSPATH' ) || exit;

use AIShopping\Cart\Cart_Session;

/**
 * Checkout flow: addresses, shipping methods, payment, order placement.
 */
class Checkout extends REST_Controller {

	/**
	 * Register routes.
	 */
	public function register_routes() {
		$ns = $this->namespace;

		register_rest_route(
			$ns,
			'/checkout/calculate',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'calculate' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
			)
		);

		register_rest_route(
			$ns,
			'/checkout/validate',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'validate' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
			)
		);

		register_rest_route(
			$ns,
			'/checkout/shipping-address',
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'set_shipping_address' ),
				'permission_callback' => array( $this, 'check_write_permission' ),
			)
		);

		register_rest_route(
			$ns,
			'/checkout/billing-address',
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'set_billing_address' ),
				'permission_callback' => array( $this, 'check_write_permission' ),
			)
		);

		register_rest_route(
			$ns,
			'/checkout/shipping-methods',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_shipping_methods' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
			)
		);

		register_rest_route(
			$ns,
			'/checkout/shipping-method',
			array(
				'methods'             => 'PUT',
				'callback'            => array( $this, 'set_shipping_method' ),
				'permission_callback' => array( $this, 'check_write_permission' ),
			)
		);

		register_rest_route(
			$ns,
			'/checkout/payment-methods',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_payment_methods' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
			)
		);

		register_rest_route(
			$ns,
			'/checkout/order',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'place_order' ),
				'permission_callback' => array( $this, 'check_write_permission' ),
			)
		);
	}

	/**
	 * Calculate totals for current cart + address.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function calculate( $request ) {
		$session = $this->load_session( $request );
		if ( is_wp_error( $session ) ) {
			return $this->error_response( $session->get_error_code(), $session->get_error_message(), 400, $request );
		}

		$calculated = Cart_Session::calculate_totals( $session['cart_data'], $session['customer_data'] );

		return $this->success( $calculated, $request );
	}

	/**
	 * Validate checkout fields.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function validate( $request ) {
		$session = $this->load_session( $request );
		if ( is_wp_error( $session ) ) {
			return $this->error_response( $session->get_error_code(), $session->get_error_message(), 400, $request );
		}

		$errors   = array();
		$customer = $session['customer_data'];

		if ( empty( $session['cart_data']['items'] ) ) {
			$errors[] = __( 'Cart is empty. Add items before checking out.', 'ai-shopping' );
		}

		if ( empty( $customer['billing_address'] ) ) {
			$errors[] = __( 'Billing address is required. Use PUT /checkout/billing-address to set it.', 'ai-shopping' );
		} else {
			$billing = $customer['billing_address'];
			$required_billing = array( 'first_name', 'last_name', 'email', 'country' );
			foreach ( $required_billing as $field ) {
				if ( empty( $billing[ $field ] ) ) {
					/* translators: %s: field name */
					$errors[] = sprintf( __( 'Missing required billing field "%s".', 'ai-shopping' ), $field );
				}
			}
			if ( ! empty( $billing['email'] ) && ! is_email( $billing['email'] ) ) {
				$errors[] = __( 'Invalid billing email address.', 'ai-shopping' );
			}
		}

		// Check if shipping is needed.
		$calculated = Cart_Session::calculate_totals( $session['cart_data'], $customer );
		if ( $calculated['needs_shipping'] && empty( $customer['shipping_address'] ) ) {
			$errors[] = __( 'Shipping address is required. Use PUT /checkout/shipping-address to set it.', 'ai-shopping' );
		}

		if ( empty( $customer['payment_method'] ) ) {
			$errors[] = __( 'Payment method is required. Use GET /checkout/payment-methods to see available options.', 'ai-shopping' );
		}

		$valid = empty( $errors );

		return $this->success(
			array(
				'valid'  => $valid,
				'errors' => $errors,
			),
			$request
		);
	}

	/**
	 * Set shipping address.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function set_shipping_address( $request ) {
		$session = $this->load_session( $request );
		if ( is_wp_error( $session ) ) {
			return $this->error_response( $session->get_error_code(), $session->get_error_message(), 400, $request );
		}

		$address = $this->sanitize_address( $request->get_json_params() );
		if ( empty( $address['country'] ) ) {
			return $this->error_response(
				'missing_country',
				__( 'Missing required field "country". Expected ISO 3166-1 alpha-2 country code (e.g., "US", "GB").', 'ai-shopping' ),
				400,
				$request
			);
		}

		$customer = $session['customer_data'];
		$customer['shipping_address'] = $address;
		Cart_Session::save_customer_data( $session['token'], $customer );

		return $this->success(
			array(
				'shipping_address' => $address,
				'message'          => __( 'Shipping address updated.', 'ai-shopping' ),
			),
			$request
		);
	}

	/**
	 * Set billing address.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function set_billing_address( $request ) {
		$session = $this->load_session( $request );
		if ( is_wp_error( $session ) ) {
			return $this->error_response( $session->get_error_code(), $session->get_error_message(), 400, $request );
		}

		$address = $this->sanitize_address( $request->get_json_params() );
		if ( empty( $address['country'] ) ) {
			return $this->error_response(
				'missing_country',
				__( 'Missing required field "country". Expected ISO 3166-1 alpha-2 country code (e.g., "US", "GB").', 'ai-shopping' ),
				400,
				$request
			);
		}

		$customer = $session['customer_data'];
		$customer['billing_address'] = $address;
		Cart_Session::save_customer_data( $session['token'], $customer );

		return $this->success(
			array(
				'billing_address' => $address,
				'message'         => __( 'Billing address updated.', 'ai-shopping' ),
			),
			$request
		);
	}

	/**
	 * Get available shipping methods.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function get_shipping_methods( $request ) {
		$session = $this->load_session( $request );
		if ( is_wp_error( $session ) ) {
			return $this->error_response( $session->get_error_code(), $session->get_error_message(), 400, $request );
		}

		// We need to load the cart into WC to get shipping rates.
		Cart_Session::calculate_totals( $session['cart_data'], $session['customer_data'] );

		$packages = WC()->shipping()->get_packages();
		$methods  = array();

		foreach ( $packages as $package_idx => $package ) {
			if ( ! empty( $package['rates'] ) ) {
				foreach ( $package['rates'] as $rate ) {
					$methods[] = array(
						'id'       => $rate->get_id(),
						'label'    => $rate->get_label(),
						'cost'     => (float) $rate->get_cost(),
						'tax'      => (float) array_sum( $rate->get_taxes() ),
						'method'   => $rate->get_method_id(),
						'instance' => $rate->get_instance_id(),
					);
				}
			}
		}

		return $this->success( array( 'shipping_methods' => $methods ), $request );
	}

	/**
	 * Select a shipping method.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function set_shipping_method( $request ) {
		$session = $this->load_session( $request );
		if ( is_wp_error( $session ) ) {
			return $this->error_response( $session->get_error_code(), $session->get_error_message(), 400, $request );
		}

		$method_id = sanitize_text_field( $request->get_param( 'method_id' ) );
		if ( ! $method_id ) {
			return $this->error_response(
				'missing_method_id',
				__( 'Missing required field "method_id". Use GET /checkout/shipping-methods to see available options.', 'ai-shopping' ),
				400,
				$request
			);
		}

		$customer = $session['customer_data'];
		$customer['shipping_method'] = $method_id;
		Cart_Session::save_customer_data( $session['token'], $customer );

		return $this->success(
			array(
				'shipping_method' => $method_id,
				'message'         => __( 'Shipping method selected.', 'ai-shopping' ),
			),
			$request
		);
	}

	/**
	 * Get available payment methods.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function get_payment_methods( $request ) {
		$gateways = WC()->payment_gateways()->get_available_payment_gateways();
		$methods  = array();

		foreach ( $gateways as $gateway ) {
			$methods[] = array(
				'id'          => $gateway->id,
				'title'       => $gateway->get_title(),
				'description' => wp_strip_all_tags( $gateway->get_description() ),
				'supports'    => $gateway->supports,
			);
		}

		return $this->success( array( 'payment_methods' => $methods ), $request );
	}

	/**
	 * Place an order.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function place_order( $request ) {
		$session = $this->load_session( $request );
		if ( is_wp_error( $session ) ) {
			return $this->error_response( $session->get_error_code(), $session->get_error_message(), 400, $request );
		}

		$cart_data = $session['cart_data'];
		$customer  = $session['customer_data'];

		if ( empty( $cart_data['items'] ) ) {
			return $this->error_response( 'empty_cart', __( 'Cart is empty.', 'ai-shopping' ), 400, $request );
		}

		if ( empty( $customer['billing_address'] ) ) {
			return $this->error_response( 'missing_billing', __( 'Billing address is required.', 'ai-shopping' ), 400, $request );
		}

		// Calculate final totals.
		$calculated = Cart_Session::calculate_totals( $cart_data, $customer );

		// Create the WooCommerce order.
		$order = wc_create_order();

		// Add line items.
		foreach ( $calculated['items'] as $item ) {
			$product = wc_get_product( $item['variation_id'] ?: $item['product_id'] );
			if ( $product ) {
				$order->add_product( $product, $item['quantity'] );
			}
		}

		// Set addresses.
		if ( ! empty( $customer['billing_address'] ) ) {
			$order->set_address( $this->format_order_address( $customer['billing_address'] ), 'billing' );
		}
		if ( ! empty( $customer['shipping_address'] ) ) {
			$order->set_address( $this->format_order_address( $customer['shipping_address'] ), 'shipping' );
		}

		// Set payment method.
		$payment_method = sanitize_text_field( $request->get_param( 'payment_method' ) ?: ( $customer['payment_method'] ?? '' ) );
		if ( $payment_method ) {
			$order->set_payment_method( $payment_method );
		}

		// Apply coupons.
		if ( ! empty( $cart_data['coupons'] ) ) {
			foreach ( $cart_data['coupons'] as $code ) {
				$order->apply_coupon( $code );
			}
		}

		$order->calculate_totals();

		// For non-payment-gateway orders or COD, mark as processing.
		if ( empty( $payment_method ) || 'cod' === $payment_method || 'bacs' === $payment_method || 'cheque' === $payment_method ) {
			$order->set_status( 'processing' );
		} else {
			$order->set_status( 'pending' );
		}

		// Add order note.
		$order->add_order_note( __( 'Order placed via AI Shopping API.', 'ai-shopping' ) );
		$order->save();

		// Clean up the cart session.
		Cart_Session::delete( $session['token'] );

		return $this->success(
			array(
				'order_id'     => $order->get_id(),
				'order_key'    => $order->get_order_key(),
				'status'       => $order->get_status(),
				'total'        => $order->get_total(),
				'currency'     => $order->get_currency(),
				'date_created' => $order->get_date_created() ? $order->get_date_created()->date( 'c' ) : null,
				'message'      => __( 'Order placed successfully.', 'ai-shopping' ),
			),
			$request,
			201
		);
	}

	/**
	 * Load cart session from request.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return array|\WP_Error
	 */
	private function load_session( $request ) {
		$token = Cart_Session::get_token_from_request( $request );
		if ( ! $token ) {
			return new \WP_Error(
				'missing_cart_token',
				__( 'Missing X-Cart-Token header.', 'ai-shopping' )
			);
		}

		$session = Cart_Session::load( $token );
		if ( ! $session ) {
			return new \WP_Error(
				'cart_not_found',
				__( 'Cart session not found or expired.', 'ai-shopping' )
			);
		}

		return $session;
	}

	/**
	 * Sanitize an address array.
	 *
	 * @param array $data Raw address data.
	 * @return array
	 */
	private function sanitize_address( $data ) {
		$fields = array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'email', 'phone' );
		$clean  = array();

		foreach ( $fields as $field ) {
			if ( isset( $data[ $field ] ) ) {
				$clean[ $field ] = sanitize_text_field( $data[ $field ] );
			}
		}

		return $clean;
	}

	/**
	 * Format address for WC_Order::set_address().
	 *
	 * @param array $address Address array.
	 * @return array
	 */
	private function format_order_address( $address ) {
		return array(
			'first_name' => $address['first_name'] ?? '',
			'last_name'  => $address['last_name'] ?? '',
			'company'    => $address['company'] ?? '',
			'address_1'  => $address['address_1'] ?? '',
			'address_2'  => $address['address_2'] ?? '',
			'city'       => $address['city'] ?? '',
			'state'      => $address['state'] ?? '',
			'postcode'   => $address['postcode'] ?? '',
			'country'    => $address['country'] ?? '',
			'email'      => $address['email'] ?? '',
			'phone'      => $address['phone'] ?? '',
		);
	}
}
