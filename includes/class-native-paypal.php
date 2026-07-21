<?php

namespace DxmPpcpBridge;

defined( 'ABSPATH' ) || exit;

final class Native_PayPal {
	const CREATE_FUNCTION = 'WooCommerce\\PayPalCommerce\\Api\\ppcp_create_order_tracking';

	/** @var array<string,mixed>|null */
	private $send_context = null;

	/** @var array<string,string>|null */
	private $carrier_cache = null;

	/**
	 * @return bool
	 */
	public function is_available() {
		return function_exists( self::CREATE_FUNCTION );
	}

	/**
	 * @return string
	 */
	public function plugin_version() {
		$version = get_option( 'woocommerce-ppcp-version', '' );
		return is_scalar( $version ) ? trim( (string) $version ) : '';
	}

	/**
	 * A PayPal Payments order is identified by native order metadata, not by
	 * whether the buyer logged in to a PayPal account.
	 *
	 * @param \WC_Order $order WooCommerce order.
	 * @return bool
	 */
	public function is_native_paypal_order( $order ) {
		return $order instanceof \WC_Order
			&& '' !== trim( (string) $order->get_meta( '_ppcp_paypal_order_id', true, 'edit' ) )
			&& '' !== trim( (string) $order->get_transaction_id() );
	}

	/**
	 * @param \WC_Order $order WooCommerce order.
	 * @return \WP_Error|true
	 */
	public function validate_order( $order ) {
		if ( ! $this->is_available() ) {
			return new \WP_Error( 'dxm_ppcp_native_api_missing', 'WooCommerce PayPal Payments native tracking API is unavailable.' );
		}

		if ( ! $order instanceof \WC_Order ) {
			return new \WP_Error( 'dxm_ppcp_invalid_order', 'WooCommerce order was not found.' );
		}

		if ( '' === trim( (string) $order->get_meta( '_ppcp_paypal_order_id', true, 'edit' ) ) ) {
			return new \WP_Error( 'dxm_ppcp_missing_order_id', 'This order has no WooCommerce PayPal Payments order ID.' );
		}

		if ( '' === trim( (string) $order->get_transaction_id() ) ) {
			return new \WP_Error( 'dxm_ppcp_missing_capture_id', 'This order has no PayPal capture/transaction ID.' );
		}

		$captured = $order->get_meta( '_ppcp_paypal_captured', true, 'edit' );
		if ( '' !== $captured && function_exists( 'wc_string_to_bool' ) && ! wc_string_to_bool( $captured ) ) {
			return new \WP_Error( 'dxm_ppcp_not_captured', 'The PayPal payment is authorized but has not been captured yet.' );
		}

		return true;
	}

	/**
	 * Submit through the official WooCommerce PayPal Payments function. Temporary
	 * official filters preserve a recognized carrier code when one is available.
	 *
	 * @param \WC_Order          $order    WooCommerce order.
	 * @param array<string,mixed> $shipment Shipment data.
	 * @return array<string,mixed>
	 */
	public function add_tracking( $order, array $shipment ) {
		$valid = $this->validate_order( $order );
		if ( is_wp_error( $valid ) ) {
			return array(
				'success'   => false,
				'message'   => $valid->get_error_message(),
				'code'      => $valid->get_error_code(),
				'retryable' => in_array( $valid->get_error_code(), array( 'dxm_ppcp_missing_order_id', 'dxm_ppcp_missing_capture_id', 'dxm_ppcp_not_captured' ), true ),
			);
		}

		$tracking_number = trim( (string) ( $shipment['tracking_number'] ?? '' ) );
		$provider        = trim( (string) ( $shipment['provider'] ?? '' ) );
		$provider_name   = trim( (string) ( $shipment['provider_name'] ?? '' ) );
		$carrier         = $this->resolve_carrier( $provider, $provider_name );

		if ( '' === $tracking_number ) {
			return array(
				'success'   => false,
				'message'   => 'Tracking number is empty.',
				'code'      => 'empty_tracking_number',
				'retryable' => false,
			);
		}

		// PayPal Payments 4.1.1 indexes this value as an array while building
		// shipment line items. Normalize a missing/scalar value to avoid its
		// first-sync "Illegal string offset" warning.
		$native_tracking_meta = $order->get_meta( '_ppcp_paypal_tracking_info_meta_name', true, 'edit' );
		if ( ! is_array( $native_tracking_meta ) ) {
			$order->update_meta_data( '_ppcp_paypal_tracking_info_meta_name', array() );
			$order->save_meta_data();
		}

		$this->send_context = array(
			'order_id'     => $order->get_id(),
			'carrier_code' => $carrier['code'],
			'carrier_name' => $carrier['name'],
		);

		add_filter( 'woocommerce_paypal_payments_tracking_data_before_sending', array( $this, 'filter_create_data' ), 20, 2 );
		add_filter( 'woocommerce_paypal_payments_tracking_data_before_update', array( $this, 'filter_update_data' ), 20, 2 );

		try {
			$callback = self::CREATE_FUNCTION;
			$callback( $order, $tracking_number, $carrier['name'], 'SHIPPED' );

			return array(
				'success'      => true,
				'message'      => 'Submitted through WooCommerce PayPal Payments Package Tracking.',
				'code'         => 'submitted',
				'retryable'    => false,
				'carrier_code' => $carrier['code'],
				'carrier_name' => $carrier['name'],
				'api'          => 'woocommerce-paypal-payments-native',
			);
		} catch ( \Throwable $error ) {
			$message = trim( wp_strip_all_tags( $error->getMessage() ) );
			if ( '' === $message ) {
				$message = get_class( $error );
			}

			return array(
				'success'      => false,
				'message'      => substr( $message, 0, 1000 ),
				'code'         => (int) $error->getCode(),
				'retryable'    => $this->is_retryable_error( $error ),
				'carrier_code' => $carrier['code'],
				'carrier_name' => $carrier['name'],
				'api'          => 'woocommerce-paypal-payments-native',
			);
		} finally {
			remove_filter( 'woocommerce_paypal_payments_tracking_data_before_sending', array( $this, 'filter_create_data' ), 20 );
			remove_filter( 'woocommerce_paypal_payments_tracking_data_before_update', array( $this, 'filter_update_data' ), 20 );
			$this->send_context = null;
		}
	}

	/**
	 * @param array $data     PayPal request body.
	 * @param int   $order_id WooCommerce order ID.
	 * @return array
	 */
	public function filter_create_data( $data, $order_id ) {
		if ( ! is_array( $data ) || ! $this->context_matches( $order_id ) ) {
			return $data;
		}

		if ( isset( $data['trackers'] ) && is_array( $data['trackers'] ) ) {
			foreach ( $data['trackers'] as $index => $tracker ) {
				if ( is_array( $tracker ) ) {
					$data['trackers'][ $index ] = $this->apply_carrier( $tracker );
				}
			}
			return $data;
		}

		return $this->apply_carrier( $data );
	}

	/**
	 * @param array $data     PayPal request body.
	 * @param int   $order_id WooCommerce order ID.
	 * @return array
	 */
	public function filter_update_data( $data, $order_id ) {
		if ( ! is_array( $data ) || ! $this->context_matches( $order_id ) ) {
			return $data;
		}

		return $this->apply_carrier( $data );
	}

	/**
	 * Match a Dianxiaomi slug/name to the carriers exposed by the installed
	 * WooCommerce PayPal Payments build. Unknown carriers safely use OTHER.
	 *
	 * @param string $provider      Dianxiaomi provider slug/code.
	 * @param string $provider_name Dianxiaomi provider label.
	 * @return array{code:string,name:string,matched:bool}
	 */
	public function resolve_carrier( $provider, $provider_name = '' ) {
		$provider      = trim( wp_strip_all_tags( (string) $provider ) );
		$provider_name = trim( wp_strip_all_tags( (string) $provider_name ) );
		$carriers      = $this->available_carriers();
		$candidates    = array_values( array_unique( array_filter( array( $provider, $provider_name ) ) ) );

		foreach ( $candidates as $candidate ) {
			$code = strtoupper( trim( preg_replace( '/[^A-Z0-9]+/i', '_', $candidate ), '_' ) );
			if ( isset( $carriers[ $code ] ) && 'OTHER' !== $code ) {
				return array( 'code' => $code, 'name' => $carriers[ $code ], 'matched' => true );
			}
		}

		$aliases = array(
			'3pe'                => '3PE_EXPRESS',
			'3peexpress'         => '3PE_EXPRESS',
			'4px'                => 'FOUR_PX_EXPRESS',
			'4pxexpress'         => 'FOUR_PX_EXPRESS',
			'fourpx'             => 'FOUR_PX_EXPRESS',
			'fourpxexpress'      => 'FOUR_PX_EXPRESS',
			'dhl'                => 'DHL',
			'dhlexpress'         => 'DHL',
			'fedex'              => 'FEDEX',
			'gls'                => 'GLS',
			'ups'                => 'UPS',
			'usps'               => 'USPS',
			'yanwen'             => 'YANWEN',
			'yanwenlogistics'    => 'YANWEN',
			'yunexpress'         => 'YUNEXPRESS',
			'yunexpresslogistics' => 'YUNEXPRESS',
			'sfb2c'              => 'SFB2C',
			'sfinternational'    => 'SFB2C',
			'sfexpress'          => 'SF_EX',
			'singaporepost'      => 'SG_SG_POST',
			'singpost'           => 'SG_SG_POST',
			'sfc'                => 'SFC_LOGISTICS',
			'sfclogistics'       => 'SFC_LOGISTICS',
			'sfcservice'         => 'SFCSERVICE',
			'chinapost'          => 'CN_CHINA_POST_EMS',
			'emschina'           => 'EMS_CN',
			'chinaems'           => 'EMS_CN',
			'sagawa'             => 'SAGAWA',
			'aramex'             => 'ARAMEX',
			'dpd'                => 'DPD',
			'tnt'                => 'TNT',
			'landmarkglobal'     => 'LANDMARK_GLOBAL',
		);

		foreach ( $candidates as $candidate ) {
			$normalized = $this->normalize( $candidate );
			if ( isset( $aliases[ $normalized ], $carriers[ $aliases[ $normalized ] ] ) ) {
				$code = $aliases[ $normalized ];
				return array( 'code' => $code, 'name' => $carriers[ $code ], 'matched' => true );
			}
		}

		foreach ( $candidates as $candidate ) {
			$normalized = $this->normalize( $candidate );
			foreach ( $carriers as $code => $label ) {
				if ( 'OTHER' !== $code && $normalized === $this->normalize( $label ) ) {
					return array( 'code' => $code, 'name' => $label, 'matched' => true );
				}
			}
		}

		$name = $provider_name ?: $this->humanize( $provider );
		if ( '' === $name ) {
			$name = 'Other';
		}

		return array( 'code' => 'OTHER', 'name' => substr( $name, 0, 64 ), 'matched' => false );
	}

	/**
	 * @return array<string,string>
	 */
	private function available_carriers() {
		if ( null !== $this->carrier_cache ) {
			return $this->carrier_cache;
		}

		$carriers = array();

		try {
			if ( class_exists( '\\WooCommerce\\PayPalCommerce\\PPCP' ) ) {
				$groups = \WooCommerce\PayPalCommerce\PPCP::container()->get( 'order-tracking.available-carriers' );
				if ( is_array( $groups ) ) {
					foreach ( $groups as $group ) {
						if ( empty( $group['items'] ) || ! is_array( $group['items'] ) ) {
							continue;
						}
						foreach ( $group['items'] as $code => $label ) {
							if ( is_scalar( $label ) ) {
								$carriers[ strtoupper( (string) $code ) ] = (string) $label;
							}
						}
					}
				}
			}
		} catch ( \Throwable $error ) {
			Plugin::log( 'debug', 'Could not read PayPal Payments carrier service.', array( 'message' => $error->getMessage() ) );
		}

		$fallback = array(
			'3PE_EXPRESS'       => '3PE Express',
			'ARAMEX'            => 'Aramex',
			'DHL'               => 'DHL',
			'DPD'               => 'DPD',
			'FEDEX'             => 'FedEx',
			'FOUR_PX_EXPRESS'   => '4PX Express',
			'GLS'               => 'GLS',
			'LANDMARK_GLOBAL'   => 'Landmark Global',
			'SAGAWA'            => 'Sagawa',
			'SFB2C'             => 'SF International',
			'SF_EX'             => 'SF Express',
			'SG_SG_POST'        => 'Singapore Post',
			'TNT'               => 'TNT',
			'UPS'               => 'UPS',
			'USPS'              => 'USPS',
			'YANWEN'            => 'Yanwen Logistics',
			'OTHER'             => 'Other',
		);

		$this->carrier_cache = empty( $carriers ) ? $fallback : $carriers + array( 'OTHER' => 'Other' );
		return $this->carrier_cache;
	}

	/**
	 * @param int $order_id WooCommerce order ID.
	 * @return bool
	 */
	private function context_matches( $order_id ) {
		return is_array( $this->send_context )
			&& (int) $order_id === (int) $this->send_context['order_id'];
	}

	/**
	 * @param array $tracker Single tracker payload.
	 * @return array
	 */
	private function apply_carrier( array $tracker ) {
		if ( ! is_array( $this->send_context ) ) {
			return $tracker;
		}

		$code = (string) $this->send_context['carrier_code'];
		$name = (string) $this->send_context['carrier_name'];

		$tracker['carrier'] = $code;
		if ( 'OTHER' === $code ) {
			$tracker['carrier_name_other'] = $name ?: 'Other';
		} else {
			unset( $tracker['carrier_name_other'] );
		}

		return $tracker;
	}

	/**
	 * @param string $value Text to normalize.
	 * @return string
	 */
	private function normalize( $value ) {
		$value = function_exists( 'remove_accents' ) ? remove_accents( (string) $value ) : (string) $value;
		return strtolower( (string) preg_replace( '/[^a-z0-9]+/i', '', $value ) );
	}

	/**
	 * @param string $provider Provider slug.
	 * @return string
	 */
	private function humanize( $provider ) {
		$provider = trim( preg_replace( '/[-_]+/', ' ', (string) $provider ) );
		return ucwords( $provider );
	}

	/**
	 * @param \Throwable $error API error.
	 * @return bool
	 */
	private function is_retryable_error( $error ) {
		$code = (int) $error->getCode();
		if ( 408 === $code || 429 === $code || $code >= 500 ) {
			return true;
		}

		if ( $code >= 400 && $code < 500 ) {
			return false;
		}

		$message = strtolower( (string) $error->getMessage() );
		$permanent_markers = array(
			'invalid tracking',
			'invalid carrier',
			'permission denied',
			'not authorized',
			'unprocessable',
			'could not retrieve transaction id',
		);

		foreach ( $permanent_markers as $marker ) {
			if ( false !== strpos( $message, $marker ) ) {
				return false;
			}
		}

		return true;
	}
}
