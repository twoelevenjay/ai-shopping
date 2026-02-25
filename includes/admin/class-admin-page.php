<?php
/**
 * Admin post action handlers.
 *
 * Handles admin_post actions for feed cache clearing.
 * Settings UI lives in WC_Settings_AI_Shopping (WooCommerce > Settings > AI Shopping).
 *
 * @package AIShopping\Admin
 */

namespace AIShopping\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Admin action handlers.
 */
class Admin_Page {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_post_ais_clear_feed_cache', array( $this, 'handle_clear_feed_cache' ) );
	}

	/**
	 * Handle feed cache clear action.
	 */
	public function handle_clear_feed_cache() {
		check_admin_referer( 'ais_clear_feed_cache' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'ai-shopping' ) );
		}

		delete_transient( 'ais_product_feed' );

		wp_safe_redirect( admin_url( 'admin.php?page=wc-settings&tab=ai-shopping&section=discovery&cache_cleared=1' ) );
		exit;
	}
}
