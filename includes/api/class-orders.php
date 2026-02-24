<?php
/**
 * Orders REST API endpoint.
 *
 * @package AIShopping\Api
 */

namespace AIShopping\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Order retrieval, tracking, and notes.
 */
class Orders extends REST_Controller {

	/**
	 * Register routes.
	 */
	public function register_routes() {
		$ns = $this->namespace;

		register_rest_route(
			$ns,
			'/orders/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_order' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
			)
		);

		register_rest_route(
			$ns,
			'/orders/(?P<id>\d+)/tracking',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_tracking' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
			)
		);

		register_rest_route(
			$ns,
			'/orders/(?P<id>\d+)/notes',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'add_note' ),
				'permission_callback' => array( $this, 'check_write_permission' ),
			)
		);
	}

	/**
	 * Get order details.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function get_order( $request ) {
		$order = wc_get_order( (int) $request['id'] );
		if ( ! $order ) {
			return $this->error_response( 'order_not_found', __( 'Order not found.', 'ai-shopping' ), 404, $request );
		}

		$data = $this->format_order( $order );

		return $this->success( $data, $request );
	}

	/**
	 * Get order tracking info.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function get_tracking( $request ) {
		$order = wc_get_order( (int) $request['id'] );
		if ( ! $order ) {
			return $this->error_response( 'order_not_found', __( 'Order not found.', 'ai-shopping' ), 404, $request );
		}

		// Check for WooCommerce Shipment Tracking data.
		$tracking = array();
		$tracking_items = $order->get_meta( '_wc_shipment_tracking_items', true );
		if ( ! empty( $tracking_items ) && is_array( $tracking_items ) ) {
			foreach ( $tracking_items as $item ) {
				$tracking[] = array(
					'provider'        => $item['tracking_provider'] ?? '',
					'tracking_number' => $item['tracking_number'] ?? '',
					'tracking_link'   => $item['tracking_link'] ?? '',
					'date_shipped'    => $item['date_shipped'] ?? '',
				);
			}
		}

		return $this->success(
			array(
				'order_id' => $order->get_id(),
				'status'   => $order->get_status(),
				'tracking' => $tracking,
			),
			$request
		);
	}

	/**
	 * Add a customer note to an order.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function add_note( $request ) {
		$order = wc_get_order( (int) $request['id'] );
		if ( ! $order ) {
			return $this->error_response( 'order_not_found', __( 'Order not found.', 'ai-shopping' ), 404, $request );
		}

		$note = sanitize_textarea_field( $request->get_param( 'note' ) );
		if ( ! $note ) {
			return $this->error_response(
				'missing_note',
				__( 'Missing required field "note". Provide the note text as a string.', 'ai-shopping' ),
				400,
				$request
			);
		}

		$note_id = $order->add_order_note( $note, 1 ); // 1 = customer note.

		return $this->success(
			array(
				'note_id' => $note_id,
				'message' => __( 'Note added to order.', 'ai-shopping' ),
			),
			$request,
			201
		);
	}

	/**
	 * Format an order for API response.
	 *
	 * @param \WC_Order $order The order.
	 * @return array
	 */
	private function format_order( $order ) {
		$items = array();
		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			$items[] = array(
				'id'           => $item->get_id(),
				'product_id'   => $item->get_product_id(),
				'variation_id' => $item->get_variation_id(),
				'name'         => $item->get_name(),
				'sku'          => $product ? $product->get_sku() : '',
				'quantity'     => $item->get_quantity(),
				'subtotal'     => (float) $item->get_subtotal(),
				'total'        => (float) $item->get_total(),
				'tax'          => (float) $item->get_total_tax(),
			);
		}

		$notes = array();
		foreach ( $order->get_customer_order_notes() as $note ) {
			$notes[] = array(
				'id'      => $note->comment_ID,
				'content' => $note->comment_content,
				'date'    => $note->comment_date_gmt,
			);
		}

		return array(
			'id'               => $order->get_id(),
			'order_key'        => $order->get_order_key(),
			'status'           => $order->get_status(),
			'currency'         => $order->get_currency(),
			'subtotal'         => (float) $order->get_subtotal(),
			'discount_total'   => (float) $order->get_discount_total(),
			'shipping_total'   => (float) $order->get_shipping_total(),
			'tax_total'        => (float) $order->get_total_tax(),
			'total'            => (float) $order->get_total(),
			'payment_method'   => $order->get_payment_method(),
			'date_created'     => $order->get_date_created() ? $order->get_date_created()->date( 'c' ) : null,
			'date_modified'    => $order->get_date_modified() ? $order->get_date_modified()->date( 'c' ) : null,
			'date_completed'   => $order->get_date_completed() ? $order->get_date_completed()->date( 'c' ) : null,
			'billing_address'  => $order->get_address( 'billing' ),
			'shipping_address' => $order->get_address( 'shipping' ),
			'items'            => $items,
			'coupons'          => array_map(
				function ( $coupon ) {
					return array(
						'code'     => $coupon->get_code(),
						'discount' => (float) $coupon->get_discount(),
					);
				},
				$order->get_items( 'coupon' )
			),
			'notes'            => $notes,
			'customer_id'      => $order->get_customer_id(),
			'customer_note'    => $order->get_customer_note(),
		);
	}
}
