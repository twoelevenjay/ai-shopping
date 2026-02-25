<?php
/**
 * Product feed generator (OpenAI format).
 *
 * Generates a full machine-readable product catalog cached via transient.
 *
 * @package AIShopping\Discovery
 */

namespace AIShopping\Discovery;

defined( 'ABSPATH' ) || exit;

/**
 * Static methods for generating the product feed.
 */
class Product_Feed {

	/**
	 * Transient key for cached feed.
	 */
	const CACHE_KEY = 'ais_product_feed';

	/**
	 * Cache TTL in seconds (1 hour).
	 */
	const CACHE_TTL = HOUR_IN_SECONDS;

	/**
	 * Generate the product feed (from cache or fresh).
	 *
	 * @return array
	 */
	public static function generate() {
		$cached = get_transient( self::CACHE_KEY );
		if ( false !== $cached ) {
			return $cached;
		}

		$feed = self::build_feed();
		set_transient( self::CACHE_KEY, $feed, self::CACHE_TTL );

		return $feed;
	}

	/**
	 * Build the complete product feed.
	 *
	 * @return array
	 */
	private static function build_feed() {
		$args = array(
			'status' => 'publish',
			'limit'  => 100,
			'page'   => 1,
		);

		/**
		 * Filter the product feed query arguments.
		 *
		 * @param array $args wc_get_products arguments.
		 */
		$args = apply_filters( 'ais_product_feed_query_args', $args );

		$products   = array();
		$page       = 1;
		$has_more   = true;

		while ( $has_more ) {
			$args['page'] = $page;
			$batch = wc_get_products( $args );

			if ( empty( $batch ) ) {
				$has_more = false;
				break;
			}

			foreach ( $batch as $product ) {
				$products[] = self::format_product( $product );
			}

			if ( count( $batch ) < $args['limit'] ) {
				$has_more = false;
			}

			++$page;
		}

		return array(
			'version'    => '1.0',
			'store'      => array(
				'name'     => get_bloginfo( 'name' ),
				'url'      => home_url(),
				'currency' => get_woocommerce_currency(),
			),
			'updated_at' => gmdate( 'c' ),
			'products'   => $products,
			'total'      => count( $products ),
			'api'        => array(
				'base_url'   => rest_url( 'ai-shopping/v1' ),
				'agent_card' => home_url( '/.well-known/agent.json' ),
			),
		);
	}

	/**
	 * Format a single product for the feed.
	 *
	 * @param \WC_Product $product The product.
	 * @return array
	 */
	private static function format_product( $product ) {
		$data = array(
			'id'           => $product->get_id(),
			'title'        => $product->get_name(),
			'url'          => $product->get_permalink(),
			'description'  => wp_strip_all_tags( $product->get_short_description() ?: $product->get_description() ),
			'price'        => $product->get_price(),
			'regular_price' => $product->get_regular_price(),
			'sale_price'   => $product->get_sale_price() ?: null,
			'currency'     => get_woocommerce_currency(),
			'availability' => $product->get_stock_status(),
			'sku'          => $product->get_sku() ?: null,
			'type'         => $product->get_type(),
		);

		// Image.
		$image_id = $product->get_image_id();
		if ( $image_id ) {
			$data['image'] = wp_get_attachment_url( $image_id );
		}

		// Brand.
		$brands = wp_get_post_terms( $product->get_id(), 'pa_brand', array( 'fields' => 'names' ) );
		if ( ! is_wp_error( $brands ) && ! empty( $brands ) ) {
			$data['brand'] = $brands[0];
		} else {
			$brand_meta = $product->get_meta( '_brand' );
			if ( $brand_meta ) {
				$data['brand'] = $brand_meta;
			}
		}

		// GTIN.
		$gtin = $product->get_meta( '_gtin' );
		if ( $gtin ) {
			$data['gtin'] = $gtin;
		}

		// Categories.
		$cats = wp_get_post_terms( $product->get_id(), 'product_cat', array( 'fields' => 'names' ) );
		if ( ! is_wp_error( $cats ) && ! empty( $cats ) ) {
			$data['categories'] = $cats;
		}

		// Attributes.
		$attributes = $product->get_attributes();
		if ( ! empty( $attributes ) ) {
			$attrs = array();
			foreach ( $attributes as $attr ) {
				if ( ! is_a( $attr, 'WC_Product_Attribute' ) ) {
					continue;
				}
				$label   = wc_attribute_label( $attr->get_name() );
				$options = $attr->is_taxonomy()
					? wp_get_post_terms( $product->get_id(), $attr->get_name(), array( 'fields' => 'names' ) )
					: $attr->get_options();
				if ( ! is_wp_error( $options ) && ! empty( $options ) ) {
					$attrs[ $label ] = $options;
				}
			}
			if ( ! empty( $attrs ) ) {
				$data['attributes'] = $attrs;
			}
		}

		// Rating.
		$rating = $product->get_average_rating();
		if ( $rating && $rating > 0 ) {
			$data['rating']       = (float) $rating;
			$data['review_count'] = $product->get_review_count();
		}

		// API link for detail.
		$data['api_url'] = rest_url( 'ai-shopping/v1/products/' . $product->get_id() );

		return $data;
	}
}
