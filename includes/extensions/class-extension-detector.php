<?php
/**
 * Extension detector — scans for supported WooCommerce extensions.
 *
 * @package AIShopping\Extensions
 */

namespace AIShopping\Extensions;

defined( 'ABSPATH' ) || exit;

/**
 * Detects active WooCommerce extensions and reports compatibility.
 */
class Extension_Detector {

	/**
	 * Transient name for cached scan results.
	 */
	const TRANSIENT = 'ais_extension_scan';

	/**
	 * Map of extension slug → detection data.
	 *
	 * @return array
	 */
	public static function get_extension_map() {
		return array(
			'subscriptions'         => array(
				'name'   => 'WooCommerce Subscriptions',
				'detect' => array( 'class' => 'WC_Subscriptions' ),
				'plugin' => 'woocommerce-subscriptions/woocommerce-subscriptions.php',
			),
			'bundles'               => array(
				'name'   => 'WooCommerce Product Bundles',
				'detect' => array( 'class' => 'WC_Bundles' ),
				'plugin' => 'woocommerce-product-bundles/woocommerce-product-bundles.php',
			),
			'composites'            => array(
				'name'   => 'WooCommerce Composite Products',
				'detect' => array( 'class' => 'WC_Composite_Products' ),
				'plugin' => 'woocommerce-composite-products/woocommerce-composite-products.php',
			),
			'addons'                => array(
				'name'   => 'WooCommerce Product Add-Ons',
				'detect' => array( 'class' => 'WC_Product_Addons' ),
				'plugin' => 'woocommerce-product-addons/woocommerce-product-addons.php',
			),
			'memberships'           => array(
				'name'   => 'WooCommerce Memberships',
				'detect' => array( 'class' => 'WC_Memberships' ),
				'plugin' => 'woocommerce-memberships/woocommerce-memberships.php',
			),
			'bookings'              => array(
				'name'   => 'WooCommerce Bookings',
				'detect' => array( 'class' => 'WC_Bookings' ),
				'plugin' => 'woocommerce-bookings/woocommerce-bookings.php',
			),
			'mix_and_match'         => array(
				'name'   => 'WooCommerce Mix and Match Products',
				'detect' => array( 'class' => 'WC_Mix_and_Match' ),
				'plugin' => 'woocommerce-mix-and-match-products/woocommerce-mix-and-match-products.php',
			),
			'points_rewards'        => array(
				'name'   => 'WooCommerce Points and Rewards',
				'detect' => array( 'class' => 'WC_Points_Rewards' ),
				'plugin' => 'woocommerce-points-and-rewards/woocommerce-points-and-rewards.php',
			),
			'gift_cards'            => array(
				'name'   => 'WooCommerce Gift Cards',
				'detect' => array( 'class' => 'WC_GC' ),
				'plugin' => 'woocommerce-gift-cards/woocommerce-gift-cards.php',
			),
			'stripe'                => array(
				'name'   => 'WooCommerce Stripe Gateway',
				'detect' => array( 'class' => 'WC_Stripe' ),
				'plugin' => 'woocommerce-gateway-stripe/woocommerce-gateway-stripe.php',
			),
			'paypal'                => array(
				'name'   => 'WooCommerce PayPal Payments',
				'detect' => array( 'function' => 'wc_paypal_payments_init' ),
				'plugin' => 'woocommerce-paypal-payments/woocommerce-paypal-payments.php',
			),
			'multilingual'          => array(
				'name'   => 'WPML WooCommerce Multilingual',
				'detect' => array( 'class' => 'woocommerce_wpml' ),
				'plugin' => 'woocommerce-multilingual/wpml-woocommerce.php',
			),
			'acf'                   => array(
				'name'   => 'Advanced Custom Fields',
				'detect' => array( 'function' => 'acf_get_field_groups' ),
				'plugin' => 'advanced-custom-fields-pro/acf.php',
			),
			'wishlist'              => array(
				'name'   => 'YITH WooCommerce Wishlist',
				'detect' => array( 'class' => 'YITH_WCWL' ),
				'plugin' => 'yith-woocommerce-wishlist/init.php',
			),
			'dynamic_pricing'       => array(
				'name'   => 'WooCommerce Dynamic Pricing',
				'detect' => array( 'class' => 'WC_Dynamic_Pricing' ),
				'plugin' => 'woocommerce-dynamic-pricing/woocommerce-dynamic-pricing.php',
			),
			'all_products_subs'     => array(
				'name'   => 'All Products for WooCommerce Subscriptions',
				'detect' => array( 'class' => 'WCS_ATT' ),
				'plugin' => 'woocommerce-all-products-for-subscriptions/woocommerce-all-products-for-subscriptions.php',
			),
		);
	}

	/**
	 * Run a scan and cache results.
	 *
	 * @return array Map of slug => active status.
	 */
	public static function scan() {
		$map    = self::get_extension_map();
		$result = array();

		foreach ( $map as $slug => $ext ) {
			$active = false;

			if ( ! empty( $ext['detect']['class'] ) && class_exists( $ext['detect']['class'] ) ) {
				$active = true;
			} elseif ( ! empty( $ext['detect']['function'] ) && function_exists( $ext['detect']['function'] ) ) {
				$active = true;
			} elseif ( ! empty( $ext['plugin'] ) ) {
				if ( ! function_exists( 'is_plugin_active' ) ) {
					include_once ABSPATH . 'wp-admin/includes/plugin.php';
				}
				$active = is_plugin_active( $ext['plugin'] );
			}

			$result[ $slug ] = array(
				'name'   => $ext['name'],
				'active' => $active,
			);
		}

		set_transient( self::TRANSIENT, $result, DAY_IN_SECONDS );
		return $result;
	}

	/**
	 * Get scan results (from cache or fresh).
	 *
	 * @return array
	 */
	public static function get_scan_results() {
		$cached = get_transient( self::TRANSIENT );
		if ( false !== $cached ) {
			return $cached;
		}
		return self::scan();
	}

	/**
	 * Check if a specific extension is active.
	 *
	 * @param string $slug Extension slug.
	 * @return bool
	 */
	public static function is_extension_active( $slug ) {
		$results = self::get_scan_results();
		return ! empty( $results[ $slug ]['active'] );
	}

	/**
	 * Get only active extensions.
	 *
	 * @return array
	 */
	public static function get_active_extensions() {
		$results = self::get_scan_results();
		$active  = array();

		foreach ( $results as $slug => $data ) {
			if ( $data['active'] ) {
				$active[ $slug ] = $data['name'];
			}
		}

		return $active;
	}
}
