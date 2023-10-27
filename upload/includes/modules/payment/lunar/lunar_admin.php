<?php
/**
 *  Zencart Admin Configuration
 *  Copyright (c) 2022 Lunar
 */

class lunar_admin {
	/**
	 * install lunar payment model
	 *
	 * @global type $db
	 */
	public function install() {
		global $db;

		$db->Execute( "INSERT INTO " . TABLE_CONFIGURATION .
		              " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added)
		              VALUES ('" . LUNAR_ADMIN_ENABLE_TITLE . "', 'MODULE_PAYMENT_LUNAR_STATUS', 'True', '" . LUNAR_ADMIN_ENABLE_DESCRIPTION . "', '6', '1', 'zen_cfg_select_option(array(\'True\', \'False\'), ', now());" );
		$db->Execute( "INSERT INTO " . TABLE_CONFIGURATION .
					" (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added)
					VALUES ('" . LUNAR_ADMIN_APP_KEY_TITLE . "', 'MODULE_PAYMENT_LUNAR_APP_KEY', '', '" . LUNAR_ADMIN_APP_KEY_DESCRIPTION . "', '6', '2', now())" );
		$db->Execute( "INSERT INTO " . TABLE_CONFIGURATION .
					" (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added)
					VALUES ('" . LUNAR_ADMIN_PUBLIC_KEY_TITLE . "', 'MODULE_PAYMENT_LUNAR_PUBLIC_KEY', '', '" . LUNAR_ADMIN_PUBLIC_KEY_DESCRIPTION . "', '6', '3', now())" );
		$db->Execute( "INSERT INTO " . TABLE_CONFIGURATION .
		              " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added)
		              VALUES ('" . LUNAR_ADMIN_METHOD_TITLE_TITLE . "', 'MODULE_PAYMENT_LUNAR_TEXT_TITLE', '" . LUNAR_ADMIN_METHOD_TITLE_VALUE . "', '" . LUNAR_ADMIN_METHOD_TITLE_DESCRIPTION . "', '6', '4', now())" );
		$db->Execute( "INSERT INTO " . TABLE_CONFIGURATION .
		              " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added)
		              VALUES ('" . LUNAR_ADMIN_METHOD_DESCRIPTION_TITLE . "', 'MODULE_PAYMENT_LUNAR_TEXT_DESCRIPTION', '" . LUNAR_ADMIN_METHOD_DESCRIPTION_VALUE . "', '" . LUNAR_ADMIN_METHOD_DESCRIPTION_DESCRIPTION . "', '6', '5', now())" );
		$db->Execute( "INSERT INTO " . TABLE_CONFIGURATION .
		              " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added)
		              VALUES ('" . LUNAR_ADMIN_CAPTURE_MODE_TITLE . "', 'MODULE_PAYMENT_LUNAR_CAPTURE_MODE', '" . LUNAR_ADMIN_CAPTURE_MODE_INSTANT . "', '" . LUNAR_ADMIN_CAPTURE_MODE_DESCRIPTION . "', '6', '6', 'zen_cfg_select_option(array(\'" . LUNAR_ADMIN_CAPTURE_MODE_INSTANT . "\', \'" . LUNAR_ADMIN_CAPTURE_MODE_DELAYED . "\'), ', now())" );
		$db->Execute( "INSERT INTO " . TABLE_CONFIGURATION .
		              " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added)
		             VALUES ('" . LUNAR_ADMIN_SHOP_TITLE . "', 'MODULE_PAYMENT_LUNAR_SHOP_TITLE', '" . (defined( 'STORE_NAME' ) ? STORE_NAME : '') . "', '" . LUNAR_ADMIN_SHOP_DESCRIPTION . "', '6', '7', now())" );
		$db->Execute( "INSERT INTO " . TABLE_CONFIGURATION .
		              " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, use_function, set_function, date_added)
		              VALUES ('" . LUNAR_ADMIN_PAYMENT_ZONE_TITLE . "', 'MODULE_PAYMENT_LUNAR_ZONE', '0', '" . LUNAR_ADMIN_PAYMENT_ZONE_DESCRIPTION . "', '6', '8', 'zen_get_zone_class_title', 'zen_cfg_pull_down_zone_classes(', now())" );
		$db->Execute( "INSERT INTO " . TABLE_CONFIGURATION .
		              " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, use_function, date_added)
		               VALUES ('" . LUNAR_ADMIN_CAPTURE_STATUS_TITLE . "', 'MODULE_PAYMENT_LUNAR_CAPTURE_ORDER_STATUS_ID', '2', '" . LUNAR_ADMIN_CAPTURE_STATUS_DESCRIPTION . "', '6', '9', 'zen_cfg_pull_down_order_statuses(', 'zen_get_order_status_name', now())" );
		$db->Execute( "INSERT INTO " . TABLE_CONFIGURATION .
		              " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, use_function, date_added)
		               VALUES ('" . LUNAR_ADMIN_REFUND_STATUS_TITLE . "', 'MODULE_PAYMENT_LUNAR_REFUND_ORDER_STATUS_ID', '4', '" . LUNAR_ADMIN_REFUND_STATUS_DESCRIPTION . "', '6', '10', 'zen_cfg_pull_down_order_statuses(', 'zen_get_order_status_name', now())" );
		$db->Execute( "INSERT INTO " . TABLE_CONFIGURATION .
		              " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, use_function, date_added)
		               VALUES ('" . LUNAR_ADMIN_CANCEL_STATUS_TITLE . "', 'MODULE_PAYMENT_LUNAR_VOID_ORDER_STATUS_ID', '4', '" . LUNAR_ADMIN_CANCEL_STATUS_DESCRIPTION . "', '6', '11', 'zen_cfg_pull_down_order_statuses(', 'zen_get_order_status_name', now())" );
		$db->Execute( "INSERT INTO " . TABLE_CONFIGURATION .
		              " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added)
		               VALUES ('" . LUNAR_ADMIN_SORT_ORDER_TITLE . "', 'MODULE_PAYMENT_LUNAR_SORT_ORDER', '0', '" . LUNAR_ADMIN_SORT_ORDER_DESCRIPTION . "', '6', '12', now())" );
	}

	/**
	 * remove module
	 *
	 * @global type $db
	 */
	public function remove() {
		global $db;
		$db->Execute( "delete from " . TABLE_CONFIGURATION . " where configuration_key LIKE 'MODULE\_PAYMENT\_LUNAR\_%'" );
	}

	/**
	 * install lunar payment model
	 *
	 */
	public function create_table_lunar() {
		global $db;
		$db->Execute( "create table lunar (id INT NOT NULL AUTO_INCREMENT, customer_id INT NOT NULL DEFAULT 0, order_id INT NOT NULL DEFAULT 0,
 authorization_type VARCHAR(50) NOT NULL, transaction_status ENUM('authorize', 'capture', 'partial_refund', 'refund', 'void') DEFAULT 'authorize',
  transaction_id VARCHAR(100) NOT NULL, time VARCHAR(50) NOT NULL, session_id VARCHAR(255) NOT NULL, PRIMARY KEY (id) );" );
	}

	/**
	 * keys
	 *
	 * @return array
	 */
	public function keys() {
		return array(
			'MODULE_PAYMENT_LUNAR_STATUS',
			'MODULE_PAYMENT_LUNAR_APP_KEY',
			'MODULE_PAYMENT_LUNAR_PUBLIC_KEY',
			'MODULE_PAYMENT_LUNAR_TEXT_TITLE',
			'MODULE_PAYMENT_LUNAR_TEXT_DESCRIPTION',
			'MODULE_PAYMENT_LUNAR_SHOP_TITLE',
			'MODULE_PAYMENT_LUNAR_CAPTURE_MODE',
			'MODULE_PAYMENT_LUNAR_ACCEPTED_CARDS',
			'MODULE_PAYMENT_LUNAR_ZONE',
			'MODULE_PAYMENT_LUNAR_CAPTURE_ORDER_STATUS_ID',
			'MODULE_PAYMENT_LUNAR_REFUND_ORDER_STATUS_ID',
			'MODULE_PAYMENT_LUNAR_VOID_ORDER_STATUS_ID',
			'MODULE_PAYMENT_LUNAR_SORT_ORDER'
		);
	}


	/**
	 * Used to read details of an existing transaction.
	 *
	 * @param $app_id
	 * @param $transaction_id
	 *
	 * @return array
	 */
	public function getTransactionHistory( $app_id, $transaction_id ) {
		try {
			// transaction history
			$api_client  = new Paylike\Paylike( $app_id );
			$lunar_history = $api_client->transactions()->fetch( $transaction_id );
			if ( $lunar_history['successful'] ) {
				return $lunar_history;
			}

			$error = LUNAR_COMMENT_TRANSACTION_FETCH_ISSUE . $transaction_id;
			lunar_debug( $error, __LINE__, __FILE__ );

		} catch ( \Paylike\Exception\ApiException $exception ) {
			$error = LUNAR_COMMENT_TRANSACTION_FETCH_ISSUE . $transaction_id;
			$this->recordError( $exception, __LINE__, __FILE__, null, $error );
		}

		return array();
	}

	/**
	 * Used to capture part or all of a given previously-authorized transaction.
	 *
	 * @param      $app_id
	 * @param      $order_id
	 * @param      $captureType
	 * @param      $amount
	 * @param      $currency
	 * @param      $note
	 *
	 * @param bool $silent
	 *
	 * @return bool
	 */
	function capture( $app_id, $order_id, $captureType, $amount, $currency, $note, $silent = false ) {
		global $db, $messageStack, $order, $currencies;
		/** Convert amount to order currency */
		$amount_converted = $currencies->value($amount, true, $order->info['currency'], $order->info['currency_value']);

		if ( $amount <= 0 ) {
			$error = '<!-- Amount is null or empty. Order: ' . $order_id . ' -->';
			$messageStack->add_session( $error, 'error' );

			return false;
		}

		$transaction_ID = $this->get_transaction_id_from_order( $order_id );
		if ( ! $transaction_ID ) {
			return false;
		}

		try {
			//@TODO: Read current order status and determine best status to set this to
			$new_order_status = (int) MODULE_PAYMENT_LUNAR_CAPTURE_ORDER_STATUS_ID;
			$new_order_status = ( $new_order_status > 0 ? $new_order_status : 2 );

			// amount convert based on currency
			$captureAmount = cf_lunar_amount( $amount_converted, $currency );

			$api_client  = new Paylike\Paylike( $app_id );
			$lunar_capture = $api_client->transactions()->capture( $transaction_ID, array(
				'amount'   => $captureAmount,
				'currency' => $currency
			) );
			if ( $lunar_capture['successful'] ) {
				// update status in lunar
				zen_db_perform( 'lunar', array( 'transaction_status' => 'capture' ), 'update', 'transaction_id = "' . $lunar_capture['id'] . '"' );
				// update orders_status_history
				$comments = LUNAR_COMMENT_CAPTURE . $lunar_capture['id'] . "\n" . LUNAR_COMMENT_AMOUNT . number_format( (float) $amount_converted, 2, '.', '' ) . ' ' . $currency;

				$this->update_order_history( $comments, $new_order_status, $order_id );
				if ( ! $silent ) {
					// success message
					$success = LUNAR_COMMENT_CAPTURE_SUCCESS . $order_id;
					$messageStack->add_session( $success, 'success' );
				}
			} else {
				$error = LUNAR_COMMENT_CAPTURE_FAILURE . $transaction_ID . '<br/>' . LUNAR_COMMENT_ORDER . $order_id;
				$messageStack->add_session( $error, 'error' );
				lunar_debug( $error, __LINE__, __FILE__ );
				// if capture is silent the user doesn't get a message so we add it in the admin history
				if($silent) {
					$this->update_order_history( $error, 0, $order_id );
				}

				return false;
			}
		} catch ( \Paylike\Exception\ApiException $exception ) {
			$error = LUNAR_COMMENT_CAPTURE_FAILURE . $transaction_ID . '<br/>' . LUNAR_COMMENT_ORDER . $order_id;
			$message=$this->recordError( $exception, __LINE__, __FILE__, $messageStack, $error );

			// if capture is silent the user doesn't get a message so we add it in the admin history
			if($silent) {
				$this->update_order_history( $message, 0, $order_id );
			}

			return false;
		}

		return true;
	}

	/**
	 * Used to submit a refund for a given transaction.
	 *
	 * @param $app_id
	 * @param $order_id
	 * @param $amount
	 * @param $note
	 *
	 * @return bool
	 */
	function refund( $app_id, $order_id, $amount, $note ) {
		global $db, $messageStack, $order, $currencies;
		/** Convert amount to order currency */
		$order_total_converted = $currencies->value($order->info['total'], true, $order->info['currency'], $order->info['currency_value']);

		$transaction_ID = $this->get_transaction_id_from_order( $order_id );
		if ( ! $transaction_ID ) {
			return false;
		}

		$amount = 0;
		if ( isset( $_POST['partialrefund'] ) ) {
			$amount = (float) $_POST['refamt'];
			if ( $amount == 0 ) {
				$error = LUNAR_COMMENT_PARTIAL_REFUND_ERROR;
				$messageStack->add_session( $error, 'error' );

				return false;
			}
		} else {
			// force conversion to supported currencies: USD, GBP, CAD, EUR, AUD, NZD
			$currency = $order->info['currency'];
			// amount convert based on currency
			$amount = $order_total_converted;
		}

		try {
			//@TODO: Read current order status and determine best status to set this to
			$refundAmount    = cf_lunar_amount( $amount, $currency );
			$orderAmount     = cf_lunar_amount( $order_total_converted, $currency );
			$isPartialRefund = false;

			// new status
			if ( ( (int) $refundAmount + (int) $_POST['refundedAmt'] ) == (int) $orderAmount ) {
				$new_order_status = (int) MODULE_PAYMENT_LUNAR_REFUND_ORDER_STATUS_ID;
				$new_order_status = ( $new_order_status > 0 ? $new_order_status : 4 );
			} else {
				$isPartialRefund  = true;
				$new_order_status = (int) $order->info['orders_status'];
			}

			$api_client = new Paylike\Paylike( $app_id );
			$lunar_refund = $api_client->transactions()->refund( $transaction_ID, array(
				'amount' => $refundAmount
			) );
			if ( $lunar_refund['successful'] ) {
				// update status in lunar
				zen_db_perform( 'lunar', array( 'transaction_status' => ( $isPartialRefund ? 'partial_refund' : 'refund' ) ), 'update', 'transaction_id = "' . $lunar_refund['id'] . '"' );
				// update orders_status_history
				$comments = LUNAR_COMMENT_REFUND . $lunar_refund['id'] . "\n" . LUNAR_COMMENT_AMOUNT . number_format( (float) $amount, 2, '.', '' ) . ' ' . $currency;

				$this->update_order_history( $comments, $new_order_status, $order_id );
				// success message
				$success = LUNAR_COMMENT_REFUND_SUCCESS . $order_id;
				$messageStack->add_session( $success, 'success' );
			} else {
				$error = LUNAR_COMMENT_REFUND_FAILURE . $transaction_ID . '<br/>' . LUNAR_COMMENT_ORDER . $order_id;
				$messageStack->add_session( $error, 'error' );
				lunar_debug( $error, __LINE__, __FILE__ );

				return false;
			}
		} catch ( \Paylike\Exception\ApiException $exception ) {
			$error = LUNAR_COMMENT_REFUND_FAILURE . $transaction_ID . '<br/>' . LUNAR_COMMENT_ORDER . $order_id;
			$this->recordError( $exception, __LINE__, __FILE__, $messageStack, $error );

			return false;
		}

		return true;
	}

	/**
	 * Used to void a given previously-authorized transaction.
	 *
	 * @param $app_id
	 * @param $order_id
	 * @param $note
	 *
	 * @return bool
	 */
	function void( $app_id, $order_id, $note ) {
		global $db, $messageStack, $order, $currencies;
		/** Convert amount to order currency */
		$order_total_converted = $currencies->value($order->info['total'], true, $order->info['currency'], $order->info['currency_value']);

		$transaction_ID = $this->get_transaction_id_from_order( $order_id );
		if ( ! $transaction_ID ) {
			return false;
		}

		try {
			//@TODO: Read current order status and determine best status to set this to
			$new_order_status = (int) MODULE_PAYMENT_LUNAR_VOID_ORDER_STATUS_ID;
			$new_order_status = ( $new_order_status > 0 ? $new_order_status : 4 );

			// force conversion to supported currencies: USD, GBP, CAD, EUR, AUD, NZD
			$currency = $order->info['currency'];
			// amount convert based on currency
			$voidAmt = cf_lunar_amount( $order_total_converted, $currency );

			$api_client = new Paylike\Paylike( $app_id );
			$lunar_void   = $api_client->transactions()->void( $transaction_ID, array(
				'amount' => $voidAmt
			) );

			if ( $lunar_void['successful'] ) {
				// update status in lunar
				zen_db_perform( 'lunar', array( 'transaction_status' => 'void' ), 'update', 'transaction_id = "' . $lunar_void['id'] . '"' );
				// update orders_status_history
				$comments = LUNAR_COMMENT_VOID . $lunar_void['id'] . "\n" . LUNAR_COMMENT_AMOUNT . number_format( (float) $order_total_converted, 2, '.', '' ) . ' ' . $currency;
				$this->update_order_history( $comments, $new_order_status, $order_id );
				// success message
				$success = LUNAR_COMMENT_VOID_SUCCESS . $order_id;
				$messageStack->add_session( $success, 'success' );
			} else {
				$error = LUNAR_COMMENT_VOID_FAILURE . $transaction_ID . '<br/>' . LUNAR_COMMENT_ORDER . $order_id;
				$messageStack->add_session( $error, 'error' );
				lunar_debug( $error, __LINE__, __FILE__ );

				return false;
			}
		} catch ( \Paylike\Exception\ApiException $exception ) {
			$error = LUNAR_COMMENT_VOID_FAILURE . $transaction_ID . '<br/>' . LUNAR_COMMENT_ORDER . $order_id;
			$this->recordError( $exception, __LINE__, __FILE__, $messageStack, $error );

			return false;
		}

		return true;
	}

	/**
	 * @param $comments
	 * @param $new_order_status
	 * @param $order_id
	 */
	function update_order_history( $comments, $new_order_status, $order_id ) {
		// TABLE_ORDERS_STATUS_HISTORY
		$updated_by = function_exists( 'zen_get_admin_name' ) ? zen_get_admin_name( $_SESSION['admin_id'] ) : 'system';
		$sql1       = [
			'comments'          => $comments,
			'orders_id'         => (int) $order_id,
			'orders_status_id'  => $new_order_status,
			'customer_notified' => - 1,
			'date_added'        => 'now()',
			'updated_by'        => $updated_by
		];
		zen_db_perform( TABLE_ORDERS_STATUS_HISTORY, $sql1 );
		// update order status
		zen_db_perform( TABLE_ORDERS, array( 'orders_status' => (int) $new_order_status ), 'update', 'orders_id = "' . $order_id . '"' );
	}


	/**
	 * @param $order_id
	 *
	 * @return bool
	 */
	public function get_transaction_id_from_order( $order_id ) {
		global $db, $messageStack;

		// look up history on this order from lunar table
		$sql     = "select * from lunar where order_id = '" . (int) $order_id . "'";
		$history = $db->Execute( $sql );
		if ( $history->RecordCount() == 0 ) {
			$error = '<!-- ' . LUNAR_COMMENT_TRANSACTION_NOT_FOUND . $order_id . ' -->';
			$messageStack->add_session( $error, 'error' );

			return false;
		}

		$transactionID = $history->fields['transaction_id'];
		if ( $transactionID == '' || $transactionID === 0 ) {
			$error = '<!-- ' . LUNAR_COMMENT_TRANSACTION_EMPTY . $order_id . ' -->';
			$messageStack->add_session( $error, 'error' );

			return false;
		}

		return $transactionID;
	}

	/**
	 * @param        $exception
	 * @param null   $messageStack
	 *
	 * @param string $context
	 *
	 * @return bool|string
	 */
	public function recordError( $exception, $line = 0, $file = '', $messageStack = null, $context = '' ) {
		if ( ! $exception ) {
			return false;
		}
		$exception_type = get_class( $exception );
		$message        = '';
		switch ( $exception_type ) {
			case 'Paylike\\Exception\\NotFound':
				$message = LUNAR_ERROR_NOT_FOUND;
				break;
			case 'Paylike\\Exception\\InvalidRequest':
				$message = LUNAR_ERROR_INVALID_REQUEST;
				break;
			case 'Paylike\\Exception\\Forbidden':
				$message = LUNAR_ERROR_FORBIDDEN;
				break;
			case 'Paylike\\Exception\\Unauthorized':
				$message = LUNAR_ERROR_UNAUTHORIZED;
				break;
			case 'Paylike\\Exception\\Conflict':
				$message = LUNAR_ERROR_CONFLICT;
				break;
			case 'Paylike\\Exception\\ApiConnection':
				$message = LUNAR_ERROR_API_CONNECTION;
				break;
			case 'Paylike\\Exception\\ApiException':
				$message = LUNAR_ERROR_EXCEPTION;
				break;
		}
		$message       = LUNAR_ERROR . $message;
		$error_message = $this->get_response_error( $exception->getJsonBody() );
		if ( $context ) {
			$message = $context . PHP_EOL . $message;
		}
		if ( $error_message ) {
			$message = $message . PHP_EOL . 'Validation:' . PHP_EOL . $error_message;
		}

		if ( $messageStack ) {
			$messageStack->add_session( nl2br( $message ), 'error' );
		}
		lunar_debug( $message . PHP_EOL . json_encode( $exception->getJsonBody() ), $line, $file );

		return $message;
	}

	/**
	 * Gets errors from a failed api request
	 *
	 * @param array $result The result returned by the api wrapper.
	 *
	 * @return string
	 */
	public function get_response_error( $result ) {
		$error = array();
		// if this is just one error
		if ( isset( $result['text'] ) ) {
			return $result['text'];
		}

		// otherwise this is a multi field error
		if ( $result ) {
			foreach ( $result as $field_error ) {
				$error[] = $field_error['field'] . ':' . $field_error['message'];
			}
		}
		$error_message = implode( ' ', $error );

		return $error_message;
	}


}

?>