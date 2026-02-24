<?php
/**
 * Plugin Name: AI Shopping for WooCommerce
 * Plugin URI:  https://github.com/twoelevenjay/ai-shopping
 * Description: Instantly expose your WooCommerce storefront to AI agents via ACP, UCP, and MCP protocols. Zero-config product discovery, cart management, checkout, and order tracking for any AI agent.
 * Version:     1.0.0
 * Author:      flavflavor
 * Author URI:  https://flavflavor.com
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ai-shopping
 * Domain Path: /languages
 * Requires at least: 6.4
 * Tested up to: 6.9
 * Requires PHP: 7.4
 * WC requires at least: 8.0
 * WC tested up to: 10.5
 *
 * @package AIShopping
 */

defined( 'ABSPATH' ) || exit;

// Plugin constants.
define( 'AIS_VERSION', '1.0.0' );
define( 'AIS_PLUGIN_FILE', __FILE__ );
define( 'AIS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AIS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'AIS_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Autoloader for AIShopping classes.
 *
 * Maps AIShopping\Sub\ClassName to includes/sub/class-classname.php
 */
spl_autoload_register(
	function ( $class ) {
		$prefix = 'AIShopping\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}

		$relative = substr( $class, strlen( $prefix ) );
		$parts    = explode( '\\', $relative );
		$filename = 'class-' . strtolower( str_replace( '_', '-', array_pop( $parts ) ) ) . '.php';
		$path     = AIS_PLUGIN_DIR . 'includes/';

		if ( ! empty( $parts ) ) {
			$path .= strtolower( implode( '/', $parts ) ) . '/';
		}

		$file = $path . $filename;
		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
);

/**
 * Check requirements and boot the plugin.
 */
function ais_init() {
	// Check for WooCommerce.
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'ais_woocommerce_missing_notice' );
		return;
	}

	// Declare HPOS compatibility.
	add_action(
		'before_woocommerce_init',
		function () {
			if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
				\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			}
		}
	);

	// Boot the plugin.
	\AIShopping\Plugin::instance();
}
add_action( 'plugins_loaded', 'ais_init', 10 );

/**
 * Admin notice when WooCommerce is not active.
 */
function ais_woocommerce_missing_notice() {
	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html__( 'AI Shopping for WooCommerce requires WooCommerce to be installed and active.', 'ai-shopping' )
	);
}

/**
 * Activation hook.
 */
function ais_activate() {
	// Create custom tables.
	require_once AIS_PLUGIN_DIR . 'includes/cart/class-cart-session.php';
	\AIShopping\Cart\Cart_Session::create_tables();

	require_once AIS_PLUGIN_DIR . 'includes/security/class-auth.php';
	\AIShopping\Security\Auth::create_tables();

	require_once AIS_PLUGIN_DIR . 'includes/security/class-rate-limiter.php';
	\AIShopping\Security\Rate_Limiter::create_tables();

	// Store version for upgrade routines.
	update_option( 'ais_version', AIS_VERSION );
	update_option( 'ais_db_version', AIS_VERSION );

	// Set default options.
	$defaults = array(
		'ais_enable_acp'           => 'yes',
		'ais_enable_ucp'           => 'yes',
		'ais_enable_mcp'           => 'yes',
		'ais_rate_limit_read'      => 60,
		'ais_rate_limit_write'     => 30,
		'ais_enable_logging'       => 'no',
		'ais_allow_http'           => 'yes',
	);

	foreach ( $defaults as $key => $value ) {
		if ( false === get_option( $key ) ) {
			update_option( $key, $value );
		}
	}

	// Flush rewrite rules for well-known endpoint.
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'ais_activate' );

/**
 * Deactivation hook.
 */
function ais_deactivate() {
	// Clean up scheduled events.
	wp_clear_scheduled_hook( 'ais_daily_extension_scan' );
	wp_clear_scheduled_hook( 'ais_cleanup_expired_carts' );
	wp_clear_scheduled_hook( 'ais_cleanup_rate_limits' );
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'ais_deactivate' );
