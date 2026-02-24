<?php
/**
 * Products REST API endpoint.
 *
 * @package AIShopping\Api
 */

namespace AIShopping\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Handles product discovery: search, detail, variations, categories, tags, attributes, reviews.
 */
class Products extends REST_Controller {

	/**
	 * Register routes.
	 */
	public function register_routes() {
		$ns = $this->namespace;

		register_rest_route(
			$ns,
			'/products',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_products' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
			)
		);

		register_rest_route(
			$ns,
			'/products/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_product' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
			)
		);

		register_rest_route(
			$ns,
			'/products/(?P<id>\d+)/variations',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_variations' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
			)
		);

		register_rest_route(
			$ns,
			'/categories',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_categories' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
			)
		);

		register_rest_route(
			$ns,
			'/tags',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_tags' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
			)
		);

		register_rest_route(
			$ns,
			'/attributes',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_attributes' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
			)
		);

		register_rest_route(
			$ns,
			'/attributes/(?P<id>\d+)/terms',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_attribute_terms' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
			)
		);

		register_rest_route(
			$ns,
			'/reviews',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_reviews' ),
				'permission_callback' => array( $this, 'check_read_permission' ),
			)
		);
	}

	/**
	 * List products with filtering.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function list_products( $request ) {
		$pagination = $this->get_pagination( $request );

		$args = array(
			'status'   => 'publish',
			'limit'    => $pagination['per_page'],
			'page'     => $pagination['page'],
			'return'   => 'objects',
			'paginate' => true,
		);

		// Search.
		$search = $request->get_param( 'search' );
		if ( $search ) {
			$args['s'] = sanitize_text_field( $search );
		}

		// Category filter.
		$category = $request->get_param( 'category' );
		if ( $category ) {
			$args['category'] = array( sanitize_text_field( $category ) );
		}

		// Tag filter.
		$tag = $request->get_param( 'tag' );
		if ( $tag ) {
			$args['tag'] = array( sanitize_text_field( $tag ) );
		}

		// Price range.
		$min_price = $request->get_param( 'min_price' );
		$max_price = $request->get_param( 'max_price' );
		if ( $min_price !== null || $max_price !== null ) {
			$args['min_price'] = $min_price !== null ? (float) $min_price : '';
			$args['max_price'] = $max_price !== null ? (float) $max_price : '';
		}

		// Stock status.
		$stock_status = $request->get_param( 'stock_status' );
		if ( $stock_status ) {
			$args['stock_status'] = sanitize_text_field( $stock_status );
		}

		// On sale.
		if ( $request->get_param( 'on_sale' ) === 'true' ) {
			$args['include'] = wc_get_product_ids_on_sale();
			if ( empty( $args['include'] ) ) {
				return $this->success(
					array(
						'products'   => array(),
						'pagination' => array(
							'page'        => $pagination['page'],
							'per_page'    => $pagination['per_page'],
							'total'       => 0,
							'total_pages' => 0,
						),
					),
					$request
				);
			}
		}

		// Featured.
		if ( $request->get_param( 'featured' ) === 'true' ) {
			$args['featured'] = true;
		}

		// Sorting.
		$orderby = $request->get_param( 'orderby' );
		$order   = $request->get_param( 'order' );
		if ( $orderby ) {
			$valid = array( 'date', 'price', 'popularity', 'rating', 'title', 'id' );
			if ( in_array( $orderby, $valid, true ) ) {
				$args['orderby'] = $orderby;
			}
		}
		if ( $order ) {
			$args['order'] = strtoupper( $order ) === 'ASC' ? 'ASC' : 'DESC';
		}

		// Type filter.
		$type = $request->get_param( 'type' );
		if ( $type ) {
			$args['type'] = sanitize_text_field( $type );
		}

		$results  = wc_get_products( $args );
		$products = array();

		foreach ( $results->products as $product ) {
			$products[] = $this->format_product_summary( $product );
		}

		return $this->success(
			array(
				'products'   => $products,
				'pagination' => array(
					'page'        => $pagination['page'],
					'per_page'    => $pagination['per_page'],
					'total'       => $results->total,
					'total_pages' => $results->max_num_pages,
				),
			),
			$request
		);
	}

	/**
	 * Get a single product with full detail.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function get_product( $request ) {
		$product = wc_get_product( (int) $request['id'] );

		if ( ! $product || 'publish' !== $product->get_status() ) {
			return $this->error_response( 'product_not_found', __( 'Product not found.', 'ai-shopping' ), 404, $request );
		}

		$data = $this->format_product_detail( $product );

		return $this->success( $data, $request );
	}

	/**
	 * Get variations for a variable product.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function get_variations( $request ) {
		$product = wc_get_product( (int) $request['id'] );

		if ( ! $product || ! $product->is_type( 'variable' ) ) {
			return $this->error_response(
				'invalid_product',
				__( 'Product not found or is not a variable product.', 'ai-shopping' ),
				404,
				$request
			);
		}

		$variations = array();
		foreach ( $product->get_children() as $variation_id ) {
			$variation = wc_get_product( $variation_id );
			if ( $variation ) {
				$variations[] = $this->format_variation( $variation );
			}
		}

		return $this->success( array( 'variations' => $variations ), $request );
	}

	/**
	 * List product categories with hierarchy.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function list_categories( $request ) {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => $request->get_param( 'hide_empty' ) !== 'false',
				'orderby'    => 'name',
			)
		);

		$categories = array();
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$categories[] = array(
					'id'          => $term->term_id,
					'name'        => $term->name,
					'slug'        => $term->slug,
					'parent'      => $term->parent,
					'description' => $term->description,
					'count'       => $term->count,
					'image'       => $this->get_term_image( $term->term_id ),
				);
			}
		}

		return $this->success( array( 'categories' => $categories ), $request );
	}

	/**
	 * List product tags.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function list_tags( $request ) {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_tag',
				'hide_empty' => true,
				'orderby'    => 'name',
			)
		);

		$tags = array();
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$tags[] = array(
					'id'    => $term->term_id,
					'name'  => $term->name,
					'slug'  => $term->slug,
					'count' => $term->count,
				);
			}
		}

		return $this->success( array( 'tags' => $tags ), $request );
	}

	/**
	 * List product attributes.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function list_attributes( $request ) {
		$taxonomies = wc_get_attribute_taxonomies();
		$attributes = array();

		foreach ( $taxonomies as $tax ) {
			$attributes[] = array(
				'id'       => (int) $tax->attribute_id,
				'name'     => $tax->attribute_label,
				'slug'     => $tax->attribute_name,
				'type'     => $tax->attribute_type,
				'order_by' => $tax->attribute_orderby,
			);
		}

		return $this->success( array( 'attributes' => $attributes ), $request );
	}

	/**
	 * List terms for an attribute.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function list_attribute_terms( $request ) {
		$attribute = wc_get_attribute( (int) $request['id'] );
		if ( ! $attribute ) {
			return $this->error_response( 'attribute_not_found', __( 'Attribute not found.', 'ai-shopping' ), 404, $request );
		}

		$taxonomy = $attribute->slug;
		$terms    = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
			)
		);

		$result = array();
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$result[] = array(
					'id'    => $term->term_id,
					'name'  => $term->name,
					'slug'  => $term->slug,
					'count' => $term->count,
				);
			}
		}

		return $this->success( array( 'terms' => $result ), $request );
	}

	/**
	 * List product reviews.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	public function list_reviews( $request ) {
		$product_id = $request->get_param( 'product_id' );
		$pagination = $this->get_pagination( $request );

		$args = array(
			'post_type' => 'product',
			'status'    => 'approve',
			'number'    => $pagination['per_page'],
			'offset'    => ( $pagination['page'] - 1 ) * $pagination['per_page'],
		);

		if ( $product_id ) {
			$args['post_id'] = (int) $product_id;
		}

		$rating = $request->get_param( 'rating' );
		if ( $rating ) {
			$args['meta_query'] = array(
				array(
					'key'   => 'rating',
					'value' => (int) $rating,
				),
			);
		}

		$comments = get_comments( $args );
		$reviews  = array();

		foreach ( $comments as $comment ) {
			$reviews[] = array(
				'id'         => $comment->comment_ID,
				'product_id' => (int) $comment->comment_post_ID,
				'reviewer'   => $comment->comment_author,
				'rating'     => (int) get_comment_meta( $comment->comment_ID, 'rating', true ),
				'review'     => $comment->comment_content,
				'date'       => $comment->comment_date_gmt,
				'verified'   => (bool) get_comment_meta( $comment->comment_ID, 'verified', true ),
			);
		}

		return $this->success( array( 'reviews' => $reviews ), $request );
	}

	/**
	 * Format a product for list view (summary).
	 *
	 * @param \WC_Product $product The product.
	 * @return array
	 */
	private function format_product_summary( $product ) {
		$data = array(
			'id'             => $product->get_id(),
			'name'           => $product->get_name(),
			'slug'           => $product->get_slug(),
			'type'           => $product->get_type(),
			'status'         => $product->get_status(),
			'sku'            => $product->get_sku(),
			'price'          => $product->get_price(),
			'regular_price'  => $product->get_regular_price(),
			'sale_price'     => $product->get_sale_price(),
			'on_sale'        => $product->is_on_sale(),
			'stock_status'   => $product->get_stock_status(),
			'stock_quantity' => $product->get_stock_quantity(),
			'featured'       => $product->is_featured(),
			'short_description' => wp_strip_all_tags( $product->get_short_description() ),
			'categories'     => $this->get_product_terms( $product, 'product_cat' ),
			'images'         => $this->get_product_images( $product ),
			'permalink'      => $product->get_permalink(),
			'average_rating' => $product->get_average_rating(),
			'review_count'   => $product->get_review_count(),
		);

		/**
		 * Filter product summary data.
		 *
		 * @param array       $data    Product summary data.
		 * @param \WC_Product $product The product.
		 */
		return apply_filters( 'ais_product_summary', $data, $product );
	}

	/**
	 * Format a product for detail view (full data).
	 *
	 * @param \WC_Product $product The product.
	 * @return array
	 */
	private function format_product_detail( $product ) {
		$data = $this->format_product_summary( $product );

		$data['description']      = wp_strip_all_tags( $product->get_description() );
		$data['weight']           = $product->get_weight();
		$data['dimensions']       = array(
			'length' => $product->get_length(),
			'width'  => $product->get_width(),
			'height' => $product->get_height(),
		);
		$data['attributes']       = $this->get_product_attributes( $product );
		$data['default_attributes'] = $product->get_default_attributes();
		$data['tags']             = $this->get_product_terms( $product, 'product_tag' );
		$data['manage_stock']     = $product->get_manage_stock();
		$data['backorders']       = $product->get_backorders();
		$data['sold_individually'] = $product->get_sold_individually();
		$data['purchase_note']    = wp_strip_all_tags( $product->get_purchase_note() );
		$data['tax_status']       = $product->get_tax_status();
		$data['tax_class']        = $product->get_tax_class();
		$data['shipping_class']   = $product->get_shipping_class();
		$data['virtual']          = $product->is_virtual();
		$data['downloadable']     = $product->is_downloadable();

		// Related, upsells, cross-sells.
		$data['upsell_ids']       = $product->get_upsell_ids();
		$data['cross_sell_ids']   = $product->get_cross_sell_ids();
		$data['related_ids']      = wc_get_related_products( $product->get_id(), 5 );

		// Variations for variable products.
		if ( $product->is_type( 'variable' ) ) {
			$data['variations'] = array();
			foreach ( $product->get_children() as $variation_id ) {
				$variation = wc_get_product( $variation_id );
				if ( $variation ) {
					$data['variations'][] = $this->format_variation( $variation );
				}
			}
		}

		// Sale schedule.
		$data['date_on_sale_from'] = $product->get_date_on_sale_from() ? $product->get_date_on_sale_from()->date( 'c' ) : null;
		$data['date_on_sale_to']   = $product->get_date_on_sale_to() ? $product->get_date_on_sale_to()->date( 'c' ) : null;

		/**
		 * Filter product detail data.
		 *
		 * @param array       $data    Product detail data.
		 * @param \WC_Product $product The product.
		 */
		return apply_filters( 'ais_product_detail', $data, $product );
	}

	/**
	 * Format a product variation.
	 *
	 * @param \WC_Product_Variation $variation The variation.
	 * @return array
	 */
	private function format_variation( $variation ) {
		return array(
			'id'             => $variation->get_id(),
			'sku'            => $variation->get_sku(),
			'price'          => $variation->get_price(),
			'regular_price'  => $variation->get_regular_price(),
			'sale_price'     => $variation->get_sale_price(),
			'on_sale'        => $variation->is_on_sale(),
			'stock_status'   => $variation->get_stock_status(),
			'stock_quantity' => $variation->get_stock_quantity(),
			'attributes'     => $variation->get_attributes(),
			'image'          => $this->get_image_data( $variation->get_image_id() ),
			'weight'         => $variation->get_weight(),
			'dimensions'     => array(
				'length' => $variation->get_length(),
				'width'  => $variation->get_width(),
				'height' => $variation->get_height(),
			),
			'description'    => wp_strip_all_tags( $variation->get_description() ),
			'purchasable'    => $variation->is_purchasable(),
		);
	}

	/**
	 * Get product taxonomy terms.
	 *
	 * @param \WC_Product $product  The product.
	 * @param string      $taxonomy Taxonomy name.
	 * @return array
	 */
	private function get_product_terms( $product, $taxonomy ) {
		$terms  = get_the_terms( $product->get_id(), $taxonomy );
		$result = array();

		if ( $terms && ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$result[] = array(
					'id'   => $term->term_id,
					'name' => $term->name,
					'slug' => $term->slug,
				);
			}
		}

		return $result;
	}

	/**
	 * Get product images.
	 *
	 * @param \WC_Product $product The product.
	 * @return array
	 */
	private function get_product_images( $product ) {
		$images = array();

		// Featured image.
		$thumb_id = $product->get_image_id();
		if ( $thumb_id ) {
			$images[] = $this->get_image_data( $thumb_id );
		}

		// Gallery images.
		foreach ( $product->get_gallery_image_ids() as $image_id ) {
			$images[] = $this->get_image_data( $image_id );
		}

		return $images;
	}

	/**
	 * Get image data by attachment ID.
	 *
	 * @param int $image_id Attachment ID.
	 * @return array|null
	 */
	private function get_image_data( $image_id ) {
		if ( ! $image_id ) {
			return null;
		}

		$src = wp_get_attachment_image_src( $image_id, 'full' );
		if ( ! $src ) {
			return null;
		}

		return array(
			'id'     => $image_id,
			'src'    => $src[0],
			'width'  => $src[1],
			'height' => $src[2],
			'alt'    => get_post_meta( $image_id, '_wp_attachment_image_alt', true ),
		);
	}

	/**
	 * Get product attributes.
	 *
	 * @param \WC_Product $product The product.
	 * @return array
	 */
	private function get_product_attributes( $product ) {
		$attributes = array();

		foreach ( $product->get_attributes() as $attr ) {
			if ( is_a( $attr, 'WC_Product_Attribute' ) ) {
				$attributes[] = array(
					'name'      => wc_attribute_label( $attr->get_name() ),
					'slug'      => $attr->get_name(),
					'options'   => $attr->is_taxonomy() ? $this->get_attribute_taxonomy_options( $attr ) : $attr->get_options(),
					'visible'   => $attr->get_visible(),
					'variation' => $attr->get_variation(),
				);
			}
		}

		return $attributes;
	}

	/**
	 * Get taxonomy-based attribute options.
	 *
	 * @param \WC_Product_Attribute $attribute The attribute.
	 * @return array
	 */
	private function get_attribute_taxonomy_options( $attribute ) {
		$options = array();

		foreach ( $attribute->get_terms() as $term ) {
			$options[] = $term->name;
		}

		return $options;
	}

	/**
	 * Get category thumbnail image.
	 *
	 * @param int $term_id Category term ID.
	 * @return array|null
	 */
	private function get_term_image( $term_id ) {
		$image_id = get_term_meta( $term_id, 'thumbnail_id', true );
		return $this->get_image_data( $image_id );
	}
}
