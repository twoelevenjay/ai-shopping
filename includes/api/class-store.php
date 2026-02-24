<?php
/**
 * Store info REST API endpoint.
 *
 * @package AIShopping\Api
 */

namespace AIShopping\Api;

defined( 'ABSPATH' ) || exit;

use AIShopping\Extensions\Extension_Detector;

/**
 * Read-only store configuration: name, currency, shipping zones, payment gateways, tax rates.
 */
class Store extends REST_Controller {

	/**
	 * Register routes.
	 */
	public function register_routes() {
		$ns = $this->namespace;

		register_rest_route(
			$ns,
			'/store',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_store_info' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
			)
		);

		register_rest_route(
			$ns,
			'/store/shipping-zones',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_shipping_zones' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
			)
		);

		register_rest_route(
			$ns,
			'/store/payment-gateways',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_payment_gateways' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
			)
		);

		register_rest_route(
			$ns,
			'/store/tax-rates',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_tax_rates' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
			)
		);
	}

	/**
	 * Get comprehensive store information.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function get_store_info( $request ) {
		$data = array(
			'name'               => get_bloginfo( 'name' ),
			'description'        => get_bloginfo( 'description' ),
			'url'                => home_url(),
			'woocommerce'        => array(
				'version' => defined( 'WC_VERSION' ) ? WC_VERSION : null,
			),
			'currency'           => get_woocommerce_currency(),
			'currency_symbol'    => get_woocommerce_currency_symbol(),
			'currency_position'  => get_option( 'woocommerce_currency_pos' ),
			'decimal_separator'  => wc_get_price_decimal_separator(),
			'thousand_separator' => wc_get_price_thousand_separator(),
			'decimals'           => wc_get_price_decimals(),
			'locale'             => get_locale(),
			'timezone'           => wp_timezone_string(),
			'weight_unit'        => get_option( 'woocommerce_weight_unit' ),
			'dimension_unit'     => get_option( 'woocommerce_dimension_unit' ),
			'store_address'      => array(
				'address_1' => get_option( 'woocommerce_store_address' ),
				'address_2' => get_option( 'woocommerce_store_address_2' ),
				'city'       => get_option( 'woocommerce_store_city' ),
				'postcode'   => get_option( 'woocommerce_store_postcode' ),
				'country'    => WC()->countries->get_base_country(),
				'state'      => WC()->countries->get_base_state(),
			),
			'selling_countries'  => array_keys( WC()->countries->get_allowed_countries() ),
			'shipping_countries' => array_keys( WC()->countries->get_shipping_countries() ),
			'tax_enabled'        => wc_tax_enabled(),
			'prices_include_tax' => 'yes' === get_option( 'woocommerce_prices_include_tax' ),
			'product_types'      => array_keys( wc_get_product_types() ),
			'protocols'          => array(
				'acp' => 'yes' === get_option( 'ais_enable_acp', 'yes' ),
				'ucp' => 'yes' === get_option( 'ais_enable_ucp', 'yes' ),
				'mcp' => 'yes' === get_option( 'ais_enable_mcp', 'yes' ),
			),
			'extensions'         => Extension_Detector::get_active_extensions(),
			'capabilities'       => $this->get_capabilities(),
		);

		return $this->success( $data, $request );
	}

	/**
	 * Get shipping zones with methods.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function get_shipping_zones( $request ) {
		$zones_data = array();
		$zones      = \WC_Shipping_Zones::get_zones();

		foreach ( $zones as $zone_data ) {
			$zone = new \WC_Shipping_Zone( $zone_data['id'] );
			$zones_data[] = $this->format_shipping_zone( $zone );
		}

		// Rest of World zone (ID 0).
		$rest_of_world = new \WC_Shipping_Zone( 0 );
		$zones_data[]  = $this->format_shipping_zone( $rest_of_world );

		return $this->success( array( 'shipping_zones' => $zones_data ), $request );
	}

	/**
	 * Get available payment gateways.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function get_payment_gateways( $request ) {
		$gateways = WC()->payment_gateways()->payment_gateways();
		$data     = array();

		foreach ( $gateways as $gateway ) {
			if ( 'yes' !== $gateway->enabled ) {
				continue;
			}
			$data[] = array(
				'id'          => $gateway->id,
				'title'       => $gateway->get_title(),
				'description' => wp_strip_all_tags( $gateway->get_description() ),
				'supports'    => $gateway->supports,
				'order'       => $gateway->order,
			);
		}

		return $this->success( array( 'payment_gateways' => $data ), $request );
	}

	/**
	 * Get tax rates.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function get_tax_rates( $request ) {
		global $wpdb;

		$rates = $wpdb->get_results(
			"SELECT * FROM {$wpdb->prefix}woocommerce_tax_rates ORDER BY tax_rate_order",
			ARRAY_A
		);

		$data = array();
		foreach ( $rates as $rate ) {
			$data[] = array(
				'id'       => (int) $rate['tax_rate_id'],
				'country'  => $rate['tax_rate_country'],
				'state'    => $rate['tax_rate_state'],
				'postcode' => '',
				'city'     => '',
				'rate'     => $rate['tax_rate'],
				'name'     => $rate['tax_rate_name'],
				'priority' => (int) $rate['tax_rate_priority'],
				'compound' => (bool) $rate['tax_rate_compound'],
				'shipping' => (bool) $rate['tax_rate_shipping'],
				'class'    => $rate['tax_rate_class'],
			);
		}

		return $this->success( array( 'tax_rates' => $data ), $request );
	}

	/**
	 * Get store capabilities summary.
	 *
	 * @return array
	 */
	private function get_capabilities() {
		return array(
			'product_search'   => true,
			'product_filter'   => true,
			'headless_cart'    => true,
			'checkout'         => true,
			'order_tracking'   => true,
			'coupons'          => true,
			'reviews'          => true,
			'shipping_calc'    => true,
			'tax_calc'         => wc_tax_enabled(),
			'subscriptions'    => Extension_Detector::is_extension_active( 'subscriptions' ),
			'bookings'         => Extension_Detector::is_extension_active( 'bookings' ),
			'bundles'          => Extension_Detector::is_extension_active( 'bundles' ),
			'composites'       => Extension_Detector::is_extension_active( 'composites' ),
			'memberships'      => Extension_Detector::is_extension_active( 'memberships' ),
			'multilingual'     => Extension_Detector::is_extension_active( 'multilingual' ),
		);
	}

	/**
	 * Format a shipping zone.
	 *
	 * @param \WC_Shipping_Zone $zone The zone.
	 * @return array
	 */
	private function format_shipping_zone( $zone ) {
		$methods = array();
		foreach ( $zone->get_shipping_methods( true ) as $method ) {
			$methods[] = array(
				'id'          => $method->id,
				'instance_id' => $method->get_instance_id(),
				'title'       => $method->get_title(),
				'enabled'     => $method->is_enabled(),
			);
		}

		return array(
			'id'        => $zone->get_id(),
			'name'      => $zone->get_zone_name(),
			'locations' => $zone->get_zone_locations(),
			'methods'   => $methods,
		);
	}
}
