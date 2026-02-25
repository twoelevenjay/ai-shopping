<?php
/**
 * Schema.org JSON-LD enhancement for WooCommerce product pages.
 *
 * Hooks into WooCommerce's structured data to add richer product metadata
 * that AI agents and search engines can parse.
 *
 * @package AIShopping\Discovery
 */

namespace AIShopping\Discovery;

defined( 'ABSPATH' ) || exit;

/**
 * Enhances WooCommerce JSON-LD with additional Schema.org properties.
 */
class Schema_Enhancer {

	/**
	 * Constructor — register hooks.
	 */
	public function __construct() {
		add_filter( 'woocommerce_structured_data_product', array( $this, 'enhance_product_schema' ), 20, 2 );
		add_action( 'wp_footer', array( $this, 'render_website_schema' ), 99 );
	}

	/**
	 * Enhance WooCommerce product JSON-LD.
	 *
	 * @param array       $markup  The existing structured data.
	 * @param \WC_Product $product The product object.
	 * @return array
	 */
	public function enhance_product_schema( $markup, $product ) {
		// Brand — from pa_brand attribute, _brand meta, or fall back to store name.
		$brand = $this->get_product_brand( $product );
		if ( $brand ) {
			$markup['brand'] = array(
				'@type' => 'Brand',
				'name'  => $brand,
			);
		}

		// GTIN / MPN from meta.
		$gtin = $product->get_meta( '_gtin' );
		if ( $gtin ) {
			$markup['gtin'] = $gtin;
		}

		$mpn = $product->get_meta( '_mpn' );
		if ( $mpn ) {
			$markup['mpn'] = $mpn;
		}

		// Weight.
		$weight = $product->get_weight();
		if ( $weight ) {
			$unit_map = array(
				'kg'  => 'KGM',
				'g'   => 'GRM',
				'lbs' => 'LBR',
				'oz'  => 'ONZ',
			);
			$wc_unit  = get_option( 'woocommerce_weight_unit', 'kg' );
			$un_code  = isset( $unit_map[ $wc_unit ] ) ? $unit_map[ $wc_unit ] : $wc_unit;

			$markup['weight'] = array(
				'@type'    => 'QuantitativeValue',
				'value'    => (float) $weight,
				'unitCode' => $un_code,
			);
		}

		// Dimensions.
		$length = $product->get_length();
		$width  = $product->get_width();
		$height = $product->get_height();

		if ( $length || $width || $height ) {
			$dim_unit_map = array(
				'cm' => 'CMT',
				'm'  => 'MTR',
				'mm' => 'MMT',
				'in' => 'INH',
				'yd' => 'YRD',
			);
			$wc_dim_unit  = get_option( 'woocommerce_dimension_unit', 'cm' );
			$dim_code     = isset( $dim_unit_map[ $wc_dim_unit ] ) ? $dim_unit_map[ $wc_dim_unit ] : $wc_dim_unit;

			if ( $length ) {
				$markup['depth'] = array(
					'@type'    => 'QuantitativeValue',
					'value'    => (float) $length,
					'unitCode' => $dim_code,
				);
			}
			if ( $width ) {
				$markup['width'] = array(
					'@type'    => 'QuantitativeValue',
					'value'    => (float) $width,
					'unitCode' => $dim_code,
				);
			}
			if ( $height ) {
				$markup['height'] = array(
					'@type'    => 'QuantitativeValue',
					'value'    => (float) $height,
					'unitCode' => $dim_code,
				);
			}
		}

		// Product attributes as additionalProperty.
		$additional = array();
		$attributes = $product->get_attributes();
		foreach ( $attributes as $attr ) {
			if ( ! is_a( $attr, 'WC_Product_Attribute' ) ) {
				continue;
			}
			// Skip brand — already handled above.
			if ( 'pa_brand' === $attr->get_name() ) {
				continue;
			}

			$label   = wc_attribute_label( $attr->get_name() );
			$options = $attr->is_taxonomy()
				? wp_get_post_terms( $product->get_id(), $attr->get_name(), array( 'fields' => 'names' ) )
				: $attr->get_options();

			if ( ! is_wp_error( $options ) && ! empty( $options ) ) {
				$additional[] = array(
					'@type'    => 'PropertyValue',
					'name'     => $label,
					'value'    => implode( ', ', $options ),
				);
			}
		}
		if ( ! empty( $additional ) ) {
			$markup['additionalProperty'] = $additional;
		}

		// Enhance offers.
		if ( isset( $markup['offers'] ) && is_array( $markup['offers'] ) ) {
			$offers = &$markup['offers'];

			// Handle single offer or array of offers.
			$offer_list = isset( $offers['@type'] ) ? array( &$offers ) : array();
			if ( ! isset( $offers['@type'] ) && isset( $offers[0] ) ) {
				foreach ( $offers as &$o ) {
					$offer_list[] = &$o;
				}
				unset( $o );
			}

			foreach ( $offer_list as &$offer ) {
				// Availability mapping.
				$stock_status = $product->get_stock_status();
				$availability_map = array(
					'instock'     => 'https://schema.org/InStock',
					'outofstock'  => 'https://schema.org/OutOfStock',
					'onbackorder' => 'https://schema.org/PreOrder',
				);
				if ( isset( $availability_map[ $stock_status ] ) ) {
					$offer['availability'] = $availability_map[ $stock_status ];
				}

				// Price valid until (sale end date).
				$sale_to = $product->get_date_on_sale_to();
				if ( $sale_to ) {
					$offer['priceValidUntil'] = $sale_to->date( 'Y-m-d' );
				}

				// Shipping details from WooCommerce shipping zones.
				$shipping = $this->get_shipping_details( $product );
				if ( ! empty( $shipping ) ) {
					$offer['shippingDetails'] = $shipping;
				}

				// Return policy.
				$return_policy = $this->get_return_policy();
				if ( $return_policy ) {
					$offer['hasMerchantReturnPolicy'] = $return_policy;
				}
			}
			unset( $offer );
		}

		// Individual reviews (up to 5).
		$reviews = $this->get_product_reviews( $product, 5 );
		if ( ! empty( $reviews ) ) {
			$markup['review'] = $reviews;
		}

		// Related products via isRelatedTo.
		$related_ids = array_merge(
			$product->get_upsell_ids(),
			$product->get_cross_sell_ids()
		);
		$related_ids = array_unique( array_filter( $related_ids ) );
		if ( ! empty( $related_ids ) ) {
			$related = array();
			foreach ( array_slice( $related_ids, 0, 5 ) as $rid ) {
				$rp = wc_get_product( $rid );
				if ( $rp ) {
					$related[] = array(
						'@type' => 'Product',
						'name'  => $rp->get_name(),
						'url'   => $rp->get_permalink(),
					);
				}
			}
			if ( ! empty( $related ) ) {
				$markup['isRelatedTo'] = $related;
			}
		}

		return $markup;
	}

	/**
	 * Render WebSite schema with SearchAction on front/shop pages.
	 */
	public function render_website_schema() {
		if ( ! is_front_page() && ! is_shop() ) {
			return;
		}

		$schema = array(
			'@context'      => 'https://schema.org',
			'@type'         => 'WebSite',
			'name'          => get_bloginfo( 'name' ),
			'url'           => home_url(),
			'potentialAction' => array(
				'@type'       => 'SearchAction',
				'target'      => array(
					'@type'        => 'EntryPoint',
					'urlTemplate'  => home_url( '/?s={search_term_string}&post_type=product' ),
				),
				'query-input' => 'required name=search_term_string',
			),
		);

		echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES ) . '</script>' . "\n";
	}

	/**
	 * Get the product brand.
	 *
	 * @param \WC_Product $product The product.
	 * @return string|null
	 */
	private function get_product_brand( $product ) {
		// Check pa_brand taxonomy.
		$brands = wp_get_post_terms( $product->get_id(), 'pa_brand', array( 'fields' => 'names' ) );
		if ( ! is_wp_error( $brands ) && ! empty( $brands ) ) {
			return $brands[0];
		}

		// Check _brand meta.
		$brand_meta = $product->get_meta( '_brand' );
		if ( $brand_meta ) {
			return $brand_meta;
		}

		// Fallback to store name.
		return get_bloginfo( 'name' );
	}

	/**
	 * Get shipping details for Schema.org OfferShippingDetails.
	 *
	 * @param \WC_Product $product The product.
	 * @return array|null
	 */
	private function get_shipping_details( $product ) {
		if ( $product->is_virtual() ) {
			return null;
		}

		$zones = \WC_Shipping_Zones::get_zones();
		if ( empty( $zones ) ) {
			return null;
		}

		// Use the first zone with active methods as a representative.
		foreach ( $zones as $zone_data ) {
			$zone    = new \WC_Shipping_Zone( $zone_data['id'] );
			$methods = $zone->get_shipping_methods( true );

			if ( empty( $methods ) ) {
				continue;
			}

			$method     = reset( $methods );
			$country    = WC()->countries->get_base_country();

			return array(
				'@type'             => 'OfferShippingDetails',
				'shippingDestination' => array(
					'@type'          => 'DefinedRegion',
					'addressCountry' => $country,
				),
				'shippingLabel'     => $method->get_title(),
			);
		}

		return null;
	}

	/**
	 * Get merchant return policy for Schema.org.
	 *
	 * @return array|null
	 */
	private function get_return_policy() {
		// Check if a refund/return policy page is set.
		$refund_page_id = wc_get_page_id( 'refund_returns' );
		if ( $refund_page_id <= 0 ) {
			// Also check for a page with 'refund' or 'return' in the slug.
			$page = get_page_by_path( 'refund-and-returns-policy' );
			if ( ! $page ) {
				$page = get_page_by_path( 'return-policy' );
			}
			if ( ! $page ) {
				$page = get_page_by_path( 'refund-policy' );
			}
			if ( $page ) {
				$refund_page_id = $page->ID;
			}
		}

		if ( $refund_page_id <= 0 ) {
			return null;
		}

		return array(
			'@type'             => 'MerchantReturnPolicy',
			'applicableCountry' => WC()->countries->get_base_country(),
			'returnPolicyCategory' => 'https://schema.org/MerchantReturnFiniteReturnWindow',
			'merchantReturnLink'   => get_permalink( $refund_page_id ),
		);
	}

	/**
	 * Get individual product reviews for Schema.org.
	 *
	 * @param \WC_Product $product The product.
	 * @param int         $limit   Max reviews.
	 * @return array
	 */
	private function get_product_reviews( $product, $limit = 5 ) {
		$comments = get_comments(
			array(
				'post_id'  => $product->get_id(),
				'status'   => 'approve',
				'type'     => 'review',
				'number'   => $limit,
				'orderby'  => 'comment_date_gmt',
				'order'    => 'DESC',
			)
		);

		$reviews = array();
		foreach ( $comments as $comment ) {
			$rating = get_comment_meta( $comment->comment_ID, 'rating', true );
			if ( ! $rating ) {
				continue;
			}

			$review = array(
				'@type'         => 'Review',
				'author'        => array(
					'@type' => 'Person',
					'name'  => $comment->comment_author,
				),
				'datePublished' => gmdate( 'Y-m-d', strtotime( $comment->comment_date_gmt ) ),
				'reviewBody'    => wp_strip_all_tags( $comment->comment_content ),
				'reviewRating'  => array(
					'@type'       => 'Rating',
					'ratingValue' => (int) $rating,
					'bestRating'  => 5,
				),
			);

			$reviews[] = $review;
		}

		return $reviews;
	}
}
