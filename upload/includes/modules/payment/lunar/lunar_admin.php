<?php


use Lunar\Lunar as ApiClient;
use Lunar\Payment\helpers\LunarHelper;

/**
 *
 */
class lunar_admin
{
    private ApiClient $apiClient;
	// protected $paymentMethod = null;
	private $currencyCode;
 	private $totalAmount;

	/**
	 * constructor
	 */
	public function __construct()
	{
		$this->apiClient = new ApiClient(MODULE_PAYMENT_LUNAR_APP_KEY, null, !!$_COOKIE['lunar_testmode']);
	}


    /**
     * 
     */
    public function getPaymentIntentCookie()
    {
        return $_COOKIE[LunarHelper::INTENT_KEY] ?? '';
    }

    /**
     * 
     */
    public function savePaymentIntentCookie($paymentIntentId)
    {
        return setcookie(LunarHelper::INTENT_KEY, $paymentIntentId, 0, '', '', false, true);
    }
	
	/**
	 * @return string|void
	 */
	public function createPaymentIntent( $args )
	{
		try {
			$paymentIntentId = $this->apiClient->payments()->create( $args );

			if ( $paymentIntentId ) {
				return $paymentIntentId;
			}

			lunar_debug( LUNAR_ERROR_INVALID_REQUEST, __LINE__, __FILE__ );

		} catch ( \Lunar\Exception\ApiException $exception ) {
			$this->recordError( $exception, __LINE__, __FILE__, LUNAR_ERROR_EXCEPTION );
		}
	}

	/**
	 * 
	 */
	public function fetchApiTransaction( $transaction_id )
	{
		global $order;

		try {
			$lunar_history = $this->apiClient->payments()->fetch( $transaction_id );
			
			$this->currencyCode = $order->info['currency'];
			$this->totalAmount = (string) $order->info['total'];

			if (!$this->parseApiTransactionResponse($lunar_history)) {
				return $this->getResponseError($lunar_history);
			} else {
				return $lunar_history;
			}

			$error = LUNAR_COMMENT_TRANSACTION_FETCH_ISSUE . $transaction_id;
			lunar_debug( $error, __LINE__, __FILE__ );

		} catch ( \Lunar\Exception\ApiException $exception ) {
			$error = LUNAR_COMMENT_TRANSACTION_FETCH_ISSUE . $transaction_id;
			$this->recordError( $exception, __LINE__, __FILE__, $error );
		}
	}

	/**
	 * Used to capture part or all of a given previously-authorized transaction.
	 * @return bool
	 */
	public function capture( $order_id, $data, $silent = false )
	{
		global $messageStack;

		if ( $data['amount'] <= 0 ) {
			$error = '<!-- Amount is null or empty. Order: ' . $order_id . ' -->';
			$messageStack->add_session( $error, 'error' );

			return false;
		}

		$this->currencyCode = $data['currency'];
		$this->totalAmount = $data['amount'];

		$transaction = $this->get_transaction_by_order_id( $order_id );
		$transaction_ID = $transaction->fields['transaction_id'] ?? '';
		if ( ! $transaction_ID ) {
			return false;
		}

		try {
			//@TODO: Read current order status and determine best status to set this to

			$new_order_status = (int) MODULE_PAYMENT_LUNAR_CAPTURE_ORDER_STATUS_ID;
			$new_order_status = ( $new_order_status > 0 ? $new_order_status : 2 );

			$apiResponse = $this->apiClient->payments()->capture( $transaction_ID, [
				'amount' => [
					'currency' => $this->currencyCode,
					'decimal' => $this->totalAmount,
				]
			]);

			if ( 'completed' == $apiResponse['captureState'] ) {
				zen_db_perform( LunarHelper::LUNAR_DB_TABLE, 
					[
						'transaction_type' => 'capture',
					],
					'update',
					'transaction_id = "' . $transaction_ID . '"' 
				);
				// update orders_status_history
				$comments = LUNAR_COMMENT_CAPTURE . $transaction_ID . "\n" . LUNAR_COMMENT_AMOUNT . $this->totalAmount . ' ' . $this->currencyCode;

				$this->update_order_history( $comments, $new_order_status, $order_id );
				if ( ! $silent ) {
					// success message
					$success = LUNAR_COMMENT_CAPTURE_SUCCESS . $order_id;
					$messageStack->add_session( $success, 'success' );
				}
			} else {
				$error = LUNAR_COMMENT_CAPTURE_FAILURE . $transaction_ID . '<br/>' . LUNAR_COMMENT_ORDER . $order_id;
				$error .= "<br>" . $this->getResponseError($apiResponse);
				$messageStack->add_session( $error, 'error' );
				lunar_debug( $error, __LINE__, __FILE__ );
				// if capture is silent the user doesn't get a message so we add it in the admin history
				if ($silent) {
					$this->update_order_history( $error, 0, $order_id );
				}
				return false;
			}
		} catch ( \Lunar\Exception\ApiException $exception ) {
			$error = LUNAR_COMMENT_CAPTURE_FAILURE . $transaction_ID . '<br/>' . LUNAR_COMMENT_ORDER . $order_id;
			$message=$this->recordError( $exception, __LINE__, __FILE__, $error );

			// if capture is silent the user doesn't get a message so we add it in the admin history
			if ($silent) {
				$this->update_order_history( $message, 0, $order_id );
			}

			return false;
		}

		return true;
	}

	/**
	 * Used to submit a refund for a given transaction.
	 * @return bool
	 */
	public function refund( $order_id )
	{
		global $messageStack, $order, $currency;

		$transaction = $this->get_transaction_by_order_id( $order_id );
		$transaction_ID = $transaction->fields['transaction_id'] ?? '';
		if ( ! $transaction_ID ) {
			return false;
		}

		$refundAmount = 0;
		if ( isset( $_POST['partialrefund'] ) ) {
			$refundAmount = $_POST['refamt'];
			if ( $refundAmount == 0 ) {
				$error = LUNAR_COMMENT_PARTIAL_REFUND_ERROR;
				$messageStack->add_session( $error, 'error' );

				return false;
			}
		} else {
			$refundAmount = $transaction->fields['order_amount'];
		}

		try {
			//@TODO: Read current order status and determine best status to set this to

			$isPartialRefund = false;

			$this->currencyCode = $order->info['currency'];
			$this->totalAmount = (string) $order->info['total'];

			$amountToRefund = $refundAmount + $_POST['refundedAmt'];
			$diff = 1 / (10 ** $currency['decimal_places']);

			// new status
			if ( $amountToRefund == $this->totalAmount || abs($amountToRefund - $this->totalAmount) <= $diff) {
				$new_order_status = (int) MODULE_PAYMENT_LUNAR_REFUND_ORDER_STATUS_ID;
				$new_order_status = ( $new_order_status > 0 ? $new_order_status : 4 );
			} else {
				$isPartialRefund  = true;
				$new_order_status = (int) $order->info['orders_status'];
			}

			$apiResponse = $this->apiClient->payments()->refund( $transaction_ID,  [
				'amount' => [
					'currency' => $this->currencyCode,
					'decimal' => (string) $refundAmount,
				]
			]);

			if ( 'completed' == $apiResponse['refundState'] ) {
				zen_db_perform( LunarHelper::LUNAR_DB_TABLE,
					[
						'transaction_type' => ( $isPartialRefund ? 'partial_refund' : 'refund' ),
						'transaction_amount' => $refundAmount,
					], 
					'update', 
					'transaction_id = "' . $transaction_ID . '"' ,
				);

				$comments = $isPartialRefund ? LUNAR_COMMENT_PARTIAL_REFUND : LUNAR_COMMENT_REFUND;
				$comments .= $transaction_ID . "\n" . LUNAR_COMMENT_AMOUNT . $refundAmount . ' ' . $this->currencyCode;
				$this->update_order_history( $comments, $new_order_status, $order_id );

				$success = LUNAR_COMMENT_REFUND_SUCCESS . $order_id;
				$messageStack->add_session( $success, 'success' );

			} else {
				$error = LUNAR_COMMENT_REFUND_FAILURE . $transaction_ID . '<br/>' . LUNAR_COMMENT_ORDER . $order_id;
				$error .= "<br>" . $this->getResponseError($apiResponse);
				$messageStack->add_session( $error, 'error' );
				lunar_debug( $error, __LINE__, __FILE__ );
				return false;
			}
		} catch ( \Lunar\Exception\ApiException $exception ) {
			$error = LUNAR_COMMENT_REFUND_FAILURE . $transaction_ID . '<br/>' . LUNAR_COMMENT_ORDER . $order_id;
			$this->recordError( $exception, __LINE__, __FILE__, $error );

			return false;
		}

		return true;
	}

	/**
	 * Used to void a given previously-authorized transaction.
	 * @return bool
	 */
	public function void( $order_id )
	{
		global $messageStack, $order;

		$transaction = $this->get_transaction_by_order_id( $order_id );
		$transaction_ID = $transaction->fields['transaction_id'] ?? '';
		if ( ! $transaction_ID ) {
			return false;
		}

		try {
			//@TODO: Read current order status and determine best status to set this to
			$new_order_status = (int) MODULE_PAYMENT_LUNAR_VOID_ORDER_STATUS_ID;
			$new_order_status = ( $new_order_status > 0 ? $new_order_status : 4 );

			$this->currencyCode = $order->info['currency'];
			$this->totalAmount = (string) $order->info['total'];

			$apiResponse = $this->apiClient->payments()->cancel( $transaction_ID, [
				'amount' => [
					'currency' => $this->currencyCode,
					'decimal' => $this->totalAmount,
				]
			]);

			if ( 'completed' == $apiResponse['cancelState'] ) {
				// update status in lunar
				zen_db_perform( LunarHelper::LUNAR_DB_TABLE,
					[
						'transaction_type' => 'void',
					],
					'update',
					'transaction_id = "' . $transaction_ID . '"'
				);
				// update orders_status_history
				$comments = LUNAR_COMMENT_VOID . $transaction_ID . "\n" . LUNAR_COMMENT_AMOUNT . $this->totalAmount . ' ' . $this->currencyCode;
				$this->update_order_history( $comments, $new_order_status, $order_id );
				// success message
				$success = LUNAR_COMMENT_VOID_SUCCESS . $order_id;
				$messageStack->add_session( $success, 'success' );
			} else {
				$error = LUNAR_COMMENT_VOID_FAILURE . $transaction_ID . '<br/>' . LUNAR_COMMENT_ORDER . $order_id;
				$error .= "<br>" . $this->getResponseError($apiResponse);
				$messageStack->add_session( $error, 'error' );
				lunar_debug( $error, __LINE__, __FILE__ );
				return false;
			}
		} catch ( \Lunar\Exception\ApiException $exception ) {
			$error = LUNAR_COMMENT_VOID_FAILURE . $transaction_ID . '<br/>' . LUNAR_COMMENT_ORDER . $order_id;
			$this->recordError( $exception, __LINE__, __FILE__, $error );

			return false;
		}

		return true;
	}

	/**
	 * @param $comments
	 * @param $new_order_status
	 * @param $order_id
	 */
	private function update_order_history( $comments, $new_order_status, $order_id )
	{
		// TABLE_ORDERS_STATUS_HISTORY
		$updated_by = function_exists( 'zen_get_admin_name' ) ? zen_get_admin_name( $_SESSION['admin_id'] ) : 'system';
		$data       = [
			'comments'          => $comments,
			'orders_id'         => (int) $order_id,
			'orders_status_id'  => $new_order_status,
			'customer_notified' => - 1,
			'date_added'        => 'now()',
			'updated_by'        => $updated_by
		];
		zen_db_perform( TABLE_ORDERS_STATUS_HISTORY, $data );
		// update order status
		zen_db_perform( TABLE_ORDERS, array( 'orders_status' => (int) $new_order_status ), 'update', 'orders_id = "' . $order_id . '"' );
	}


	/**
	 * @param $order_id
	 */
	public function get_transaction_by_order_id( $order_id )
	{
		global $db, $messageStack;

		// look up history on this order from lunar table
		$sql     = "SELECT * FROM " . LunarHelper::LUNAR_DB_TABLE . " WHERE order_id = '" . (int) $order_id . "'";
		$lunarTransaction = $db->Execute( $sql );
		if ( $lunarTransaction->RecordCount() == 0 ) {
			$error = '<!-- ' . LUNAR_COMMENT_TRANSACTION_NOT_FOUND . $order_id . ' -->';
			$messageStack->add_session( $error, 'error' );

			return false;
		}

		return $lunarTransaction;
	}

	/**
	 * @param        $exception
	 * @param null   $messageStack
	 *
	 * @param string $context
	 *
	 * @return bool|string
	 */
	public function recordError( $exception, $line = 0, $file = '', $context = '' )
	{
		global $messageStack;

		if ( ! $exception ) {
			return false;
		}
		$exception_type = get_class( $exception );
		$message        = '';
		switch ( $exception_type ) {
			case 'Lunar\\Exception\\NotFound':
				$message = LUNAR_ERROR_NOT_FOUND;
				break;
			case 'Lunar\\Exception\\InvalidRequest':
				$message = LUNAR_ERROR_INVALID_REQUEST;
				break;
			case 'Lunar\\Exception\\Forbidden':
				$message = LUNAR_ERROR_FORBIDDEN;
				break;
			case 'Lunar\\Exception\\Unauthorized':
				$message = LUNAR_ERROR_UNAUTHORIZED;
				break;
			case 'Lunar\\Exception\\Conflict':
				$message = LUNAR_ERROR_CONFLICT;
				break;
			case 'Lunar\\Exception\\ApiConnection':
				$message = LUNAR_ERROR_API_CONNECTION;
				break;
			case 'Lunar\\Exception\\ApiException':
				$message = LUNAR_ERROR_EXCEPTION;
				break;
		}
		$message       = LUNAR_ERROR . $message;
		$error_message = $this->getResponseError( $exception->getJsonBody() );
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
     * Parses api transaction response for errors
     */
    private function parseApiTransactionResponse($transaction): bool
    {
        if (! $this->isTransactionSuccessful($transaction)) {
            return false;
        }

        return true;
    }

    /**
     * Checks if the transaction was successful and
     * the data was not tempered with.
     */
    private function isTransactionSuccessful($transaction): bool
    {   
        $matchCurrency = $this->currencyCode == ($transaction['amount']['currency'] ?? '');
        $matchAmount = $this->totalAmount == ($transaction['amount']['decimal'] ?? '');

        return (true == $transaction['authorisationCreated'] && $matchCurrency && $matchAmount);
    }

    /**
     * Gets errors from a failed api request
     * @param array $result The result returned by the api wrapper.
     */
    private function getResponseError($result): string
    {
        $error = [];
        // if this is just one error
        if (isset($result['text'])) {
            return $result['text'];
        }

        if (isset($result['code']) && isset($result['error'])) {
            return $result['code'] . '-' . $result['error'];
        }

        // otherwise this is a multi field error
        if ($result) {
            foreach ($result as $fieldError) {
                $error[] = $fieldError['field'] . ':' . $fieldError['message'];
            }
        }

        return implode(' ', $error);
    }

	/**
	 * install lunar payment model
	 */
	public function install()
	{
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
		              VALUES ('" . LUNAR_ADMIN_LOGO_URL_TITLE . "', 'MODULE_PAYMENT_LUNAR_LOGO_URL', '', '" . LUNAR_ADMIN_METHOD_LOGO_URL_DESCRIPTION . "', '6', '4', now())" );
		$db->Execute( "INSERT INTO " . TABLE_CONFIGURATION .
		              " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added)
		              VALUES ('" . LUNAR_ADMIN_METHOD_TITLE_TITLE . "', 'MODULE_PAYMENT_LUNAR_TITLE', '" . LUNAR_ADMIN_METHOD_TITLE_VALUE . "', '" . LUNAR_ADMIN_METHOD_TITLE_DESCRIPTION . "', '6', '4', now())" );
		$db->Execute( "INSERT INTO " . TABLE_CONFIGURATION .
		              " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added)
		              VALUES ('" . LUNAR_ADMIN_METHOD_DESCRIPTION_TITLE . "', 'MODULE_PAYMENT_LUNAR_DESCRIPTION', '" . LUNAR_ADMIN_METHOD_DESCRIPTION_VALUE . "', '" . LUNAR_ADMIN_METHOD_DESCRIPTION_DESCRIPTION . "', '6', '5', now())" );
		$db->Execute( "INSERT INTO " . TABLE_CONFIGURATION .
		              " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added)
		              VALUES ('" . LUNAR_ADMIN_CAPTURE_MODE_TITLE . "', 'MODULE_PAYMENT_LUNAR_CAPTURE_MODE', '" . LUNAR_ADMIN_CAPTURE_MODE_INSTANT . "', '" . LUNAR_ADMIN_CAPTURE_MODE_DESCRIPTION . "', '6', '6', 'zen_cfg_select_option(array(\'" . LUNAR_ADMIN_CAPTURE_MODE_DELAYED . "\', \'" . LUNAR_ADMIN_CAPTURE_MODE_INSTANT . "\'), ', now())" );
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
	 */
	public function remove()
	{
		global $db;
		$db->Execute( "DELETE FROM " . TABLE_CONFIGURATION . " WHERE configuration_key LIKE 'MODULE\_PAYMENT\_LUNAR\_%'" );
	}

	/**
	 * 
	 */
	public function create_transactions_table()
	{
		global $db;
		$db->Execute( "CREATE TABLE IF NOT EXISTS `" . LunarHelper::LUNAR_DB_TABLE . "` (
			id INT NOT NULL AUTO_INCREMENT,
			order_id INT NOT NULL,
			transaction_id VARCHAR(100) NOT NULL,
			order_amount VARCHAR(50) NOT NULL,
			transaction_amount VARCHAR(50) NOT NULL,
			transaction_type ENUM(" . implode(', ', LunarHelper::PAYMENT_TYPES) . ") NOT NULL,
			method_code VARCHAR(50) NOT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id) );"
		);
	}

	/**
	 * @return array
	 */
	public function keys()
	{
		return [
			'MODULE_PAYMENT_LUNAR_STATUS',
			'MODULE_PAYMENT_LUNAR_APP_KEY',
			'MODULE_PAYMENT_LUNAR_PUBLIC_KEY',
			'MODULE_PAYMENT_LUNAR_LOGO_URL',
			'MODULE_PAYMENT_LUNAR_TITLE',
			'MODULE_PAYMENT_LUNAR_DESCRIPTION',
			'MODULE_PAYMENT_LUNAR_SHOP_TITLE',
			'MODULE_PAYMENT_LUNAR_CAPTURE_MODE',
			'MODULE_PAYMENT_LUNAR_ACCEPTED_CARDS',
			'MODULE_PAYMENT_LUNAR_ZONE',
			'MODULE_PAYMENT_LUNAR_CAPTURE_ORDER_STATUS_ID',
			'MODULE_PAYMENT_LUNAR_REFUND_ORDER_STATUS_ID',
			'MODULE_PAYMENT_LUNAR_VOID_ORDER_STATUS_ID',
			'MODULE_PAYMENT_LUNAR_SORT_ORDER'
		];
	}


}