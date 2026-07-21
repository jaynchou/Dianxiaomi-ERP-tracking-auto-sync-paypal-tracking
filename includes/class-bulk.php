<?php

namespace DxmPpcpBridge;

defined( 'ABSPATH' ) || exit;

/**
 * Background scanner for recent or historical WooCommerce orders.
 */
final class Bulk {
	const OPTION_KEY  = 'dxm_ppcp_bridge_bulk_job';
	const BATCH_SIZE  = 40;
	const DAY_SECONDS = 86400;

	/** @var Native_PayPal */
	private $native;

	/** @var Sync */
	private $sync;

	/**
	 * @param Native_PayPal $native Native PayPal adapter.
	 * @param Sync          $sync   Synchronization service.
	 */
	public function __construct( Native_PayPal $native, Sync $sync ) {
		$this->native = $native;
		$this->sync   = $sync;
	}

	/**
	 * @return void
	 */
	public function register_hooks() {
		add_action( Plugin::BULK_SCAN_HOOK, array( $this, 'run_scan' ), 10, 2 );
	}

	/**
	 * Start one background scan. Only one scanner runs at a time, while already
	 * queued per-order actions may continue independently and idempotently.
	 *
	 * @param string $scope days7, days15, days30, or all.
	 * @return array<string,mixed>|\WP_Error
	 */
	public function start( $scope ) {
		$scope      = sanitize_key( (string) $scope );
		$scope_days = self::scope_days( $scope );
		if ( null === $scope_days ) {
			return new \WP_Error( 'dxm_ppcp_invalid_bulk_scope', 'Invalid historical synchronization scope.' );
		}

		if ( 'yes' !== Plugin::setting( 'enabled', 'yes' ) ) {
			return new \WP_Error( 'dxm_ppcp_disabled', 'Dianxiaomi PayPal bridge is disabled.' );
		}

		if ( ! $this->native->is_available() ) {
			return new \WP_Error( 'dxm_ppcp_native_api_missing', 'WooCommerce PayPal Payments native tracking API is unavailable.' );
		}

		$current = $this->job();
		if ( in_array( (string) ( $current['status'] ?? '' ), array( 'queued', 'scanning' ), true ) ) {
			$last_activity = strtotime( (string) ( $current['updated_at'] ?? ( $current['started_at'] ?? '' ) ) );
			if ( false !== $last_activity && $last_activity > time() - 1800 ) {
				return new \WP_Error( 'dxm_ppcp_bulk_running', 'A historical order scan is already running.' );
			}
		}

		$cutoff = time();
		$job    = array(
			'id'               => str_replace( '-', '', wp_generate_uuid4() ),
			'scope'            => $scope,
			'range_days'       => $scope_days,
			'status'           => 'queued',
			'started_at'       => gmdate( 'c' ),
			'updated_at'       => gmdate( 'c' ),
			'finished_at'      => '',
			'cutoff'           => $cutoff,
			'range_start'      => $scope_days > 0 ? $cutoff - ( $scope_days * self::DAY_SECONDS ) : 0,
			'page'             => 0,
			'total_pages'      => 0,
			'scanned'          => 0,
			'paypal_orders'    => 0,
			'with_tracking'    => 0,
			'queued_orders'    => 0,
			'already_queued'   => 0,
			'already_synced'   => 0,
			'without_tracking' => 0,
			'skipped'          => 0,
			'last_error'       => '',
		);

		update_option( self::OPTION_KEY, $job, false );
		if ( ! $this->schedule_scan( $job['id'], 1, 2 ) ) {
			$job['status']     = 'failed';
			$job['last_error'] = 'Could not queue the first historical scan action.';
			update_option( self::OPTION_KEY, $job, false );
			return new \WP_Error( 'dxm_ppcp_bulk_queue_failed', $job['last_error'] );
		}

		return $job;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function job() {
		$job = get_option( self::OPTION_KEY, array() );
		return is_array( $job ) ? $job : array();
	}

	/**
	 * Queue selected orders from the WooCommerce list-table bulk action.
	 *
	 * @param array<int,mixed> $order_ids Selected order IDs.
	 * @return array<string,int>
	 */
	public function queue_selected( array $order_ids ) {
		$stats = array(
			'selected'         => 0,
			'queued'           => 0,
			'already_queued'   => 0,
			'already_synced'   => 0,
			'without_tracking' => 0,
			'skipped'          => 0,
		);

		$order_ids = array_values( array_unique( array_filter( array_map( 'absint', $order_ids ) ) ) );
		if ( 'yes' !== Plugin::setting( 'enabled', 'yes' ) ) {
			$stats['selected'] = count( $order_ids );
			$stats['skipped']  = count( $order_ids );
			return $stats;
		}

		foreach ( $order_ids as $order_id ) {
			++$stats['selected'];
			$order = wc_get_order( $order_id );
			if ( ! $this->sync->is_shipped_order( $order ) || ! $this->native->is_native_paypal_order( $order ) ) {
				++$stats['skipped'];
				continue;
			}
			if ( is_wp_error( $this->native->validate_order( $order ) ) ) {
				++$stats['skipped'];
				continue;
			}

			$shipments = $this->sync->shipments( $order );
			if ( empty( $shipments ) ) {
				++$stats['without_tracking'];
				continue;
			}

			if ( ! $this->sync->needs_sync( $order, $shipments ) ) {
				++$stats['already_synced'];
				continue;
			}

			$delay = 5 + ( $stats['queued'] * 5 );
			if ( $this->sync->queue_order( $order_id, $delay ) ) {
				++$stats['queued'];
			} else {
				++$stats['already_queued'];
			}
		}

		return $stats;
	}

	/**
	 * Process one small page of historical orders and schedule only unsynced,
	 * eligible shipments. The next page is queued after this page finishes.
	 *
	 * @param string $job_id Current job UUID.
	 * @param int    $page    One-indexed query page.
	 * @return void
	 */
	public function run_scan( $job_id, $page ) {
		$job  = $this->job();
		$page = max( 1, (int) $page );

		if ( empty( $job['id'] ) || ! hash_equals( (string) $job['id'], (string) $job_id ) ) {
			return;
		}

		if ( ! in_array( (string) ( $job['status'] ?? '' ), array( 'queued', 'scanning' ), true ) ) {
			return;
		}

		$job['status']     = 'scanning';
		$job['page']       = $page;
		$job['updated_at'] = gmdate( 'c' );
		update_option( self::OPTION_KEY, $job, false );

		try {
			$cutoff      = (int) ( $job['cutoff'] ?? time() );
			$range_start = (int) ( $job['range_start'] ?? 0 );
			if ( $range_start <= 0 && 'recent20' === (string) ( $job['scope'] ?? '' ) ) {
				// A queued 1.1.x job may survive an in-place plugin update. Convert it
				// to the new safe 30-day Completed-only window.
				$range_start = $cutoff - ( 30 * self::DAY_SECONDS );
			}

			$date_completed = $range_start > 0 ? $range_start . '...' . $cutoff : '<=' . $cutoff;
			$query = wc_get_orders(
				array(
					'type'           => 'shop_order',
					'status'         => array( 'completed' ),
					'limit'          => self::BATCH_SIZE,
					'page'           => $page,
					'paginate'       => true,
					'orderby'        => 'date',
					'order'          => 'DESC',
					'date_completed' => $date_completed,
					'return'         => 'objects',
				)
			);

			$orders    = is_object( $query ) && isset( $query->orders ) && is_array( $query->orders ) ? $query->orders : ( is_array( $query ) ? $query : array() );
			$max_pages = is_object( $query ) && isset( $query->max_num_pages ) ? max( 1, (int) $query->max_num_pages ) : 1;
			$job['total_pages'] = $max_pages;

			foreach ( $orders as $order ) {
				if ( ! $order instanceof \WC_Order ) {
					$order = wc_get_order( (int) $order );
				}

				++$job['scanned'];
				if ( ! $this->sync->is_shipped_order( $order ) || ! $this->native->is_native_paypal_order( $order ) ) {
					++$job['skipped'];
					continue;
				}

				if ( is_wp_error( $this->native->validate_order( $order ) ) ) {
					++$job['skipped'];
					continue;
				}
				++$job['paypal_orders'];

				$shipments = $this->sync->shipments( $order );
				if ( empty( $shipments ) ) {
					++$job['without_tracking'];
					continue;
				}

				++$job['with_tracking'];
				if ( ! $this->sync->needs_sync( $order, $shipments ) ) {
					++$job['already_synced'];
					continue;
				}

				$delay = 5 + ( (int) $job['queued_orders'] * 5 );
				if ( $this->sync->queue_order( $order->get_id(), $delay ) ) {
					++$job['queued_orders'];
				} else {
					++$job['already_queued'];
				}
			}

			$has_next = $page < $max_pages;
			if ( $has_next ) {
				$job['status']     = 'queued';
				$job['updated_at'] = gmdate( 'c' );
				update_option( self::OPTION_KEY, $job, false );
				if ( ! $this->schedule_scan( $job['id'], $page + 1, 5 ) ) {
					$job['status']     = 'failed';
					$job['last_error'] = 'Could not queue the next historical scan page.';
					update_option( self::OPTION_KEY, $job, false );
				}
				return;
			}

			$job['status']      = 'scan_complete';
			$job['finished_at'] = gmdate( 'c' );
			$job['updated_at']  = gmdate( 'c' );
			update_option( self::OPTION_KEY, $job, false );

			Plugin::log(
				'info',
				'Historical PayPal tracking scan completed.',
				array(
					'scope'            => $job['scope'],
					'scanned'          => $job['scanned'],
					'queued_orders'    => $job['queued_orders'],
					'already_synced'   => $job['already_synced'],
					'without_tracking' => $job['without_tracking'],
				)
			);
		} catch ( \Throwable $error ) {
			$job['status']      = 'failed';
			$job['finished_at'] = gmdate( 'c' );
			$job['updated_at']  = gmdate( 'c' );
			$job['last_error']  = substr( wp_strip_all_tags( $error->getMessage() ), 0, 1000 );
			update_option( self::OPTION_KEY, $job, false );
			Plugin::log( 'error', 'Historical PayPal tracking scan failed.', array( 'message' => $job['last_error'] ) );
		}
	}

	/**
	 * @param string $scope Bulk range identifier.
	 * @return int|null Number of days, zero for all history, or null if invalid.
	 */
	public static function scope_days( $scope ) {
		$scopes = array(
			'days7'  => 7,
			'days15' => 15,
			'days30' => 30,
			'all'    => 0,
		);

		return array_key_exists( (string) $scope, $scopes ) ? $scopes[ (string) $scope ] : null;
	}

	/**
	 * @param string $job_id Current job UUID.
	 * @param int    $page    Page to scan.
	 * @param int    $delay   Delay in seconds.
	 * @return bool
	 */
	private function schedule_scan( $job_id, $page, $delay ) {
		$args  = array( 'job_id' => (string) $job_id, 'page' => (int) $page );
		$delay = max( 1, min( 300, (int) $delay ) );

		if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( Plugin::BULK_SCAN_HOOK, $args, Plugin::ACTION_GROUP ) ) {
			return true;
		}

		if ( function_exists( 'as_schedule_single_action' ) ) {
			return (bool) as_schedule_single_action( time() + $delay, Plugin::BULK_SCAN_HOOK, $args, Plugin::ACTION_GROUP );
		}

		$cron_args = array( (string) $job_id, (int) $page );
		if ( wp_next_scheduled( Plugin::BULK_SCAN_HOOK, $cron_args ) ) {
			return true;
		}

		return (bool) wp_schedule_single_event( time() + $delay, Plugin::BULK_SCAN_HOOK, $cron_args );
	}
}
