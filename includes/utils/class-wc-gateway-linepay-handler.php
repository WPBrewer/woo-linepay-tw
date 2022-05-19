<?php
/**
 *
 * WC_Gateway_LINEPay_Handler
 * 1. woocommerce WC_Gateway_LINEPay register
 * 2. Added to be refundable on the User Account tab
 * 3. Handle callback requests
 *
 * @class WC_Gateway_LINEPay_Handler
 * @version 1.0.0
 * @author LINEPay
 */
class WC_Gateway_LINEPay_Handler {

	/**
	 * The logger object
	 *
	 * @var WC_Gateway_LINEpay_Logger
	 */
	private static $logger;

	/**
	 * Constructor function
	 */
	public function __construct() {
		add_action( 'plugins_loaded', array( $this, 'init_wc_gateway_linepay_handler' ) );
	}

	/**
	 * WC_Gateway_LINEPay_Handler Initialize
	 */
	public function init_wc_gateway_linepay_handler() {

		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			return;
		}

		include_once plugin_dir_path( __FILE__ ) . 'includes/class-wc-gateway-linepay-const.php';
		include_once plugin_dir_path( __FILE__ ) . 'includes/class-wc-gateway-linepay-logger.php';
		include_once plugin_dir_path( __FILE__ ) . 'includes/class-wc-gateway-linepay.php';


		add_filter( 'woocommerce_my_account_my_orders_title', array( $this, 'append_script_for_refund_action' ) );
		add_filter( 'woocommerce_my_account_my_orders_actions', array( $this, 'change_customer_order_action' ), 10, 2 );


		// linepay setting.
		$this->linepay_settings = get_option( 'woocommerce_' . WC_Gateway_LINEPay_Const::ID . '_settings' );

		// logger.
		$linepay_log_info = array(
			'enabled' => wc_string_to_bool( $this->linepay_settings['log_enabled'] ),
			'level'   => ( '' !== $this->linepay_settings['log_enabled'] ) ? $this->linepay_settings['log_enabled'] : WC_Gateway_LINEPay_Logger::LOG_LEVEL_NONE,
		);

		// static::$logger = WC_Gateway_LINEPay_Logger::get_instance( $linepay_log_info );
	}

	/**
	 * Process the user's refund request.
	 *
	 * If the user requests a refund, WC_AJAX::refund_line_items() is executed when the administrator requests.
	 * Since it cannot be called first, define a new method to handle it.
	 *
	 * Create a refund order to return the quantity, total amount, tax, and request a refund.
	 *
	 * @see	WC_AJAX::refund_line_items()
	 * @param WC_Gateway_LINEPay $linepay_gateway
	 * @param int $order_id
	 * @throws Exception
	 */
	public function process_refund_by_customer( $linepay_gateway, $order_id ) {
		$order         = wc_get_order( $order_id );
		$refund_amount = wc_format_decimal( sanitize_text_field( wp_unslash( $_GET['cancel_amount'] ) ) );
		$refund_reason = sanitize_text_field( wp_unslash( $_GET['reason'] ) );


		$line_items       = array();
		$items            = $order->get_items();
		$shipping_methods = $order->get_shipping_methods();

		// items.
		foreach ( $items as $item_id => $item ) {
			$line_tax_data          = unserialize( $item['line_tax_data'] );
			$line_item              = array(
				'qty'          => $item['qty'],
				'refund_total' => wc_format_decimal( $item['line_total'] ),
				'refund_tax'   => $line_tax_data['total'],
			);
			$line_items[ $item_id ] = $line_item;
		}

		// shipping.
		foreach ( $shipping_methods as $shipping_id => $shipping ) {
			$line_item                  = array(
				'refund_total' => wc_format_decimal( $shipping['cost'] ),
				'refund_tax'   => unserialize( $shipping['taxes'] ),
			);
			$line_items[ $shipping_id ] = $line_item;
		}

		try {
			$refund = wc_create_refund(
				array(
					'amount'     => $refund_amount,
					'reason'     => $refund_reason,
					'order_id'   => $order_id,
					'line_items' => $line_items,
				)
			);

			if ( is_wp_error( $refund ) ) {
				throw new Exception( $refund->get_error_message() );
			}

			// Refund processing
			$result = $linepay_gateway->process_refund_request( WC_Gateway_LINEPay_Const::USER_STATUS_CUSTOMER, $order_id, $refund_amount, $refund_reason );

			if ( is_wp_error( $result ) || ! $result ) {
				static::$logger->error( 'process_refund_request_by_customer', $result );

				throw new Exception( $result->get_error_message() );
			}

			// Item quantity return.
			foreach ( $items as $item_id => $item ) {
				$qty      = $item['qty'];
				$_product = $order->get_product_from_item( $item );

				if ( $_product && $_product->exists() && $_product->managing_stock() ) {
					$old_stock = wc_stock_amount( $_product->stock );

					$new_quantity = wc_update_product_stock( $_product, $qty, 'increase', true );

					$order->add_order_note( sprintf( __( 'Item #%s stock increased from %s to %s.', 'woocommerce' ), $order_item['product_id'], $old_stock, $new_quantity ) );

					do_action( 'woocommerce_restock_refunded_item', $_product->id, $old_stock, $new_quantity, $order );
				}
			}

			wc_delete_shop_order_transients( $order_id );

			wc_add_notice( __( 'Refund complete.', 'woocommerce_gateway_linepay' ) );
			wp_send_json_success( array( 'info' => 'fully_refunded' ) );

		} catch ( Exception $e ) {

			if ( $refund && is_a( $refund, 'WC_Order_Refund' ) ) {
				wp_delete_post( $refund->id, true );
			}

			wc_add_wp_error_notices( new WP_Error( 'process_refund_by_customer', __( 'Unable to process refund. Please try again.', 'woocommerce_gateway_linepay' ) ) );
			wp_send_json_error( array( 'info' => $e->getMessage() ) );
		}

	}

	/**
	 * Register a script file to help consumers with refund processing and a script to contain language information to be used internally.
	 * I used the woocommerce_my_account_my_orders_title filter to register only the first time when my account is loaded.
	 * Therefore, no change is made to the title.
	 *
	 * @see woocommerce::filter - woocommerce_my_account_my_orders_title
	 * @param String $title
	 * @return String
	 */
	public function append_script_for_refund_action( $title ) {

		// Registration of consumer refund processing script.
		wp_register_script( 'wc-gateway-linepay-customer-refund-action', untrailingslashit( plugins_url( '/', __FILE__ ) ) . WC_Gateway_LINEPay_Const::RESOURCE_JS_CUSTOMER_REFUND_ACTION );
		wp_enqueue_script( 'wc-gateway-linepay-customer-refund-action' );

		// Register language information to be used in script.
		$lang_process_refund = __( 'Processing refund...', 'woocommerce-gateway-linepay' );
		$lang_request_refund = __( 'Request refund for order {order_id}', 'woocommerce-gateway-linepay' );
		$lang_cancel         = __( 'Cancel', 'woocommerce-gateway-linepay' );

		$lang_script = '<script>
					function linepay_lang_pack() {
						return { \'process_refund\': \'' . $lang_process_refund . '\',
								\'request_refund\':\'' . $lang_request_refund . '\',
								\'cancel\':\'' . $lang_cancel . '\'
							};
					}
				</script>';
		echo $lang_script;

		return $title;
	}


	/**
	 *
	 * Add a user's refund action for each order in my account.
	 * You can change the user's refund status in the admin setting.
	 * Actions that can be re-purchased or canceled when Linepay payment fails.
	 *
	 * @see woocommerce:filter - woocommerce_my_account_my_orders_actions
	 * @param array $actions
	 * @param WC_Order $order
	 * @return array
	 */
	public function change_customer_order_action( $actions, $order ) {
		$order_status = $order->get_status();

		switch ( $order_status ) {
			case 'failed':
				$payment_method = get_post_meta( $order->id, '_payment_method' );
				if ( WC_Gateway_LINEPay_Const::ID !== $payment_method[0] ) {
					break;
				}

				unset( $actions['pay'] );
				unset( $actions['cancel'] );

				break;
		}

		if ( in_array( 'wc-' . $order_status, $this->linepay_settings['customer_refund'] ) ) {
			$actions['cancel'] = array(
				'url'  => esc_url_raw( add_query_arg(
					array(
						'request_type'  => WC_Gateway_LINEPay_Const::REQUEST_TYPE_REFUND,
						'order_id'      => $order->order_id,
						'cancel_amount' => $order->get_total(),
					),
					home_url( WC_Gateway_LINEPay_Const::URI_CALLBACK_HANDLER )
				)
				),
				'name' => __( 'Cancel', 'woocommerce-gateway-linepay' ),
			);
		}

		return $actions;
	}
}