<?php
/**
 * Webhook dispatcher — fires on WooCommerce order status transitions.
 *
 * @package AIShopping\Webhooks
 */

namespace AIShopping\Webhooks;

defined( 'ABSPATH' ) || exit;

use AIShopping\Security\Auth;
use AIShopping\Security\HMAC;

/**
 * Dispatches webhook events to registered URLs on order status changes.
 */
class Webhook_Dispatcher {

	/**
	 * Constructor — hook into WooCommerce order status transitions.
	 */
	public function __construct() {
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_order_status_change' ), 10, 4 );
	}

	/**
	 * Handle order status change.
	 *
	 * @param int      $order_id   Order ID.
	 * @param string   $old_status Old status.
	 * @param string   $new_status New status.
	 * @param \WC_Order $order     The order object.
	 */
	public function on_order_status_change( $order_id, $old_status, $new_status, $order ) {
		$webhook_url = get_option( 'ais_webhook_url' );
		if ( empty( $webhook_url ) ) {
			return;
		}

		$event_map = array(
			'processing' => 'order.confirmed',
			'completed'  => 'order.completed',
			'shipped'    => 'order.shipped',
			'refunded'   => 'order.refunded',
			'cancelled'  => 'order.canceled',
			'on-hold'    => 'order.on_hold',
			'failed'     => 'order.failed',
		);

		$event_type = $event_map[ $new_status ] ?? 'order.status_changed';

		$payload = array(
			'event'      => $event_type,
			'order_id'   => $order_id,
			'old_status' => $old_status,
			'new_status' => $new_status,
			'total'      => (float) $order->get_total(),
			'currency'   => $order->get_currency(),
			'timestamp'  => gmdate( 'c' ),
		);

		$json = wp_json_encode( $payload );

		// Get webhook secret from the first active API key (or global).
		$webhook_secret = get_option( 'ais_webhook_secret', '' );
		$signature      = '';
		if ( $webhook_secret ) {
			$signature = HMAC::sign( $json, $webhook_secret );
		}

		$args = array(
			'body'    => $json,
			'headers' => array(
				'Content-Type'        => 'application/json',
				'X-AIS-Event'         => $event_type,
				'X-AIS-Signature'     => $signature,
				'X-AIS-Timestamp'     => (string) time(),
			),
			'timeout'   => 15,
			'sslverify' => true,
		);

		wp_remote_post( $webhook_url, $args );
	}
}
