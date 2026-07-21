<?php

namespace DxmPpcpBridge;

defined( 'ABSPATH' ) || exit;

final class Sync {
	const META_STATUS         = '_dxm_ppcp_bridge_status';
	const META_SYNCED         = '_dxm_ppcp_bridge_synced';
	const META_LAST_ERROR     = '_dxm_ppcp_bridge_last_error';
	const META_LAST_SYNCED_AT = '_dxm_ppcp_bridge_last_synced_at';
	const META_RETRY_COUNT    = '_dxm_ppcp_bridge_retry_count';
	const META_WAIT_COUNT     = '_dxm_ppcp_bridge_wait_count';
	const META_NOTE_SHIPMENTS = '_dxm_ppcp_bridge_note_shipments';

	/** @var Native_PayPal */
	private $native;

	/** @var bool */
	private static $internal_update = false;

	/**
	 * @param Native_PayPal $native Native PayPal adapter.
	 */
	public function __construct( Native_PayPal $native ) {
		$this->native = $native;
	}

	/**
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'added_post_meta', array( $this, 'legacy_meta_changed' ), 10, 4 );
		add_action( 'updated_post_meta', array( $this, 'legacy_meta_changed' ), 10, 4 );
		add_action( 'woocommerce_after_order_object_save', array( $this, 'order_object_saved' ), 20, 2 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'order_status_triggered' ), 20, 1 );
		add_action( 'woocommerce_order_note_added', array( $this, 'order_note_added' ), 20, 2 );
		add_action( 'woocommerce_rest_insert_shop_order_object', array( $this, 'rest_order_saved' ), 20, 3 );
		add_action( Plugin::ACTION_HOOK, array( $this, 'run_scheduled_sync' ), 10, 1 );
	}

	/**
	 * Catch direct postmeta writes used by legacy Dianxiaomi integrations.
	 *
	 * @param int    $meta_id    Meta ID.
	 * @param int    $object_id  Order ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Meta value.
	 * @return void
	 */
	public function legacy_meta_changed( $meta_id, $object_id, $meta_key, $meta_value ) {
		unset( $meta_id, $meta_value );

		if ( self::$internal_update || ! $this->automatic_enabled() ) {
			return;
		}

		$watched = array(
			(string) Plugin::setting( 'tracking_number_meta_key', '_dianxiaomi_tracking_number' ),
			(string) Plugin::setting( 'provider_meta_key', '_dianxiaomi_tracking_provider' ),
			(string) Plugin::setting( 'provider_name_meta_key', '_dianxiaomi_tracking_provider_name' ),
			'_wc_shipment_tracking_items',
		);

		if ( ! in_array( (string) $meta_key, $watched, true ) ) {
			return;
		}

		$order = wc_get_order( (int) $object_id );
		if ( $order instanceof \WC_Order ) {
			$this->maybe_schedule_order( $order );
		}
	}

	/**
	 * HPOS-compatible order-save trigger.
	 *
	 * @param mixed $order      Saved object.
	 * @param mixed $data_store Data store.
	 * @return void
	 */
	public function order_object_saved( $order, $data_store ) {
		unset( $data_store );

		if ( self::$internal_update || ! $this->automatic_enabled() ) {
			return;
		}

		if ( $order instanceof \WC_Order ) {
			$this->maybe_schedule_order( $order );
		}
	}

	/**
	 * @param int $order_id WooCommerce order ID.
	 * @return void
	 */
	public function order_status_triggered( $order_id ) {
		if ( ! $this->automatic_enabled() ) {
			return;
		}

		$order = wc_get_order( (int) $order_id );
		if ( ! $this->is_shipped_order( $order ) || ! $this->native->is_native_paypal_order( $order ) ) {
			return;
		}

		$delay = empty( $this->shipments( $order ) ) ? 90 : (int) Plugin::setting( 'delay', 20 );
		$this->schedule( $order->get_id(), $delay );
	}

	/**
	 * Parse the shipment note used by Dianxiaomi REST API authorization. The
	 * original customer note and its email behavior are left untouched.
	 *
	 * @param int       $note_id Order-note comment ID.
	 * @param \WC_Order $order   WooCommerce order.
	 * @return void
	 */
	public function order_note_added( $note_id, $order ) {
		if ( ! $this->automatic_enabled() || ! $order instanceof \WC_Order || ! $this->native->is_native_paypal_order( $order ) ) {
			return;
		}

		$comment = get_comment( (int) $note_id );
		if ( ! $comment || empty( $comment->comment_content ) ) {
			return;
		}

		$parsed = $this->parse_tracking_note( (string) $comment->comment_content );
		if ( empty( $parsed['tracking_number'] ) ) {
			return;
		}

		$stored = $order->get_meta( self::META_NOTE_SHIPMENTS, true, 'edit' );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$key            = strtoupper( (string) $parsed['tracking_number'] );
		$stored[ $key ] = array(
			'tracking_number' => (string) $parsed['tracking_number'],
			'provider'        => sanitize_title( (string) $parsed['provider'] ),
			'provider_name'   => (string) $parsed['provider'],
		);

		$order->update_meta_data( self::META_NOTE_SHIPMENTS, $stored );
		$this->save_meta_data( $order );
		if ( $this->is_shipped_order( $order ) ) {
			$this->schedule( $order->get_id(), (int) Plugin::setting( 'delay', 20 ) );
		}
	}

	/**
	 * @param \WC_Order        $order    Order object.
	 * @param \WP_REST_Request $request  REST request.
	 * @param bool             $creating Whether creating.
	 * @return void
	 */
	public function rest_order_saved( $order, $request, $creating ) {
		unset( $request, $creating );

		if ( $this->automatic_enabled() && $order instanceof \WC_Order ) {
			$this->maybe_schedule_order( $order );
		}
	}

	/**
	 * @param int $order_id WooCommerce order ID.
	 * @return void
	 */
	public function run_scheduled_sync( $order_id ) {
		$this->sync_order( (int) $order_id, false );
	}

	/**
	 * Synchronize every new or changed shipment on one order.
	 *
	 * @param int  $order_id WooCommerce order ID.
	 * @param bool $force    Force add/update even if the local signature matches.
	 * @return array<string,mixed>
	 */
	public function sync_order( $order_id, $force = false ) {
		$order = wc_get_order( (int) $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return $this->result( false, 'WooCommerce order was not found.', 'invalid_order' );
		}

		if ( 'yes' !== Plugin::setting( 'enabled', 'yes' ) ) {
			return $this->result( false, 'Dianxiaomi PayPal bridge is disabled.', 'disabled' );
		}

		if ( ! $this->is_shipped_order( $order ) ) {
			return $this->result( false, 'Only completed (shipped) orders can be synchronized.', 'not_shipped' );
		}

		$valid = $this->native->validate_order( $order );
		if ( is_wp_error( $valid ) ) {
			$this->set_status( $order, $valid->get_error_code(), $valid->get_error_message() );
			if ( in_array( $valid->get_error_code(), array( 'dxm_ppcp_missing_order_id', 'dxm_ppcp_missing_capture_id', 'dxm_ppcp_not_captured' ), true ) ) {
				$this->schedule_waiting_retry( $order );
			}
			return $this->result( false, $valid->get_error_message(), $valid->get_error_code() );
		}

		$shipments = $this->shipments( $order );
		if ( empty( $shipments ) ) {
			$this->set_status( $order, 'waiting', 'Dianxiaomi has not supplied a tracking number yet.' );
			$this->schedule_waiting_retry( $order );
			return $this->result( false, 'No Dianxiaomi tracking number was found yet.', 'waiting' );
		}

		$lock_key = 'dxm_ppcp_bridge_lock_' . $order->get_id();
		if ( get_transient( $lock_key ) ) {
			return $this->result( false, 'This order is already being synchronized.', 'locked' );
		}
		set_transient( $lock_key, 1, 120 );

		try {
			$this->set_status( $order, 'syncing', '' );

			$synced = $order->get_meta( self::META_SYNCED, true, 'edit' );
			if ( ! is_array( $synced ) ) {
				$synced = array();
			}

			$results       = array();
			$success_count = 0;
			$errors        = array();
			$retryable     = false;

			foreach ( $shipments as $shipment ) {
				$carrier   = $this->native->resolve_carrier( (string) $shipment['provider'], (string) $shipment['provider_name'] );
				$record_key = $this->record_key( $order, (string) $shipment['tracking_number'] );
				$signature  = $this->signature( $order, $shipment, $carrier );

				if ( ! $force && isset( $synced[ $record_key ]['signature'] ) && hash_equals( (string) $synced[ $record_key ]['signature'], $signature ) ) {
					++$success_count;
					$results[] = array(
						'tracking_number' => (string) $shipment['tracking_number'],
						'success'         => true,
						'skipped'         => true,
						'message'         => 'Already synchronized.',
						'carrier_code'    => $carrier['code'],
						'carrier_name'    => $carrier['name'],
					);
					continue;
				}

				$api_result                    = $this->native->add_tracking( $order, $shipment );
				$api_result['tracking_number'] = (string) $shipment['tracking_number'];
				$results[]                     = $api_result;

				if ( ! empty( $api_result['success'] ) ) {
					++$success_count;
					$synced[ $record_key ] = array(
						'tracking_number' => (string) $shipment['tracking_number'],
						'carrier_code'    => (string) ( $api_result['carrier_code'] ?? 'OTHER' ),
						'carrier_name'    => (string) ( $api_result['carrier_name'] ?? '' ),
						'signature'       => $signature,
						'synced_at'       => gmdate( 'c' ),
					);
				} else {
					$errors[] = (string) ( $api_result['message'] ?? 'Unknown PayPal tracking error.' );
					$retryable = $retryable || ! empty( $api_result['retryable'] );
				}
			}

			$total  = count( $shipments );
			$status = $success_count === $total ? 'synced' : ( $success_count > 0 ? 'partial' : 'failed' );
			$error  = implode( ' | ', array_unique( array_filter( $errors ) ) );

			$order->update_meta_data( self::META_SYNCED, $synced );
			$order->update_meta_data( self::META_STATUS, $status );
			$order->update_meta_data( self::META_LAST_ERROR, substr( $error, 0, 1000 ) );
			if ( 'synced' === $status ) {
				$order->update_meta_data( self::META_LAST_SYNCED_AT, gmdate( 'c' ) );
				$order->update_meta_data( self::META_RETRY_COUNT, 0 );
				$order->update_meta_data( self::META_WAIT_COUNT, 0 );
			}
			$this->save_meta_data( $order );

			if ( 'synced' === $status ) {
				Plugin::log( 'info', 'Tracking synchronized through native PayPal Package Tracking.', array( 'order_id' => $order->get_id(), 'shipments' => $total ) );
				return array(
					'success' => true,
					'status'  => 'synced',
					'message' => sprintf( '%d tracking number(s) synchronized through WooCommerce PayPal Payments.', $total ),
					'results' => $results,
				);
			}

			Plugin::log( 'error', 'Native PayPal tracking synchronization failed.', array( 'order_id' => $order->get_id(), 'message' => $error ) );
			if ( $retryable ) {
				$this->schedule_api_retry( $order );
			}

			return array(
				'success' => false,
				'status'  => $status,
				'message' => '' !== $error ? $error : 'WooCommerce PayPal Payments did not accept the tracking information.',
				'results' => $results,
			);
		} finally {
			delete_transient( $lock_key );
		}
	}

	/**
	 * @param int $order_id WooCommerce order ID.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function inspect_order( $order_id ) {
		$order = wc_get_order( (int) $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return new \WP_Error( 'dxm_ppcp_invalid_order', 'WooCommerce order was not found.' );
		}

		$shipments = $this->shipments( $order );
		foreach ( $shipments as $index => $shipment ) {
			$shipments[ $index ]['paypal_carrier'] = $this->native->resolve_carrier( (string) $shipment['provider'], (string) $shipment['provider_name'] );
		}

		$native_meta = $order->get_meta( '_ppcp_paypal_tracking_info_meta_name', true, 'edit' );

		return array(
			'order_id'             => $order->get_id(),
			'order_number'         => $order->get_order_number(),
			'order_status'         => $order->get_status(),
			'payment_method'       => $order->get_payment_method(),
			'payment_title'        => $order->get_payment_method_title(),
			'paypal_plugin_version' => $this->native->plugin_version(),
			'native_api_available' => $this->native->is_available(),
			'is_native_paypal'     => $this->native->is_native_paypal_order( $order ),
			'paypal_order_id'      => (string) $order->get_meta( '_ppcp_paypal_order_id', true, 'edit' ),
			'capture_id'           => (string) $order->get_transaction_id(),
			'shipments'            => $shipments,
			'native_tracking_meta' => is_array( $native_meta ) ? count( $native_meta ) : 0,
			'sync_status'          => (string) $order->get_meta( self::META_STATUS, true, 'edit' ),
			'last_error'           => (string) $order->get_meta( self::META_LAST_ERROR, true, 'edit' ),
			'last_synced_at'       => (string) $order->get_meta( self::META_LAST_SYNCED_AT, true, 'edit' ),
		);
	}

	/**
	 * Read shipments from Dianxiaomi metadata, standard WooCommerce Shipment
	 * Tracking metadata, and shipment notes captured by this bridge.
	 *
	 * @param \WC_Order $order WooCommerce order.
	 * @return array<int,array{tracking_number:string,provider:string,provider_name:string,source:string}>
	 */
	public function shipments( $order ) {
		if ( ! $order instanceof \WC_Order ) {
			return array();
		}

		$found             = array();
		$number_key        = (string) Plugin::setting( 'tracking_number_meta_key', '_dianxiaomi_tracking_number' );
		$provider_key      = (string) Plugin::setting( 'provider_meta_key', '_dianxiaomi_tracking_provider' );
		$provider_name_key = (string) Plugin::setting( 'provider_name_meta_key', '_dianxiaomi_tracking_provider_name' );
		$numbers           = $this->flatten_values( $order->get_meta( $number_key, true, 'edit' ) );
		$providers         = $this->flatten_values( $order->get_meta( $provider_key, true, 'edit' ) );
		$provider_names    = $this->flatten_values( $order->get_meta( $provider_name_key, true, 'edit' ) );

		foreach ( $numbers as $index => $number ) {
			$this->add_shipment(
				$found,
				$number,
				(string) ( $providers[ $index ] ?? ( $providers[0] ?? '' ) ),
				(string) ( $provider_names[ $index ] ?? ( $provider_names[0] ?? '' ) ),
				'dianxiaomi_meta'
			);
		}

		$standard_items = $order->get_meta( '_wc_shipment_tracking_items', true, 'edit' );
		if ( is_array( $standard_items ) ) {
			foreach ( $standard_items as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}

				$this->add_shipment(
					$found,
					(string) ( $item['tracking_number'] ?? '' ),
					(string) ( $item['tracking_provider'] ?? '' ),
					(string) ( $item['custom_tracking_provider'] ?? '' ),
					'wc_shipment_tracking'
				);
			}
		}

		$note_shipments = $order->get_meta( self::META_NOTE_SHIPMENTS, true, 'edit' );
		if ( is_array( $note_shipments ) ) {
			foreach ( $note_shipments as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$this->add_shipment(
					$found,
					(string) ( $item['tracking_number'] ?? '' ),
					(string) ( $item['provider'] ?? '' ),
					(string) ( $item['provider_name'] ?? '' ),
					'dianxiaomi_note'
				);
			}
		}

		// Notes created before this bridge was activated never passed through the
		// woocommerce_order_note_added hook. Read existing customer/internal order
		// notes as well so diagnostics and manual sync can recover older shipments.
		if ( function_exists( 'wc_get_order_notes' ) ) {
			$historical_notes = wc_get_order_notes(
				array(
					'order_id' => $order->get_id(),
					'limit'    => 100,
					'orderby'  => 'date_created_gmt',
					'order'    => 'DESC',
					'type'     => '',
				)
			);

			if ( is_array( $historical_notes ) ) {
				foreach ( $historical_notes as $note ) {
					$content = is_object( $note ) && isset( $note->content ) ? (string) $note->content : '';
					$parsed  = $this->parse_tracking_note( $content );

					if ( empty( $parsed['tracking_number'] ) ) {
						continue;
					}

					$this->add_shipment(
						$found,
						(string) $parsed['tracking_number'],
						sanitize_title( (string) $parsed['provider'] ),
						(string) $parsed['provider'],
						'order_note_history'
					);
				}
			}
		}

		return array_values( $found );
	}

	/**
	 * @param string $note Raw order-note body.
	 * @return array{provider:string,tracking_number:string}
	 */
	public function parse_tracking_note( $note ) {
		$text = html_entity_decode( wp_strip_all_tags( (string) $note ), ENT_QUOTES, 'UTF-8' );
		$text = (string) preg_replace( '/[\x{00a0}\t]+/u', ' ', $text );

		$patterns = array(
			'/shipped\s+by\s+(.{1,80}?)\s*[\.\r\n]+\s*(?:the\s+)?tracking\s+(?:number|no\.?|#)\s*(?:is|:|-)\s*([A-Z0-9][A-Z0-9._\-]{2,63})/iu',
			'/(?:shipping\s+carrier|carrier)\s*[:\-]\s*([^\r\n,;]{1,80}).*?tracking\s+(?:number|no\.?|#)\s*[:\-]\s*([A-Z0-9][A-Z0-9._\-]{2,63})/isu',
			'/(?:物流公司|物流商|承运商|快递公司)\s*[:：\-]?\s*([^\r\n,，;；]{1,80}).*?(?:运单号|物流单号|快递单号)\s*[:：\-]?\s*([A-Z0-9][A-Z0-9._\-]{2,63})/isu',
			'/(?:已由|通过)\s*([^\r\n,，;；]{1,80})\s*发货.*?(?:运单号|物流单号|快递单号)\s*[:：\-]?\s*([A-Z0-9][A-Z0-9._\-]{2,63})/isu',
		);

		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $text, $matches ) ) {
				return array(
					'provider'        => trim( (string) $matches[1] ),
					'tracking_number' => rtrim( trim( (string) $matches[2] ), '.,;' ),
				);
			}
		}

		$number_patterns = array(
			'/tracking\s+(?:number|no\.?|#)\s*(?:is|:|-)\s*([A-Z0-9][A-Z0-9._\-]{2,63})/iu',
			'/(?:运单号|物流单号|快递单号)\s*[:：\-]?\s*([A-Z0-9][A-Z0-9._\-]{2,63})/iu',
		);

		foreach ( $number_patterns as $pattern ) {
			if ( preg_match( $pattern, $text, $matches ) ) {
				return array(
					'provider'        => '',
					'tracking_number' => rtrim( trim( (string) $matches[1] ), '.,;' ),
				);
			}
		}

		return array( 'provider' => '', 'tracking_number' => '' );
	}

	/**
	 * Determine whether an order has at least one shipment whose current local
	 * signature has not yet been accepted through the bridge.
	 *
	 * @param \WC_Order $order     WooCommerce order.
	 * @param array|null $shipments Optional already-detected shipments.
	 * @return bool
	 */
	public function needs_sync( $order, $shipments = null ) {
		if ( ! $this->is_shipped_order( $order ) || ! $this->native->is_native_paypal_order( $order ) ) {
			return false;
		}

		if ( ! is_array( $shipments ) ) {
			$shipments = $this->shipments( $order );
		}

		if ( empty( $shipments ) ) {
			return false;
		}

		$synced = $order->get_meta( self::META_SYNCED, true, 'edit' );
		if ( ! is_array( $synced ) ) {
			$synced = array();
		}

		foreach ( $shipments as $shipment ) {
			$carrier    = $this->native->resolve_carrier( (string) $shipment['provider'], (string) $shipment['provider_name'] );
			$record_key = $this->record_key( $order, (string) $shipment['tracking_number'] );
			$signature  = $this->signature( $order, $shipment, $carrier );

			if ( ! isset( $synced[ $record_key ]['signature'] ) || ! hash_equals( (string) $synced[ $record_key ]['signature'], $signature ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Treat WooCommerce Completed as the store's shipped state. Processing
	 * orders are intentionally excluded even when partial tracking metadata is
	 * already present, because Dianxiaomi completes the order when it ships.
	 *
	 * @param mixed $order Possible WooCommerce order.
	 * @return bool
	 */
	public function is_shipped_order( $order ) {
		return $order instanceof \WC_Order && 'completed' === (string) $order->get_status();
	}

	/**
	 * Queue one idempotent background synchronization.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @param int $delay    Delay in seconds.
	 * @return bool True when a new action was queued.
	 */
	public function queue_order( $order_id, $delay = 5 ) {
		return $this->schedule( (int) $order_id, (int) $delay );
	}

	/**
	 * @param \WC_Order $order WooCommerce order.
	 * @return void
	 */
	private function maybe_schedule_order( $order ) {
		if ( ! $this->automatic_enabled() || ! $this->is_shipped_order( $order ) || ! $this->needs_sync( $order ) ) {
			return;
		}

		$this->schedule( $order->get_id(), (int) Plugin::setting( 'delay', 20 ) );
	}

	/**
	 * @param int  $order_id WooCommerce order ID.
	 * @param int  $delay    Delay in seconds.
	 * @param bool $force_new Allow a follow-up job while current one is running.
	 * @return bool True when a new action was queued.
	 */
	private function schedule( $order_id, $delay, $force_new = false ) {
		$order_id = (int) $order_id;
		$delay    = max( 5, min( 21600, (int) $delay ) );
		$args     = array( 'order_id' => $order_id );

		if ( ! $force_new && function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( Plugin::ACTION_HOOK, $args, Plugin::ACTION_GROUP ) ) {
			return false;
		}
		if ( ! $force_new && function_exists( 'as_next_scheduled_action' ) && as_next_scheduled_action( Plugin::ACTION_HOOK, $args, Plugin::ACTION_GROUP ) ) {
			return false;
		}
		if ( function_exists( 'as_schedule_single_action' ) ) {
			return (bool) as_schedule_single_action( time() + $delay, Plugin::ACTION_HOOK, $args, Plugin::ACTION_GROUP );
		}

		$cron_args = array( $order_id );
		if ( $force_new || ! wp_next_scheduled( Plugin::ACTION_HOOK, $cron_args ) ) {
			return (bool) wp_schedule_single_event( time() + $delay, Plugin::ACTION_HOOK, $cron_args );
		}

		return false;
	}

	/**
	 * @param \WC_Order $order WooCommerce order.
	 * @return void
	 */
	private function schedule_waiting_retry( $order ) {
		$count = (int) $order->get_meta( self::META_WAIT_COUNT, true, 'edit' );
		if ( $count >= 6 || ! $this->automatic_enabled() ) {
			return;
		}

		++$count;
		$order->update_meta_data( self::META_WAIT_COUNT, $count );
		$this->save_meta_data( $order );
		$this->schedule( $order->get_id(), min( 1800, 120 * $count ), true );
	}

	/**
	 * @param \WC_Order $order WooCommerce order.
	 * @return void
	 */
	private function schedule_api_retry( $order ) {
		$count = (int) $order->get_meta( self::META_RETRY_COUNT, true, 'edit' );
		if ( $count >= 5 || ! $this->automatic_enabled() ) {
			return;
		}

		++$count;
		$order->update_meta_data( self::META_RETRY_COUNT, $count );
		$this->save_meta_data( $order );
		$delays = array( 300, 900, 3600, 10800, 21600 );
		$this->schedule( $order->get_id(), $delays[ $count - 1 ], true );
	}

	/**
	 * @param \WC_Order $order   Order object.
	 * @param string    $status  Status code.
	 * @param string    $message Detail.
	 * @return void
	 */
	private function set_status( $order, $status, $message ) {
		$order->update_meta_data( self::META_STATUS, (string) $status );
		$order->update_meta_data( self::META_LAST_ERROR, substr( (string) $message, 0, 1000 ) );
		$this->save_meta_data( $order );
	}

	/**
	 * @param \WC_Order $order Order whose metadata is being saved.
	 * @return void
	 */
	private function save_meta_data( $order ) {
		self::$internal_update = true;
		try {
			$order->save_meta_data();
		} finally {
			self::$internal_update = false;
		}
	}

	/**
	 * @param array<string,array<string,string>> $found         Shipment map by tracking number.
	 * @param string                            $number        Tracking number.
	 * @param string                            $provider      Provider slug.
	 * @param string                            $provider_name Provider name.
	 * @param string                            $source        Detection source.
	 * @return void
	 */
	private function add_shipment( array &$found, $number, $provider, $provider_name, $source ) {
		$number = $this->clean_tracking_number( $number );
		if ( '' === $number ) {
			return;
		}

		$provider      = substr( trim( wp_strip_all_tags( (string) $provider ) ), 0, 100 );
		$provider_name = substr( trim( wp_strip_all_tags( (string) $provider_name ) ), 0, 100 );
		$key           = strtoupper( $number );

		if ( isset( $found[ $key ] ) ) {
			if ( '' === $found[ $key ]['provider'] && '' !== $provider ) {
				$found[ $key ]['provider'] = $provider;
			}
			if ( '' === $found[ $key ]['provider_name'] && '' !== $provider_name ) {
				$found[ $key ]['provider_name'] = $provider_name;
			}
			return;
		}

		$found[ $key ] = array(
			'tracking_number' => $number,
			'provider'        => $provider,
			'provider_name'   => $provider_name,
			'source'          => (string) $source,
		);
	}

	/**
	 * @param mixed $value Scalar/array/serialized metadata.
	 * @return array<int,string>
	 */
	private function flatten_values( $value ) {
		$value  = maybe_unserialize( $value );
		$values = array();

		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				if ( is_array( $item ) && isset( $item['tracking_number'] ) ) {
					$item = $item['tracking_number'];
				}
				$values = array_merge( $values, $this->flatten_values( $item ) );
			}
			return $values;
		}

		if ( is_scalar( $value ) ) {
			$parts = preg_split( '/[\r\n,;|]+/', (string) $value );
			if ( is_array( $parts ) ) {
				foreach ( $parts as $part ) {
					$part = trim( (string) $part );
					if ( '' !== $part ) {
						$values[] = $part;
					}
				}
			}
		}

		return $values;
	}

	/**
	 * @param string $number Raw tracking number.
	 * @return string
	 */
	private function clean_tracking_number( $number ) {
		$number = trim( wp_strip_all_tags( (string) $number ) );
		$number = (string) preg_replace( '/[\x00-\x1F\x7F]+/', '', $number );
		return substr( $number, 0, 64 );
	}

	/**
	 * @return bool
	 */
	private function automatic_enabled() {
		return 'yes' === Plugin::setting( 'enabled', 'yes' ) && 'yes' === Plugin::setting( 'auto_sync', 'yes' );
	}

	/**
	 * @param \WC_Order $order           WooCommerce order.
	 * @param string    $tracking_number Tracking number.
	 * @return string
	 */
	private function record_key( $order, $tracking_number ) {
		return sha1(
			strtoupper( trim( (string) $order->get_meta( '_ppcp_paypal_order_id', true, 'edit' ) ) ) . '|' .
			strtoupper( trim( (string) $order->get_transaction_id() ) ) . '|' .
			strtoupper( trim( (string) $tracking_number ) )
		);
	}

	/**
	 * @param \WC_Order          $order    WooCommerce order.
	 * @param array<string,mixed> $shipment Shipment.
	 * @param array<string,mixed> $carrier  Resolved carrier.
	 * @return string
	 */
	private function signature( $order, array $shipment, array $carrier ) {
		return sha1(
			$this->record_key( $order, (string) $shipment['tracking_number'] ) . '|' .
			strtoupper( (string) $carrier['code'] ) . '|' .
			strtoupper( (string) $carrier['name'] ) . '|SHIPPED'
		);
	}

	/**
	 * @param bool   $success Success.
	 * @param string $message Human-readable message.
	 * @param string $status  Status code.
	 * @return array<string,mixed>
	 */
	private function result( $success, $message, $status ) {
		return array(
			'success' => (bool) $success,
			'message' => (string) $message,
			'status'  => (string) $status,
			'results' => array(),
		);
	}
}
