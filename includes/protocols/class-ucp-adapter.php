<?php
/**
 * Universal Commerce Protocol (UCP) adapter.
 *
 * @package AIShopping\Protocols
 */

namespace AIShopping\Protocols;

defined( 'ABSPATH' ) || exit;

use AIShopping\Api\REST_Controller;
use AIShopping\Cart\Cart_Session;
use AIShopping\Extensions\Extension_Detector;

/**
 * UCP implementation: merchant profile, capability negotiation, shopping service.
 *
 * Serves /.well-known/ucp (handled in Plugin class) and REST endpoints.
 */
class UCP_Adapter extends REST_Controller {

	/**
	 * Register UCP routes.
	 */
	public function register_routes() {
		$ns = $this->namespace;

		// Capability negotiation.
		register_rest_route(
			$ns,
			'/ucp/negotiate',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'negotiate' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
			)
		);

		// Shopping service.
		register_rest_route(
			$ns,
			'/ucp/catalog/search',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'catalog_search' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
			)
		);

		register_rest_route(
			$ns,
			'/ucp/catalog/products/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'catalog_product' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
			)
		);

		register_rest_route(
			$ns,
			'/ucp/catalog/categories',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'catalog_categories' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
			)
		);

		// Checkout session (UCP state machine).
		register_rest_route(
			$ns,
			'/ucp/checkout',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_session' ),
				'permission_callback' => array( $this, 'check_write_permission' ),
			)
		);

		register_rest_route(
			$ns,
			'/ucp/checkout/(?P<id>[a-zA-Z0-9]+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_session' ),
					'permission_callback' => array( $this, 'check_read_permission' ),
				),
				array(
					'methods'             => 'PATCH',
					'callback'            => array( $this, 'update_session' ),
					'permission_callback' => array( $this, 'check_write_permission' ),
				),
			)
		);

		register_rest_route(
			$ns,
			'/ucp/checkout/(?P<id>[a-zA-Z0-9]+)/complete',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'complete_session' ),
				'permission_callback' => array( $this, 'check_write_permission' ),
			)
		);

		// Orders.
		register_rest_route(
			$ns,
			'/ucp/orders/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_order' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
			)
		);
	}

	/**
	 * Get the merchant profile for /.well-known/ucp.
	 *
	 * @return array
	 */
	public function get_merchant_profile() {
		$extensions = Extension_Detector::get_active_extensions();

		$capabilities = array(
			'dev.ucp.shopping.catalog',
			'dev.ucp.shopping.checkout',
			'dev.ucp.shopping.orders',
		);

		// Add fulfillment capability if shipping is configured.
		$zones = \WC_Shipping_Zones::get_zones();
		if ( ! empty( $zones ) ) {
			$capabilities[] = 'dev.ucp.shopping.fulfillment';
		}

		// Payment handlers.
		$payment_handlers = array();
		$gateways = WC()->payment_gateways()->get_available_payment_gateways();
		foreach ( $gateways as $gateway ) {
			$payment_handlers[] = $gateway->id;
		}

		return array(
			'ucp_version'      => '1.0',
			'merchant'         => array(
				'name'        => get_bloginfo( 'name' ),
				'description' => get_bloginfo( 'description' ),
				'url'         => home_url(),
				'logo'        => get_site_icon_url(),
			),
			'api_base'         => rest_url( $this->namespace . '/ucp' ),
			'capabilities'     => $capabilities,
			'payment_handlers' => $payment_handlers,
			'currency'         => get_woocommerce_currency(),
			'supported_locales' => array( get_locale() ),
			'extensions'       => array_keys( $extensions ),
		);
	}

	/**
	 * Capability negotiation.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function negotiate( $request ) {
		$agent_caps     = $request->get_param( 'capabilities' ) ?: array();
		$merchant_caps  = $this->get_merchant_profile()['capabilities'];
		$intersection   = array_values( array_intersect( $merchant_caps, $agent_caps ) );

		$agent_payments   = $request->get_param( 'payment_handlers' ) ?: array();
		$merchant_payments = $this->get_merchant_profile()['payment_handlers'];
		$payment_match     = array_values( array_intersect( $merchant_payments, $agent_payments ) );

		$data = array(
			'negotiated_capabilities'    => ! empty( $intersection ) ? $intersection : $merchant_caps,
			'negotiated_payment_handlers' => $payment_match,
			'merchant_profile'           => $this->get_merchant_profile(),
		);

		$envelope = array(
			'success' => true,
			'data'    => $data,
			'meta'    => $this->get_meta( 'ucp' ),
		);

		return new \WP_REST_Response( $envelope, 200 );
	}

	/**
	 * Catalog search.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function catalog_search( $request ) {
		$products_api = new \AIShopping\Api\Products();
		return $products_api->list_products( $request );
	}

	/**
	 * Catalog product detail.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function catalog_product( $request ) {
		$products_api = new \AIShopping\Api\Products();
		return $products_api->get_product( $request );
	}

	/**
	 * Catalog categories.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function catalog_categories( $request ) {
		$products_api = new \AIShopping\Api\Products();
		return $products_api->list_categories( $request );
	}

	/**
	 * Create a UCP checkout session.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function create_session( $request ) {
		$key_row = $request->get_param( '_ais_key' );
		$token   = Cart_Session::create( $key_row ? $key_row['id'] : 0 );

		$items = $request->get_param( 'line_items' ) ?: array();
		$cart_data = array( 'items' => array(), 'coupons' => array() );

		foreach ( $items as $item ) {
			$product_id = (int) ( $item['product_id'] ?? 0 );
			if ( $product_id ) {
				$cart_data['items'][] = array(
					'_key'         => md5( $product_id . '-' . (int) ( $item['variation_id'] ?? 0 ) ),
					'product_id'   => $product_id,
					'variation_id' => (int) ( $item['variation_id'] ?? 0 ),
					'variation'    => array(),
					'quantity'     => max( 1, (int) ( $item['quantity'] ?? 1 ) ),
				);
			}
		}

		Cart_Session::save( $token, $cart_data );

		$data = array(
			'session_id' => $token,
			'status'     => 'incomplete',
			'line_items' => $cart_data['items'],
			'messages'   => array(
				__( 'Checkout session created. Add shipping/billing addresses and select payment method to proceed.', 'ai-shopping' ),
			),
		);

		$envelope = array(
			'success' => true,
			'data'    => $data,
			'meta'    => $this->get_meta( 'ucp' ),
		);

		return new \WP_REST_Response( $envelope, 201 );
	}

	/**
	 * Get UCP checkout session state.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function get_session( $request ) {
		$session = Cart_Session::load( sanitize_text_field( $request['id'] ) );
		if ( ! $session ) {
			return $this->error_response( 'session_not_found', __( 'Checkout session not found or expired.', 'ai-shopping' ), 404, $request );
		}

		$calculated = Cart_Session::calculate_totals( $session['cart_data'], $session['customer_data'] );
		$status     = $this->determine_ucp_status( $session );

		$data = array(
			'session_id'     => $session['token'],
			'status'         => $status,
			'line_items'     => $calculated['items'],
			'subtotal'       => $calculated['subtotal'],
			'tax_total'      => $calculated['tax_total'],
			'shipping_total' => $calculated['shipping_total'],
			'total'          => $calculated['total'],
			'currency'       => $calculated['currency'],
			'customer'       => $session['customer_data'],
			'messages'       => $this->get_status_messages( $status, $session ),
		);

		$envelope = array(
			'success' => true,
			'data'    => $data,
			'meta'    => $this->get_meta( 'ucp' ),
		);

		return new \WP_REST_Response( $envelope, 200 );
	}

	/**
	 * Update UCP checkout session.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function update_session( $request ) {
		$token   = sanitize_text_field( $request['id'] );
		$session = Cart_Session::load( $token );

		if ( ! $session ) {
			return $this->error_response( 'session_not_found', __( 'Checkout session not found or expired.', 'ai-shopping' ), 404, $request );
		}

		$customer = $session['customer_data'];
		$params   = $request->get_json_params();

		if ( isset( $params['billing_address'] ) ) {
			$customer['billing_address'] = array_map( 'sanitize_text_field', $params['billing_address'] );
		}
		if ( isset( $params['shipping_address'] ) ) {
			$customer['shipping_address'] = array_map( 'sanitize_text_field', $params['shipping_address'] );
		}
		if ( isset( $params['payment_method'] ) ) {
			$customer['payment_method'] = sanitize_text_field( $params['payment_method'] );
		}
		if ( isset( $params['shipping_method'] ) ) {
			$customer['shipping_method'] = sanitize_text_field( $params['shipping_method'] );
		}

		Cart_Session::save_customer_data( $token, $customer );

		// Reload and respond.
		$session['customer_data'] = $customer;
		$status = $this->determine_ucp_status( $session );

		$data = array(
			'session_id' => $token,
			'status'     => $status,
			'customer'   => $customer,
			'messages'   => $this->get_status_messages( $status, $session ),
		);

		$envelope = array(
			'success' => true,
			'data'    => $data,
			'meta'    => $this->get_meta( 'ucp' ),
		);

		return new \WP_REST_Response( $envelope, 200 );
	}

	/**
	 * Complete UCP checkout session.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function complete_session( $request ) {
		$token   = sanitize_text_field( $request['id'] );
		$session = Cart_Session::load( $token );

		if ( ! $session ) {
			return $this->error_response( 'session_not_found', __( 'Checkout session not found or expired.', 'ai-shopping' ), 404, $request );
		}

		$status = $this->determine_ucp_status( $session );
		if ( 'ready_for_complete' !== $status ) {
			return $this->error_response(
				'not_ready',
				sprintf(
					/* translators: %s: current status */
					__( 'Checkout is not ready to complete. Current status: %s. Ensure billing address and payment method are set.', 'ai-shopping' ),
					$status
				),
				400,
				$request
			);
		}

		// Place the order (reuse ACP logic).
		$calculated = Cart_Session::calculate_totals( $session['cart_data'], $session['customer_data'] );
		$order      = wc_create_order();
		$customer   = $session['customer_data'];

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
		$order->add_order_note( __( 'Order placed via UCP (Universal Commerce Protocol).', 'ai-shopping' ) );
		$order->save();

		Cart_Session::delete( $token );

		$data = array(
			'session_id'   => $token,
			'status'       => 'complete',
			'order_id'     => $order->get_id(),
			'order_status' => $order->get_status(),
			'total'        => (float) $order->get_total(),
			'currency'     => $order->get_currency(),
		);

		$envelope = array(
			'success' => true,
			'data'    => $data,
			'meta'    => $this->get_meta( 'ucp' ),
		);

		return new \WP_REST_Response( $envelope, 200 );
	}

	/**
	 * Get a UCP order.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function get_order( $request ) {
		$orders_api = new \AIShopping\Api\Orders();
		return $orders_api->get_order( $request );
	}

	/**
	 * Determine UCP checkout status from session state.
	 *
	 * UCP state machine: incomplete → requires_escalation → ready_for_complete → complete
	 *
	 * @param array $session Session data.
	 * @return string
	 */
	private function determine_ucp_status( $session ) {
		$customer = $session['customer_data'];
		$items    = $session['cart_data']['items'] ?? array();

		if ( empty( $items ) ) {
			return 'incomplete';
		}

		if ( empty( $customer['billing_address'] ) ) {
			return 'incomplete';
		}

		if ( empty( $customer['payment_method'] ) ) {
			return 'incomplete';
		}

		return 'ready_for_complete';
	}

	/**
	 * Get human-readable messages for a UCP status.
	 *
	 * @param string $status  UCP status.
	 * @param array  $session Session data.
	 * @return array
	 */
	private function get_status_messages( $status, $session ) {
		$messages = array();
		$customer = $session['customer_data'];

		if ( 'incomplete' === $status ) {
			if ( empty( $session['cart_data']['items'] ) ) {
				$messages[] = __( 'Add items to continue.', 'ai-shopping' );
			}
			if ( empty( $customer['billing_address'] ) ) {
				$messages[] = __( 'Billing address is required.', 'ai-shopping' );
			}
			if ( empty( $customer['payment_method'] ) ) {
				$messages[] = __( 'Payment method is required.', 'ai-shopping' );
			}
		} elseif ( 'ready_for_complete' === $status ) {
			$messages[] = __( 'Checkout is ready. Call /complete to place the order.', 'ai-shopping' );
		}

		return $messages;
	}
}
