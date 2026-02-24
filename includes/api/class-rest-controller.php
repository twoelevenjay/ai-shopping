<?php
/**
 * Base REST controller with authentication, rate limiting, and error formatting.
 *
 * @package AIShopping\Api
 */

namespace AIShopping\Api;

defined( 'ABSPATH' ) || exit;

use AIShopping\Security\Auth;
use AIShopping\Security\Rate_Limiter;

/**
 * Abstract base for all AI Shopping REST controllers.
 */
abstract class REST_Controller {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'ai-shopping/v1';

	/**
	 * Register routes (implemented by each controller).
	 */
	abstract public function register_routes();

	/**
	 * Standard permission check for read endpoints.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return true|\WP_Error
	 */
	public function check_read_permission( $request ) {
		return $this->check_permission( $request, 'read' );
	}

	/**
	 * Standard permission check for write endpoints.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return true|\WP_Error
	 */
	public function check_write_permission( $request ) {
		return $this->check_permission( $request, 'write' );
	}

	/**
	 * Core permission and rate limit check.
	 *
	 * @param \WP_REST_Request $request   The request.
	 * @param string           $operation 'read' or 'write'.
	 * @return true|\WP_Error
	 */
	protected function check_permission( $request, $operation = 'read' ) {
		// Allow HTTP in local dev if configured.
		if ( 'yes' !== get_option( 'ais_allow_http', 'yes' ) && ! is_ssl() ) {
			return $this->error( 'https_required', __( 'HTTPS is required for API access.', 'ai-shopping' ), 403 );
		}

		$key_row = Auth::validate_request( $request );
		if ( ! $key_row ) {
			return $this->error(
				'unauthorized',
				__( 'Invalid or missing API key. Include a valid Bearer token in the Authorization header.', 'ai-shopping' ),
				401
			);
		}

		if ( ! Auth::has_permission( $key_row, $operation ) ) {
			return $this->error(
				'forbidden',
				sprintf(
					/* translators: %s: required permission level */
					__( 'This endpoint requires "%s" permission. Your API key has "%s" permission.', 'ai-shopping' ),
					$operation,
					$key_row['permissions']
				),
				403
			);
		}

		// Rate limit check.
		$rate = Rate_Limiter::check( $key_row, $operation );
		if ( ! $rate['allowed'] ) {
			$response = $this->error(
				'rate_limit_exceeded',
				__( 'Rate limit exceeded. Please wait and try again.', 'ai-shopping' ),
				429
			);
			$response->header( 'X-RateLimit-Limit', $rate['limit'] );
			$response->header( 'X-RateLimit-Remaining', 0 );
			$response->header( 'X-RateLimit-Reset', $rate['reset'] );
			$response->header( 'Retry-After', max( 1, $rate['reset'] - time() ) );
			return $response;
		}

		// Store key data and rate info on request for downstream use.
		$request->set_param( '_ais_key', $key_row );
		$request->set_param( '_ais_rate', $rate );

		return true;
	}

	/**
	 * Build a success response.
	 *
	 * @param mixed            $data    Response data.
	 * @param \WP_REST_Request $request The request (for rate limit headers).
	 * @param int              $status  HTTP status code.
	 * @return \WP_REST_Response
	 */
	protected function success( $data, $request = null, $status = 200 ) {
		$envelope = array(
			'success' => true,
			'data'    => $data,
			'meta'    => $this->get_meta(),
		);

		$response = new \WP_REST_Response( $envelope, $status );
		$this->add_rate_headers( $response, $request );
		return $response;
	}

	/**
	 * Build an error WP_Error.
	 *
	 * @param string $code    Error code.
	 * @param string $message Human-readable message.
	 * @param int    $status  HTTP status code.
	 * @return \WP_Error
	 */
	protected function error( $code, $message, $status = 400 ) {
		return new \WP_Error(
			$code,
			$message,
			array( 'status' => $status )
		);
	}

	/**
	 * Build a formatted error response (for direct return).
	 *
	 * @param string           $code    Error code.
	 * @param string           $message Human-readable message.
	 * @param int              $status  HTTP status.
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response
	 */
	protected function error_response( $code, $message, $status = 400, $request = null ) {
		$envelope = array(
			'success' => false,
			'error'   => array(
				'code'    => $code,
				'message' => $message,
				'status'  => $status,
			),
		);

		$response = new \WP_REST_Response( $envelope, $status );
		$this->add_rate_headers( $response, $request );
		return $response;
	}

	/**
	 * Get standard response meta.
	 *
	 * @param string $protocol Override protocol identifier.
	 * @return array
	 */
	protected function get_meta( $protocol = 'rest' ) {
		return array(
			'protocol'  => $protocol,
			'version'   => AIS_VERSION,
			'store'     => get_bloginfo( 'name' ),
			'currency'  => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD',
			'timestamp' => gmdate( 'c' ),
		);
	}

	/**
	 * Add rate limit headers to response.
	 *
	 * @param \WP_REST_Response     $response The response.
	 * @param \WP_REST_Request|null $request  The request.
	 */
	protected function add_rate_headers( $response, $request = null ) {
		if ( ! $request ) {
			return;
		}

		$rate = $request->get_param( '_ais_rate' );
		if ( $rate && ! empty( $rate['limit'] ) ) {
			$response->header( 'X-RateLimit-Limit', $rate['limit'] );
			$response->header( 'X-RateLimit-Remaining', $rate['remaining'] );
			$response->header( 'X-RateLimit-Reset', $rate['reset'] );
		}
	}

	/**
	 * Parse pagination params from request.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return array{page: int, per_page: int}
	 */
	protected function get_pagination( $request ) {
		return array(
			'page'     => max( 1, (int) $request->get_param( 'page' ) ?: 1 ),
			'per_page' => min( 100, max( 1, (int) $request->get_param( 'per_page' ) ?: 20 ) ),
		);
	}
}
