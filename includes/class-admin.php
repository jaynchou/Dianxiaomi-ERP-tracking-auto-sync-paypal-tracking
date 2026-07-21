<?php

namespace DxmPpcpBridge;

defined( 'ABSPATH' ) || exit;

final class Admin {
	const PAGE_SLUG = 'dxm-ppcp-bridge';
	const LIST_COLUMN = 'dxm_ppcp_bridge_status';

	/** @var Native_PayPal */
	private $native;

	/** @var Sync */
	private $sync;

	/** @var Bulk */
	private $bulk;

	/**
	 * @param Native_PayPal $native Native PayPal adapter.
	 * @param Sync          $sync   Sync service.
	 * @param Bulk          $bulk   Historical batch service.
	 */
	public function __construct( Native_PayPal $native, Sync $sync, Bulk $bulk ) {
		$this->native = $native;
		$this->sync   = $sync;
		$this->bulk   = $bulk;
	}

	/**
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 60 );
		add_action( 'admin_post_dxm_ppcp_bridge_save', array( $this, 'save_settings' ) );
		add_action( 'admin_post_dxm_ppcp_bridge_sync', array( $this, 'manual_sync' ) );
		add_action( 'admin_post_dxm_ppcp_bridge_bulk', array( $this, 'start_bulk_sync' ) );
		add_filter( 'woocommerce_order_actions', array( $this, 'add_order_action' ), 20, 2 );
		add_action( 'woocommerce_order_action_dxm_ppcp_bridge_sync', array( $this, 'run_order_action' ) );

		add_action( 'add_meta_boxes', array( $this, 'add_order_metabox' ), 30, 0 );
		add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_order_list_column' ), 20 );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_order_list_column' ), 20, 2 );
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'add_order_list_column' ), 20 );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'render_order_list_column' ), 20, 2 );
		add_filter( 'woocommerce_admin_order_actions', array( $this, 'add_order_row_action' ), 20, 2 );

		add_filter( 'bulk_actions-edit-shop_order', array( $this, 'add_order_bulk_action' ) );
		add_filter( 'handle_bulk_actions-edit-shop_order', array( $this, 'handle_order_bulk_action' ), 10, 3 );
		add_filter( 'bulk_actions-woocommerce_page_wc-orders', array( $this, 'add_order_bulk_action' ) );
		add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', array( $this, 'handle_order_bulk_action' ), 10, 3 );

		add_action( 'admin_notices', array( $this, 'render_order_admin_notice' ) );
		add_action( 'admin_head', array( $this, 'render_admin_styles' ) );
	}

	/**
	 * @return void
	 */
	public function add_menu() {
		add_submenu_page(
			'woocommerce',
			__( '店小秘 → PayPal 运单桥接', 'dianxiaomi-paypal-package-tracking-bridge' ),
			__( 'PayPal 运单桥接', 'dianxiaomi-paypal-package-tracking-bridge' ),
			'manage_woocommerce',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage WooCommerce.', 'dianxiaomi-paypal-package-tracking-bridge' ) );
		}

		$settings = Plugin::settings();
		$order_id = isset( $_GET['order_id'] ) ? absint( wp_unslash( $_GET['order_id'] ) ) : 0;
		$result   = get_transient( $this->result_transient_key() );
		if ( false !== $result ) {
			delete_transient( $this->result_transient_key() );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( '店小秘 → PayPal Package Tracking 桥接', 'dianxiaomi-paypal-package-tracking-bridge' ); ?></h1>
			<p class="description">
				<?php esc_html_e( '读取店小秘写入 WooCommerce 的运单号，并调用当前 WooCommerce PayPal Payments 已连接账户的官方 Package Tracking 功能。无需、也不会保存第二套 PayPal Client ID 或 Secret。', 'dianxiaomi-paypal-package-tracking-bridge' ); ?>
			</p>

			<?php $this->render_dependency_status(); ?>
			<?php $this->render_result( $result ); ?>

			<div style="display:grid;grid-template-columns:minmax(520px,1fr) minmax(420px,1fr);gap:20px;align-items:start;margin-top:20px;">
				<div class="postbox" style="padding:18px;">
					<h2 style="margin-top:0;"><?php esc_html_e( '自动同步设置', 'dianxiaomi-paypal-package-tracking-bridge' ); ?></h2>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="dxm_ppcp_bridge_save">
						<?php wp_nonce_field( 'dxm_ppcp_bridge_save' ); ?>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><?php esc_html_e( '启用桥接', 'dianxiaomi-paypal-package-tracking-bridge' ); ?></th>
								<td><label><input type="checkbox" name="enabled" value="yes" <?php checked( $settings['enabled'], 'yes' ); ?>> <?php esc_html_e( '允许自动及手动提交运单', 'dianxiaomi-paypal-package-tracking-bridge' ); ?></label></td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( '自动同步', 'dianxiaomi-paypal-package-tracking-bridge' ); ?></th>
								<td><label><input type="checkbox" name="auto_sync" value="yes" <?php checked( $settings['auto_sync'], 'yes' ); ?>> <?php esc_html_e( '订单完成发货后，店小秘写入单号或添加发货备注时自动处理（Processing 不同步）', 'dianxiaomi-paypal-package-tracking-bridge' ); ?></label></td>
							</tr>
							<tr>
								<th scope="row"><label for="dxm-ppcp-delay"><?php esc_html_e( '同步延迟', 'dianxiaomi-paypal-package-tracking-bridge' ); ?></label></th>
								<td><input id="dxm-ppcp-delay" class="small-text" type="number" min="5" max="300" name="delay" value="<?php echo esc_attr( (int) $settings['delay'] ); ?>"> <?php esc_html_e( '秒', 'dianxiaomi-paypal-package-tracking-bridge' ); ?><p class="description"><?php esc_html_e( '建议 20 秒，让店小秘先完成订单状态、承运商和单号的连续写入。', 'dianxiaomi-paypal-package-tracking-bridge' ); ?></p></td>
							</tr>
							<tr>
								<th scope="row"><label for="dxm-number-key"><?php esc_html_e( '运单号字段', 'dianxiaomi-paypal-package-tracking-bridge' ); ?></label></th>
								<td><input id="dxm-number-key" class="regular-text code" type="text" name="tracking_number_meta_key" value="<?php echo esc_attr( $settings['tracking_number_meta_key'] ); ?>"></td>
							</tr>
							<tr>
								<th scope="row"><label for="dxm-provider-key"><?php esc_html_e( '承运商字段', 'dianxiaomi-paypal-package-tracking-bridge' ); ?></label></th>
								<td><input id="dxm-provider-key" class="regular-text code" type="text" name="provider_meta_key" value="<?php echo esc_attr( $settings['provider_meta_key'] ); ?>"></td>
							</tr>
							<tr>
								<th scope="row"><label for="dxm-provider-name-key"><?php esc_html_e( '承运商名称字段', 'dianxiaomi-paypal-package-tracking-bridge' ); ?></label></th>
								<td><input id="dxm-provider-name-key" class="regular-text code" type="text" name="provider_name_meta_key" value="<?php echo esc_attr( $settings['provider_name_meta_key'] ); ?>"></td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( '调试日志', 'dianxiaomi-paypal-package-tracking-bridge' ); ?></th>
								<td><label><input type="checkbox" name="debug" value="yes" <?php checked( $settings['debug'], 'yes' ); ?>> <?php esc_html_e( '在 WooCommerce → 状态 → 日志中记录额外诊断信息', 'dianxiaomi-paypal-package-tracking-bridge' ); ?></label></td>
							</tr>
						</table>
						<?php submit_button( __( '保存设置', 'dianxiaomi-paypal-package-tracking-bridge' ) ); ?>
					</form>
				</div>

				<div class="postbox" style="padding:18px;">
					<h2 style="margin-top:0;"><?php esc_html_e( '订单诊断与手动同步', 'dianxiaomi-paypal-package-tracking-bridge' ); ?></h2>
					<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
						<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
						<label for="dxm-order-id"><strong><?php esc_html_e( 'WooCommerce 订单 ID', 'dianxiaomi-paypal-package-tracking-bridge' ); ?></strong></label>
						<input id="dxm-order-id" type="number" min="1" name="order_id" value="<?php echo esc_attr( $order_id ); ?>">
						<?php submit_button( __( '读取订单', 'dianxiaomi-paypal-package-tracking-bridge' ), 'secondary', 'submit', false ); ?>
					</form>

					<?php if ( $order_id ) : ?>
						<?php $this->render_order_diagnostics( $order_id ); ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:16px;">
							<input type="hidden" name="action" value="dxm_ppcp_bridge_sync">
							<input type="hidden" name="order_id" value="<?php echo esc_attr( $order_id ); ?>">
							<?php wp_nonce_field( 'dxm_ppcp_bridge_sync_' . $order_id ); ?>
							<?php submit_button( __( '强制提交/更新到 PayPal', 'dianxiaomi-paypal-package-tracking-bridge' ), 'primary', 'submit', false ); ?>
						</form>
					<?php endif; ?>
				</div>
			</div>

			<?php $this->render_bulk_tools(); ?>
		</div>
		<?php
	}

	/**
	 * @return void
	 */
	public function save_settings() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dianxiaomi-paypal-package-tracking-bridge' ) );
		}
		check_admin_referer( 'dxm_ppcp_bridge_save' );

		$defaults = Plugin::defaults();
		$settings = array(
			'enabled'                  => isset( $_POST['enabled'] ) ? 'yes' : 'no',
			'auto_sync'                => isset( $_POST['auto_sync'] ) ? 'yes' : 'no',
			'delay'                    => max( 5, min( 300, absint( $_POST['delay'] ?? 20 ) ) ),
			'tracking_number_meta_key' => $this->sanitize_meta_key_setting( $_POST['tracking_number_meta_key'] ?? '', $defaults['tracking_number_meta_key'] ),
			'provider_meta_key'        => $this->sanitize_meta_key_setting( $_POST['provider_meta_key'] ?? '', $defaults['provider_meta_key'] ),
			'provider_name_meta_key'   => $this->sanitize_meta_key_setting( $_POST['provider_name_meta_key'] ?? '', $defaults['provider_name_meta_key'] ),
			'debug'                    => isset( $_POST['debug'] ) ? 'yes' : 'no',
		);

		update_option( Plugin::OPTION_KEY, $settings, false );
		set_transient( $this->result_transient_key(), array( 'success' => true, 'message' => '设置已保存。', 'status' => 'settings_saved', 'results' => array() ), 300 );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
		exit;
	}

	/**
	 * @return void
	 */
	public function manual_sync() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dianxiaomi-paypal-package-tracking-bridge' ) );
		}

		$order_id = absint( $_REQUEST['order_id'] ?? 0 );
		check_admin_referer( 'dxm_ppcp_bridge_sync_' . $order_id );
		$result    = $this->sync->sync_order( $order_id, true );
		$return_to = sanitize_key( wp_unslash( (string) ( $_REQUEST['return_to'] ?? 'bridge' ) ) );

		if ( 'order' === $return_to ) {
			$order = wc_get_order( $order_id );
			$url   = $order instanceof \WC_Order ? $order->get_edit_order_url() : $this->orders_list_url();
			wp_safe_redirect( add_query_arg( 'dxm_ppcp_result', ! empty( $result['success'] ) ? 'success' : 'failed', $url ) );
			exit;
		}

		if ( 'orders' === $return_to ) {
			$return_url = $this->requested_orders_return_url();
			wp_safe_redirect(
				add_query_arg(
					array(
						'dxm_ppcp_result'   => ! empty( $result['success'] ) ? 'success' : 'failed',
						'dxm_ppcp_order_id' => $order_id,
					),
					remove_query_arg( array( 'dxm_ppcp_result', 'dxm_ppcp_order_id' ), $return_url )
				)
			);
			exit;
		}

		set_transient( $this->result_transient_key(), $result, 300 );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&order_id=' . $order_id ) );
		exit;
	}

	/**
	 * Start a Completed-order background scanner for the selected shipping-age window.
	 *
	 * @return void
	 */
	public function start_bulk_sync() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'dianxiaomi-paypal-package-tracking-bridge' ) );
		}

		check_admin_referer( 'dxm_ppcp_bridge_bulk' );
		$scope  = sanitize_key( wp_unslash( (string) ( $_POST['scope'] ?? '' ) ) );
		$result = $this->bulk->start( $scope );

		if ( is_wp_error( $result ) ) {
			$notice = array(
				'success' => false,
				'message' => $result->get_error_message(),
				'status'  => $result->get_error_code(),
				'results' => array(),
			);
		} else {
			$scope_labels = array(
				'days7'  => '最近 7 天',
				'days15' => '最近 15 天',
				'days30' => '最近 30 天',
				'all'    => '全部往期',
			);
			$notice = array(
				'success' => true,
				'message' => sprintf( '已开始后台扫描%s已完成发货的订单；仅同步已捕获的 PayPal 订单和已检测到的运单。', $scope_labels[ $scope ] ?? '指定范围内' ),
				'status'  => 'bulk_started',
				'results' => array(),
			);
		}

		set_transient( $this->result_transient_key(), $notice, 300 );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '#dxm-ppcp-bulk' ) );
		exit;
	}

	/**
	 * Add an optional action to the standard WooCommerce order-actions box.
	 *
	 * @param array<string,string> $actions Order actions.
	 * @param array<string,string> $actions Existing order actions.
	 * @param mixed                $order   Current WooCommerce order.
	 * @return array<string,string>
	 */
	public function add_order_action( $actions, $order = null ) {
		if ( $this->sync->is_shipped_order( $order ) ) {
			$actions['dxm_ppcp_bridge_sync'] = __( '店小秘：提交/更新 PayPal 运单', 'dianxiaomi-paypal-package-tracking-bridge' );
		}
		return $actions;
	}

	/**
	 * @param \WC_Order $order WooCommerce order.
	 * @return void
	 */
	public function run_order_action( $order ) {
		if ( $order instanceof \WC_Order && current_user_can( 'manage_woocommerce' ) ) {
			$this->sync->sync_order( $order->get_id(), true );
		}
	}

	/**
	 * Add a HPOS/legacy-compatible status and manual-sync box to order details.
	 *
	 * @return void
	 */
	public function add_order_metabox() {
		$screen           = 'shop_order';
		$controller_class = 'Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\CustomOrdersTableController';

		try {
			if ( class_exists( $controller_class ) && function_exists( 'wc_get_container' ) && wc_get_container()->get( $controller_class )->custom_orders_table_usage_is_enabled() ) {
				$screen = wc_get_page_screen_id( 'shop-order' );
			}
		} catch ( \Throwable $error ) {
			Plugin::log( 'debug', 'Could not determine the WooCommerce order editor screen.', array( 'message' => $error->getMessage() ) );
		}

		add_meta_box(
			'dxm-ppcp-bridge-order-status',
			__( 'PayPal 运单同步', 'dianxiaomi-paypal-package-tracking-bridge' ),
			array( $this, 'render_order_metabox' ),
			$screen,
			'side',
			'high'
		);
	}

	/**
	 * @param mixed $post_or_order Legacy WP_Post or HPOS WC_Order.
	 * @return void
	 */
	public function render_order_metabox( $post_or_order ) {
		$order = $this->resolve_order( $post_or_order );
		if ( ! $order instanceof \WC_Order ) {
			echo '<p>' . esc_html__( '无法读取订单。', 'dianxiaomi-paypal-package-tracking-bridge' ) . '</p>';
			return;
		}

		if ( ! $this->native->is_native_paypal_order( $order ) ) {
			echo '<p>' . esc_html__( '此订单不是已捕获的 WooCommerce PayPal Payments 订单。', 'dianxiaomi-paypal-package-tracking-bridge' ) . '</p>';
			return;
		}

		echo '<p>' . $this->status_badge_html( $order ) . '</p>';

		$last_success = (string) $order->get_meta( Sync::META_LAST_SYNCED_AT, true, 'edit' );
		$last_error   = (string) $order->get_meta( Sync::META_LAST_ERROR, true, 'edit' );
		if ( '' !== $last_success ) {
			echo '<p><strong>' . esc_html__( '上次成功：', 'dianxiaomi-paypal-package-tracking-bridge' ) . '</strong><br>' . esc_html( $this->format_datetime( $last_success ) ) . '</p>';
		}
		if ( '' !== $last_error ) {
			echo '<p class="dxm-ppcp-error"><strong>' . esc_html__( '错误：', 'dianxiaomi-paypal-package-tracking-bridge' ) . '</strong><br>' . esc_html( $last_error ) . '</p>';
		}

		$shipments = $this->sync->shipments( $order );
		if ( empty( $shipments ) ) {
			echo '<p>' . esc_html__( '尚未检测到店小秘运单号。', 'dianxiaomi-paypal-package-tracking-bridge' ) . '</p>';
		} else {
			echo '<ul class="dxm-ppcp-shipment-list">';
			foreach ( $shipments as $shipment ) {
				echo '<li><code>' . esc_html( (string) $shipment['tracking_number'] ) . '</code><br><small>' . esc_html( (string) ( $shipment['provider_name'] ?: $shipment['provider'] ?: 'Other' ) ) . '</small></li>';
			}
			echo '</ul>';
		}

		if ( $this->sync->is_shipped_order( $order ) ) {
			echo '<p><a class="button button-primary" href="' . esc_url( $this->manual_sync_url( $order, 'order' ) ) . '">' . esc_html__( '立即提交/更新到 PayPal', 'dianxiaomi-paypal-package-tracking-bridge' ) . '</a></p>';
		} else {
			echo '<p class="description">' . esc_html__( '订单仍是 Processing，尚未完成发货，因此不会提交到 PayPal。', 'dianxiaomi-paypal-package-tracking-bridge' ) . '</p>';
		}
	}

	/**
	 * @param array<string,string> $columns Existing order-list columns.
	 * @return array<string,string>
	 */
	public function add_order_list_column( $columns ) {
		$new_columns = array();
		foreach ( $columns as $key => $label ) {
			$new_columns[ $key ] = $label;
			if ( 'order_status' === $key ) {
				$new_columns[ self::LIST_COLUMN ] = __( 'PayPal 运单', 'dianxiaomi-paypal-package-tracking-bridge' );
			}
		}

		if ( ! isset( $new_columns[ self::LIST_COLUMN ] ) ) {
			$new_columns[ self::LIST_COLUMN ] = __( 'PayPal 运单', 'dianxiaomi-paypal-package-tracking-bridge' );
		}

		return $new_columns;
	}

	/**
	 * Render the custom column in legacy and HPOS order tables.
	 *
	 * @param string $column       Column key.
	 * @param mixed  $order_or_id WC_Order or order ID.
	 * @return void
	 */
	public function render_order_list_column( $column, $order_or_id ) {
		if ( self::LIST_COLUMN !== $column ) {
			return;
		}

		$order = $this->resolve_order( $order_or_id );
		if ( ! $order instanceof \WC_Order || ! $this->native->is_native_paypal_order( $order ) ) {
			echo '<span class="dxm-ppcp-badge dxm-ppcp-na">—</span>';
			return;
		}

		echo $this->status_badge_html( $order );
		if ( $this->sync->is_shipped_order( $order ) ) {
			echo '<br><a class="dxm-ppcp-list-sync" href="' . esc_url( $this->manual_sync_url( $order, 'orders' ) ) . '">' . esc_html__( '立即同步', 'dianxiaomi-paypal-package-tracking-bridge' ) . '</a>';
		}
	}

	/**
	 * Add a compact action icon to WooCommerce's Actions column.
	 *
	 * @param array<string,mixed> $actions Existing actions.
	 * @param \WC_Order           $order   Order row.
	 * @return array<string,mixed>
	 */
	public function add_order_row_action( $actions, $order ) {
		if ( $this->sync->is_shipped_order( $order ) && $this->native->is_native_paypal_order( $order ) ) {
			$actions['dxm_ppcp_bridge_sync'] = array(
				'url'    => $this->manual_sync_url( $order, 'orders' ),
				'name'   => __( '同步 PayPal 运单', 'dianxiaomi-paypal-package-tracking-bridge' ),
				'action' => 'dxm-ppcp-sync',
			);
		}

		return $actions;
	}

	/**
	 * @param array<string,string> $actions List-table bulk actions.
	 * @return array<string,string>
	 */
	public function add_order_bulk_action( $actions ) {
		$actions['dxm_ppcp_bridge_queue'] = __( '同步 PayPal 运单（店小秘）', 'dianxiaomi-paypal-package-tracking-bridge' );
		return $actions;
	}

	/**
	 * @param string           $redirect_to List-table redirect URL.
	 * @param string           $action      Selected action.
	 * @param array<int,mixed> $order_ids   Selected IDs.
	 * @return string
	 */
	public function handle_order_bulk_action( $redirect_to, $action, $order_ids ) {
		if ( 'dxm_ppcp_bridge_queue' !== $action || ! current_user_can( 'manage_woocommerce' ) ) {
			return $redirect_to;
		}

		$stats = $this->bulk->queue_selected( is_array( $order_ids ) ? $order_ids : array() );
		return add_query_arg(
			array(
				'dxm_ppcp_bulk_selected' => (int) $stats['selected'],
				'dxm_ppcp_bulk_queued'   => (int) $stats['queued'],
				'dxm_ppcp_bulk_synced'   => (int) $stats['already_synced'],
				'dxm_ppcp_bulk_skipped'  => (int) $stats['skipped'] + (int) $stats['without_tracking'] + (int) $stats['already_queued'],
			),
			$redirect_to
		);
	}

	/**
	 * Show feedback after an order-page/list manual action.
	 *
	 * @return void
	 */
	public function render_order_admin_notice() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$result = isset( $_GET['dxm_ppcp_result'] ) ? sanitize_key( wp_unslash( (string) $_GET['dxm_ppcp_result'] ) ) : '';
		if ( in_array( $result, array( 'success', 'failed' ), true ) ) {
			$order_id = isset( $_GET['dxm_ppcp_order_id'] ) ? absint( $_GET['dxm_ppcp_order_id'] ) : 0;
			$class    = 'success' === $result ? 'notice notice-success is-dismissible' : 'notice notice-error is-dismissible';
			$message  = 'success' === $result ? 'PayPal 运单同步成功。' : 'PayPal 运单同步失败，请查看该订单的同步状态和错误信息。';
			if ( $order_id ) {
				$message = sprintf( '订单 #%d：%s', $order_id, $message );
			}
			echo '<div class="' . esc_attr( $class ) . '"><p>' . esc_html( $message ) . '</p></div>';
		}

		if ( isset( $_GET['dxm_ppcp_bulk_selected'] ) ) {
			$selected = absint( $_GET['dxm_ppcp_bulk_selected'] );
			$queued   = absint( $_GET['dxm_ppcp_bulk_queued'] ?? 0 );
			$synced   = absint( $_GET['dxm_ppcp_bulk_synced'] ?? 0 );
			$skipped  = absint( $_GET['dxm_ppcp_bulk_skipped'] ?? 0 );
			$message  = sprintf( '已检查 %1$d 笔所选订单：%2$d 笔已排队，%3$d 笔此前已同步，%4$d 笔跳过。', $selected, $queued, $synced, $skipped );
			echo '<div class="notice notice-info is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
		}
	}

	/**
	 * Minimal styling for status badges and the row action icon.
	 *
	 * @return void
	 */
	public function render_admin_styles() {
		?>
		<style>
			.column-dxm_ppcp_bridge_status{width:130px}.dxm-ppcp-badge{display:inline-block;padding:3px 7px;border-radius:10px;font-size:11px;font-weight:600;line-height:1.4}.dxm-ppcp-success{color:#006b20;background:#d7f0df}.dxm-ppcp-warning{color:#7a4b00;background:#fff0c2}.dxm-ppcp-error{color:#9b1c1c;background:#f8d7da}.dxm-ppcp-neutral,.dxm-ppcp-na{color:#50575e;background:#e9eaeb}.dxm-ppcp-list-sync{font-size:11px}.dxm-ppcp-shipment-list{margin:8px 0 12px}.dxm-ppcp-shipment-list li{margin-bottom:7px}.wc-action-button-dxm-ppcp-sync::after{font-family:dashicons!important;content:"\f463"!important}
		</style>
		<?php
	}

	/**
	 * @return void
	 */
	private function render_dependency_status() {
		$version   = $this->native->plugin_version();
		$available = $this->native->is_available();
		$conflicts = Plugin::conflicting_plugins();
		?>
		<table class="widefat striped" style="max-width:980px;margin-top:18px;">
			<tbody>
				<tr><th style="width:280px;"><?php esc_html_e( 'WooCommerce PayPal Payments', 'dianxiaomi-paypal-package-tracking-bridge' ); ?></th><td><?php echo esc_html( $version ?: __( '版本未知', 'dianxiaomi-paypal-package-tracking-bridge' ) ); ?></td></tr>
				<tr><th><?php esc_html_e( '官方 Package Tracking API', 'dianxiaomi-paypal-package-tracking-bridge' ); ?></th><td><?php echo $available ? '<span style="color:#008a20;">' . esc_html__( '可用', 'dianxiaomi-paypal-package-tracking-bridge' ) . '</span>' : '<span style="color:#b32d2e;">' . esc_html__( '不可用', 'dianxiaomi-paypal-package-tracking-bridge' ) . '</span>'; ?></td></tr>
				<tr><th><?php esc_html_e( '重复同步插件', 'dianxiaomi-paypal-package-tracking-bridge' ); ?></th><td><?php echo empty( $conflicts ) ? esc_html__( '未检测到', 'dianxiaomi-paypal-package-tracking-bridge' ) : '<span style="color:#b32d2e;">' . esc_html( implode( ', ', $conflicts ) ) . '</span>'; ?></td></tr>
			</tbody>
		</table>
		<?php
	}

	/**
	 * @param mixed $result Sync/admin result.
	 * @return void
	 */
	private function render_result( $result ) {
		if ( ! is_array( $result ) || empty( $result['message'] ) ) {
			return;
		}

		$class = ! empty( $result['success'] ) ? 'notice notice-success' : 'notice notice-error';
		echo '<div class="' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( (string) $result['message'] ) . '</p>';

		if ( ! empty( $result['results'] ) && is_array( $result['results'] ) ) {
			echo '<ul style="list-style:disc;padding-left:22px;">';
			foreach ( $result['results'] as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$line = sprintf(
					'%s — %s (%s)',
					(string) ( $item['tracking_number'] ?? '' ),
					(string) ( $item['message'] ?? '' ),
					(string) ( $item['carrier_code'] ?? 'OTHER' )
				);
				echo '<li>' . esc_html( $line ) . '</li>';
			}
			echo '</ul>';
		}

		echo '</div>';
	}

	/**
	 * @param int $order_id WooCommerce order ID.
	 * @return void
	 */
	private function render_order_diagnostics( $order_id ) {
		$data = $this->sync->inspect_order( $order_id );
		if ( is_wp_error( $data ) ) {
			echo '<p style="color:#b32d2e;">' . esc_html( $data->get_error_message() ) . '</p>';
			return;
		}

		$this->render_persistent_sync_status( $data );

		$rows = array(
			__( '订单', 'dianxiaomi-paypal-package-tracking-bridge' )              => '#' . $data['order_number'] . ' (' . $data['order_status'] . ')',
			__( '付款方式', 'dianxiaomi-paypal-package-tracking-bridge' )          => $data['payment_title'] . ' [' . $data['payment_method'] . ']',
			__( 'PayPal Order ID', 'dianxiaomi-paypal-package-tracking-bridge' )   => $data['paypal_order_id'] ?: '—',
			__( 'Capture ID', 'dianxiaomi-paypal-package-tracking-bridge' )        => $data['capture_id'] ?: '—',
			__( '桥接状态', 'dianxiaomi-paypal-package-tracking-bridge' )          => $this->status_label( (string) $data['sync_status'] ),
			__( '上次成功', 'dianxiaomi-paypal-package-tracking-bridge' )          => $this->format_datetime( (string) $data['last_synced_at'] ),
			__( '上次错误', 'dianxiaomi-paypal-package-tracking-bridge' )          => $data['last_error'] ?: '—',
		);

		echo '<table class="widefat striped" style="margin-top:14px;"><tbody>';
		foreach ( $rows as $label => $value ) {
			echo '<tr><th style="width:130px;">' . esc_html( $label ) . '</th><td><code style="word-break:break-all;">' . esc_html( (string) $value ) . '</code></td></tr>';
		}
		echo '</tbody></table>';

		echo '<h3>' . esc_html__( '检测到的运单', 'dianxiaomi-paypal-package-tracking-bridge' ) . '</h3>';
		if ( empty( $data['shipments'] ) ) {
			echo '<p>' . esc_html__( '尚未检测到店小秘运单号。', 'dianxiaomi-paypal-package-tracking-bridge' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( '单号', 'dianxiaomi-paypal-package-tracking-bridge' ) . '</th><th>' . esc_html__( '店小秘承运商', 'dianxiaomi-paypal-package-tracking-bridge' ) . '</th><th>' . esc_html__( 'PayPal 承运商', 'dianxiaomi-paypal-package-tracking-bridge' ) . '</th><th>' . esc_html__( '来源', 'dianxiaomi-paypal-package-tracking-bridge' ) . '</th></tr></thead><tbody>';
		foreach ( $data['shipments'] as $shipment ) {
			$carrier = $shipment['paypal_carrier'];
			echo '<tr><td><code>' . esc_html( $shipment['tracking_number'] ) . '</code></td><td>' . esc_html( $shipment['provider_name'] ?: $shipment['provider'] ?: 'Other' ) . '</td><td><code>' . esc_html( $carrier['code'] ) . '</code> ' . esc_html( $carrier['name'] ) . '</td><td>' . esc_html( $shipment['source'] ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Keep the latest order-level result visible after the one-time redirect
	 * notice has been dismissed or the page is refreshed again.
	 *
	 * @param array<string,mixed> $data Order diagnostics.
	 * @return void
	 */
	private function render_persistent_sync_status( array $data ) {
		$status = (string) ( $data['sync_status'] ?? '' );
		if ( '' === $status ) {
			return;
		}

		$class   = 'notice notice-info inline';
		$message = '';

		switch ( $status ) {
			case 'synced':
				$class   = 'notice notice-success inline';
				$message = __( '同步成功：PayPal 已接受该订单的运单信息。', 'dianxiaomi-paypal-package-tracking-bridge' );
				if ( ! empty( $data['last_synced_at'] ) ) {
					$message .= ' ' . sprintf(
						/* translators: %s: localized successful sync time. */
						__( '最后成功时间：%s', 'dianxiaomi-paypal-package-tracking-bridge' ),
						$this->format_datetime( (string) $data['last_synced_at'] )
					);
				}
				break;

			case 'partial':
				$class   = 'notice notice-warning inline';
				$message = __( '部分成功：至少一个运单已提交，但仍有运单失败。', 'dianxiaomi-paypal-package-tracking-bridge' );
				break;

			case 'failed':
				$class   = 'notice notice-error inline';
				$message = __( '同步失败。', 'dianxiaomi-paypal-package-tracking-bridge' );
				break;

			case 'waiting':
				$class   = 'notice notice-warning inline';
				$message = __( '尚未提交：正在等待店小秘运单号或 PayPal Capture。', 'dianxiaomi-paypal-package-tracking-bridge' );
				break;

			case 'syncing':
				$message = __( '正在同步，请稍后刷新。', 'dianxiaomi-paypal-package-tracking-bridge' );
				break;

			default:
				$class   = 'notice notice-error inline';
				$message = __( '同步未完成。', 'dianxiaomi-paypal-package-tracking-bridge' );
				break;
		}

		if ( 'synced' !== $status && ! empty( $data['last_error'] ) ) {
			$message .= ' ' . (string) $data['last_error'];
		}

		echo '<div class="' . esc_attr( $class ) . '" style="margin:14px 0 10px;"><p><strong>' . esc_html( $message ) . '</strong></p></div>';
	}

	/**
	 * @param string $status Stored status code.
	 * @return string
	 */
	private function status_label( $status ) {
		$labels = array(
			'synced'  => __( '成功（synced）', 'dianxiaomi-paypal-package-tracking-bridge' ),
			'partial' => __( '部分成功（partial）', 'dianxiaomi-paypal-package-tracking-bridge' ),
			'failed'  => __( '失败（failed）', 'dianxiaomi-paypal-package-tracking-bridge' ),
			'waiting' => __( '等待中（waiting）', 'dianxiaomi-paypal-package-tracking-bridge' ),
			'syncing' => __( '同步中（syncing）', 'dianxiaomi-paypal-package-tracking-bridge' ),
		);

		return isset( $labels[ $status ] ) ? $labels[ $status ] : ( $status ?: '—' );
	}

	/**
	 * Display stored UTC timestamps in the WordPress site timezone.
	 *
	 * @param string $value ISO-8601 timestamp.
	 * @return string
	 */
	private function format_datetime( $value ) {
		if ( '' === $value ) {
			return '—';
		}

		$timestamp = strtotime( $value );
		return false === $timestamp ? $value : wp_date( 'Y-m-d H:i:s T', $timestamp );
	}

	/**
	 * Render background tools and the latest scanner progress.
	 *
	 * @return void
	 */
	private function render_bulk_tools() {
		$job           = $this->bulk->job();
		$scope_buttons = array(
			'days7'  => __( '同步最近 7 天已发货订单', 'dianxiaomi-paypal-package-tracking-bridge' ),
			'days15' => __( '同步最近 15 天已发货订单', 'dianxiaomi-paypal-package-tracking-bridge' ),
			'days30' => __( '同步最近 30 天已发货订单', 'dianxiaomi-paypal-package-tracking-bridge' ),
			'all'    => __( '扫描全部往期已发货订单', 'dianxiaomi-paypal-package-tracking-bridge' ),
		);
		?>
		<div id="dxm-ppcp-bulk" class="postbox" style="padding:18px;margin-top:20px;max-width:1180px;">
			<h2 style="margin-top:0;"><?php esc_html_e( '往期订单批量同步', 'dianxiaomi-paypal-package-tracking-bridge' ); ?></h2>
			<p><?php esc_html_e( '按订单完成/发货时间扫描，只读取 Completed，完全排除 Processing；仅对具有 PayPal Order ID、Capture ID 和运单号且尚未同步的订单排队。', 'dianxiaomi-paypal-package-tracking-bridge' ); ?></p>

			<div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;">
				<?php foreach ( $scope_buttons as $scope => $button_label ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="dxm_ppcp_bridge_bulk">
						<input type="hidden" name="scope" value="<?php echo esc_attr( $scope ); ?>">
						<?php wp_nonce_field( 'dxm_ppcp_bridge_bulk' ); ?>
						<?php
						$attributes = 'all' === $scope ? array( 'onclick' => "return window.confirm('将分批扫描全部往期 Completed 订单，并仅为已捕获的 PayPal 发货订单建立后台任务。确定继续吗？');" ) : array();
						submit_button( $button_label, 'days7' === $scope ? 'primary' : 'secondary', 'submit', false, $attributes );
						?>
					</form>
				<?php endforeach; ?>

				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '#dxm-ppcp-bulk' ) ); ?>"><?php esc_html_e( '刷新进度', 'dianxiaomi-paypal-package-tracking-bridge' ); ?></a>
				<a class="button" href="<?php echo esc_url( $this->orders_list_url() ); ?>"><?php esc_html_e( '打开订单列表', 'dianxiaomi-paypal-package-tracking-bridge' ); ?></a>
			</div>

			<?php if ( ! empty( $job ) ) : ?>
				<?php
				$status_labels = array(
					'queued'        => '等待扫描',
					'scanning'      => '正在扫描',
					'scan_complete' => '扫描完成，订单同步任务已排队',
					'failed'        => '扫描失败',
				);
				$scope_labels = array(
					'days7'   => '最近 7 天已发货订单',
					'days15'  => '最近 15 天已发货订单',
					'days30'  => '最近 30 天已发货订单',
					'all'     => '全部往期已发货订单',
					'recent20' => '升级前任务（按最近 30 天已发货订单处理）',
				);
				$scope_label = $scope_labels[ (string) ( $job['scope'] ?? '' ) ] ?? '指定范围';
				$status      = (string) ( $job['status'] ?? '' );
				?>
				<table class="widefat striped" style="margin-top:16px;max-width:980px;">
					<tbody>
						<tr><th style="width:220px;"><?php esc_html_e( '扫描范围', 'dianxiaomi-paypal-package-tracking-bridge' ); ?></th><td><?php echo esc_html( $scope_label ); ?></td></tr>
						<tr><th><?php esc_html_e( '后台状态', 'dianxiaomi-paypal-package-tracking-bridge' ); ?></th><td><?php echo esc_html( ( $status_labels[ $status ] ?? $status ) ?: '—' ); ?></td></tr>
						<tr><th><?php esc_html_e( '扫描页数', 'dianxiaomi-paypal-package-tracking-bridge' ); ?></th><td><?php echo esc_html( (int) ( $job['page'] ?? 0 ) . ' / ' . (int) ( $job['total_pages'] ?? 0 ) ); ?></td></tr>
						<tr><th><?php esc_html_e( '范围内 Completed 订单', 'dianxiaomi-paypal-package-tracking-bridge' ); ?></th><td><?php echo esc_html( (int) ( $job['scanned'] ?? 0 ) ); ?></td></tr>
						<tr><th><?php esc_html_e( '已捕获 PayPal 订单', 'dianxiaomi-paypal-package-tracking-bridge' ); ?></th><td><?php echo esc_html( (int) ( $job['paypal_orders'] ?? 0 ) ); ?></td></tr>
						<tr><th><?php esc_html_e( '符合条件且有运单', 'dianxiaomi-paypal-package-tracking-bridge' ); ?></th><td><?php echo esc_html( (int) ( $job['with_tracking'] ?? 0 ) ); ?></td></tr>
						<tr><th><?php esc_html_e( '新建同步任务', 'dianxiaomi-paypal-package-tracking-bridge' ); ?></th><td><?php echo esc_html( (int) ( $job['queued_orders'] ?? 0 ) ); ?></td></tr>
						<tr><th><?php esc_html_e( '此前已同步', 'dianxiaomi-paypal-package-tracking-bridge' ); ?></th><td><?php echo esc_html( (int) ( $job['already_synced'] ?? 0 ) ); ?></td></tr>
						<tr><th><?php esc_html_e( '无运单号', 'dianxiaomi-paypal-package-tracking-bridge' ); ?></th><td><?php echo esc_html( (int) ( $job['without_tracking'] ?? 0 ) ); ?></td></tr>
						<tr><th><?php esc_html_e( '开始时间', 'dianxiaomi-paypal-package-tracking-bridge' ); ?></th><td><?php echo esc_html( $this->format_datetime( (string) ( $job['started_at'] ?? '' ) ) ); ?></td></tr>
						<tr><th><?php esc_html_e( '最近活动', 'dianxiaomi-paypal-package-tracking-bridge' ); ?></th><td><?php echo esc_html( $this->format_datetime( (string) ( $job['updated_at'] ?? '' ) ) ); ?></td></tr>
						<?php if ( ! empty( $job['last_error'] ) ) : ?><tr><th><?php esc_html_e( '扫描错误', 'dianxiaomi-paypal-package-tracking-bridge' ); ?></th><td style="color:#b32d2e;"><?php echo esc_html( (string) $job['last_error'] ); ?></td></tr><?php endif; ?>
					</tbody>
				</table>
				<p class="description"><?php esc_html_e( '“扫描完成”表示所有符合条件的订单已排入后台；每笔订单的最终结果请在订单列表的“PayPal 运单”列查看。', 'dianxiaomi-paypal-package-tracking-bridge' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * @param \WC_Order $order WooCommerce order.
	 * @return string Escaped status-badge HTML.
	 */
	private function status_badge_html( $order ) {
		if ( ! $this->sync->is_shipped_order( $order ) ) {
			return '<span class="dxm-ppcp-badge dxm-ppcp-neutral" title="Processing orders are excluded">' . esc_html__( '未发货', 'dianxiaomi-paypal-package-tracking-bridge' ) . '</span>';
		}

		$status = (string) $order->get_meta( Sync::META_STATUS, true, 'edit' );
		$error  = (string) $order->get_meta( Sync::META_LAST_ERROR, true, 'edit' );
		$label  = __( '未同步', 'dianxiaomi-paypal-package-tracking-bridge' );
		$class  = 'dxm-ppcp-neutral';

		switch ( $status ) {
			case 'synced':
				$label = __( '已同步', 'dianxiaomi-paypal-package-tracking-bridge' );
				$class = 'dxm-ppcp-success';
				break;
			case 'partial':
				$label = __( '部分成功', 'dianxiaomi-paypal-package-tracking-bridge' );
				$class = 'dxm-ppcp-warning';
				break;
			case 'waiting':
				$label = __( '等待中', 'dianxiaomi-paypal-package-tracking-bridge' );
				$class = 'dxm-ppcp-warning';
				break;
			case 'syncing':
				$label = __( '同步中', 'dianxiaomi-paypal-package-tracking-bridge' );
				$class = 'dxm-ppcp-warning';
				break;
			case 'failed':
				$label = __( '失败', 'dianxiaomi-paypal-package-tracking-bridge' );
				$class = 'dxm-ppcp-error';
				break;
			default:
				if ( '' !== $status ) {
					$label = __( '未完成', 'dianxiaomi-paypal-package-tracking-bridge' );
					$class = 'dxm-ppcp-error';
				}
				break;
		}

		return '<span class="dxm-ppcp-badge ' . esc_attr( $class ) . '" title="' . esc_attr( $error ) . '">' . esc_html( $label ) . '</span>';
	}

	/**
	 * @param \WC_Order $order     WooCommerce order.
	 * @param string    $return_to bridge, order, or orders.
	 * @return string
	 */
	private function manual_sync_url( $order, $return_to ) {
		$args = array(
			'action'    => 'dxm_ppcp_bridge_sync',
			'order_id'  => $order->get_id(),
			'return_to' => sanitize_key( (string) $return_to ),
		);
		if ( 'orders' === $return_to ) {
			$args['redirect_to'] = $this->current_orders_list_url();
		}

		$url = add_query_arg(
			$args,
			admin_url( 'admin-post.php' )
		);

		return wp_nonce_url( $url, 'dxm_ppcp_bridge_sync_' . $order->get_id() );
	}

	/**
	 * Preserve the current HPOS/legacy list page, search, status, and filters.
	 *
	 * @return string
	 */
	private function current_orders_list_url() {
		$args = isset( $_GET ) && is_array( $_GET ) ? wc_clean( wp_unslash( $_GET ) ) : array();
		foreach ( array_keys( $args ) as $key ) {
			if ( in_array( (string) $key, array( 'action', 'action2', '_wpnonce', '_wp_http_referer', 'order_id', 'return_to', 'redirect_to' ), true ) || 0 === strpos( (string) $key, 'dxm_ppcp_' ) ) {
				unset( $args[ $key ] );
			}
		}

		return add_query_arg( $args, $this->orders_list_url() );
	}

	/**
	 * Accept only a return URL inside this site's WordPress admin.
	 *
	 * @return string
	 */
	private function requested_orders_return_url() {
		$fallback  = $this->orders_list_url();
		$requested = isset( $_REQUEST['redirect_to'] ) ? esc_url_raw( wp_unslash( (string) $_REQUEST['redirect_to'] ) ) : '';
		$validated = '' !== $requested ? wp_validate_redirect( $requested, $fallback ) : $fallback;

		return 0 === strpos( $validated, admin_url() ) ? $validated : $fallback;
	}

	/**
	 * @param mixed $value WC_Order, WP_Post, or order ID.
	 * @return \WC_Order|false
	 */
	private function resolve_order( $value ) {
		if ( $value instanceof \WC_Order ) {
			return $value;
		}

		if ( is_object( $value ) && isset( $value->ID ) ) {
			return wc_get_order( (int) $value->ID );
		}

		return wc_get_order( (int) $value );
	}

	/**
	 * @return string
	 */
	private function orders_list_url() {
		$controller_class = 'Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\CustomOrdersTableController';

		try {
			if ( class_exists( $controller_class ) && function_exists( 'wc_get_container' ) && wc_get_container()->get( $controller_class )->custom_orders_table_usage_is_enabled() ) {
				return admin_url( 'admin.php?page=wc-orders' );
			}
		} catch ( \Throwable $error ) {
			Plugin::log( 'debug', 'Could not determine the WooCommerce orders-list URL.', array( 'message' => $error->getMessage() ) );
		}

		return admin_url( 'edit.php?post_type=shop_order' );
	}

	/**
	 * @param mixed  $value    Submitted meta key.
	 * @param string $fallback Default meta key.
	 * @return string
	 */
	private function sanitize_meta_key_setting( $value, $fallback ) {
		$value = sanitize_key( wp_unslash( (string) $value ) );
		return '' !== $value ? $value : (string) $fallback;
	}

	/**
	 * @return string
	 */
	private function result_transient_key() {
		return 'dxm_ppcp_bridge_admin_result_' . get_current_user_id();
	}
}
