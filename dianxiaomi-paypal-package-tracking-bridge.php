<?php
/**
 * Plugin Name:       Dianxiaomi to PayPal Package Tracking Bridge
 * Plugin URI:        https://github.com/jaynchou/-ERP-paypal-tracking
 * Description:       Sends Dianxiaomi tracking numbers through WooCommerce PayPal Payments' native Package Tracking service.
 * Version:           1.2.0
 * Author:            jaynchou
 * Author URI:        https://github.com/jaynchou
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce, woocommerce-paypal-payments
 * WC requires at least: 9.6
 * WC tested up to:   10.9
 * Text Domain:       dianxiaomi-paypal-package-tracking-bridge
 */

namespace DxmPpcpBridge;

defined( 'ABSPATH' ) || exit;

define( 'DXM_PPCP_BRIDGE_VERSION', '1.2.0' );
define( 'DXM_PPCP_BRIDGE_FILE', __FILE__ );
define( 'DXM_PPCP_BRIDGE_PATH', plugin_dir_path( __FILE__ ) );

require_once DXM_PPCP_BRIDGE_PATH . 'includes/class-native-paypal.php';
require_once DXM_PPCP_BRIDGE_PATH . 'includes/class-sync.php';
require_once DXM_PPCP_BRIDGE_PATH . 'includes/class-bulk.php';
require_once DXM_PPCP_BRIDGE_PATH . 'includes/class-admin.php';
require_once DXM_PPCP_BRIDGE_PATH . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Plugin::class, 'deactivate' ) );

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

add_action(
	'plugins_loaded',
	static function () {
		Plugin::instance()->init();
	},
	40
);
