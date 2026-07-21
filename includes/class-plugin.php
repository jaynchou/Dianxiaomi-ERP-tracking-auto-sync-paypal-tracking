<?php

namespace DxmPpcpBridge;

defined( 'ABSPATH' ) || exit;

final class Plugin {
	const OPTION_KEY    = 'dxm_ppcp_bridge_settings';
	const MIGRATION_KEY = 'dxm_ppcp_bridge_migrated';
	const LOG_SOURCE    = 'dxm-paypal-package-bridge';
	const ACTION_HOOK   = 'dxm_ppcp_bridge_sync_order';
	const BULK_SCAN_HOOK = 'dxm_ppcp_bridge_bulk_scan';
	const ACTION_GROUP  = 'dxm-paypal-package-bridge';

	/** @var Plugin|null */
	private static $instance = null;

	/** @var Native_PayPal|null */
	private $native;

	/** @var Sync|null */
	private $sync;

	/** @var Admin|null */
	private $admin;

	/** @var Bulk|null */
	private $bulk;

	/**
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Initialize the bridge after WooCommerce and PayPal Payments have loaded.
	 *
	 * @return void
	 */
	public function init() {
		if ( ! class_exists( 'WooCommerce' ) || ! function_exists( 'wc_get_order' ) ) {
			add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
			return;
		}

		self::maybe_migrate_old_settings();

		$this->native = new Native_PayPal();
		$this->sync   = new Sync( $this->native );
		$this->bulk   = new Bulk( $this->native, $this->sync );
		$this->admin  = new Admin( $this->native, $this->sync, $this->bulk );

		$this->sync->register_hooks();
		$this->bulk->register_hooks();
		$this->admin->register_hooks();

		if ( ! $this->native->is_available() ) {
			add_action( 'admin_notices', array( $this, 'paypal_api_missing_notice' ) );
		}

		if ( ! empty( self::conflicting_plugins() ) ) {
			add_action( 'admin_notices', array( $this, 'conflict_notice' ) );
		}
	}

	/**
	 * @return void
	 */
	public static function activate() {
		self::maybe_migrate_old_settings();
	}

	/**
	 * Remove pending jobs without deleting settings or order history.
	 *
	 * @return void
	 */
	public static function deactivate() {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::ACTION_HOOK, array(), self::ACTION_GROUP );
			as_unschedule_all_actions( self::BULK_SCAN_HOOK, array(), self::ACTION_GROUP );
		}

		if ( function_exists( 'wp_unschedule_hook' ) ) {
			wp_unschedule_hook( self::ACTION_HOOK );
			wp_unschedule_hook( self::BULK_SCAN_HOOK );
		} else {
			wp_clear_scheduled_hook( self::ACTION_HOOK );
			wp_clear_scheduled_hook( self::BULK_SCAN_HOOK );
		}
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			'enabled'                  => 'yes',
			'auto_sync'                => 'yes',
			'delay'                    => 20,
			'tracking_number_meta_key' => '_dianxiaomi_tracking_number',
			'provider_meta_key'        => '_dianxiaomi_tracking_provider',
			'provider_name_meta_key'   => '_dianxiaomi_tracking_provider_name',
			'debug'                    => 'no',
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function settings() {
		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return wp_parse_args( $stored, self::defaults() );
	}

	/**
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback value.
	 * @return mixed
	 */
	public static function setting( $key, $default = null ) {
		$settings = self::settings();
		return array_key_exists( $key, $settings ) ? $settings[ $key ] : $default;
	}

	/**
	 * Preserve the old paid plugin's automatic-sync and reversed-field choices.
	 * API credentials are intentionally not migrated or used.
	 *
	 * @return void
	 */
	public static function maybe_migrate_old_settings() {
		if ( get_option( self::MIGRATION_KEY, false ) ) {
			return;
		}

		$settings = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$old_automate = get_option( 'woocommerce-add-tracking-info-to-paypal_automate', '' );
		$old_reverse  = get_option( 'woocommerce-add-tracking-info-to-paypal_reverse_provider_tracking_number', '' );

		if ( '' !== $old_automate ) {
			$settings['auto_sync'] = in_array( $old_automate, array( 'yes', 'true', true, 1, '1' ), true ) ? 'yes' : 'no';
		}

		if ( 'yes' === $old_reverse ) {
			$settings['tracking_number_meta_key'] = '_dianxiaomi_tracking_provider';
			$settings['provider_meta_key']        = '_dianxiaomi_tracking_number';
		}

		update_option( self::OPTION_KEY, wp_parse_args( $settings, self::defaults() ), false );
		update_option( self::MIGRATION_KEY, gmdate( 'c' ), false );
	}

	/**
	 * @return array<int,string>
	 */
	public static function conflicting_plugins() {
		$conflicts = array();

		if ( class_exists( '\\WC_Settings_tab_paypal_tracking_info', false ) || function_exists( 'sychronize_to_paypal' ) ) {
			$conflicts[] = 'Add Tracking Info to PayPal 1.2.0';
		}

		if ( class_exists( '\\DxmPaypalTracking\\Plugin', false ) ) {
			$conflicts[] = 'Dianxiaomi to PayPal Tracking Sync 2.x';
		}

		return $conflicts;
	}

	/**
	 * @param string $level   Logger level.
	 * @param string $message Message.
	 * @param array  $context Context without credentials or tokens.
	 * @return void
	 */
	public static function log( $level, $message, array $context = array() ) {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		$allowed = array( 'debug', 'info', 'notice', 'warning', 'error', 'critical' );
		if ( ! in_array( $level, $allowed, true ) ) {
			$level = 'info';
		}

		if ( 'debug' === $level && 'yes' !== self::setting( 'debug', 'no' ) ) {
			return;
		}

		$context['source'] = self::LOG_SOURCE;
		wc_get_logger()->log( $level, $message, $context );
	}

	/**
	 * @return void
	 */
	public function woocommerce_missing_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p>' . esc_html__( 'Dianxiaomi PayPal Bridge requires WooCommerce.', 'dianxiaomi-paypal-package-tracking-bridge' ) . '</p></div>';
	}

	/**
	 * @return void
	 */
	public function paypal_api_missing_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p>' . esc_html__( 'Dianxiaomi PayPal Bridge requires WooCommerce PayPal Payments 3.1.0 or newer. Its native Package Tracking API was not found.', 'dianxiaomi-paypal-package-tracking-bridge' ) . '</p></div>';
	}

	/**
	 * @return void
	 */
	public function conflict_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$message = sprintf(
			/* translators: %s: conflicting plugin names. */
			__( 'Dianxiaomi PayPal Bridge detected another PayPal tracking sync plugin: %s. Deactivate the old sync plugin to prevent duplicate submissions.', 'dianxiaomi-paypal-package-tracking-bridge' ),
			implode( ', ', self::conflicting_plugins() )
		);

		echo '<div class="notice notice-warning"><p>' . esc_html( $message ) . '</p></div>';
	}
}
