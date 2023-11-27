<?php


use Lunar\Lunar as ApiClient;
use Lunar\Payment\helpers\LunarHelper;

/**
 *
 */
class lunar_admin
{
    private ApiClient $apiClient;
	private $paymentMethod = null;
	private $currencyCode;
 	private $totalAmount;
 	private $isMobilePay = false;

	/**
	 * constructor
	 */
	public function __construct(string $paymentMethodCode)
	{
		$this->paymentMethod = $paymentMethodCode;
		$this->isMobilePay = LunarHelper::LUNAR_MOBILEPAY_CODE == $this->paymentMethod;

		$this->apiClient = new ApiClient($this->getConfig('APP_KEY'), null, !!$_COOKIE['lunar_testmode']);
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

			$this->writeLog( LUNAR_ERROR_INVALID_REQUEST, __LINE__, __FILE__ );

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
			$this->writeLog( $error, __LINE__, __FILE__ );

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
		global $messageStack, $currencies;

		if ( $data['amount'] <= 0 ) {
			$error = '<!-- Amount is null or empty. Order: ' . $order_id . ' -->';
			$messageStack->add_session( $error, 'error' );

			return false;
		}

		$this->currencyCode = $data['currency'];
		$this->totalAmount = $data['amount'];
		
		/**
		 * @TODO maybe at some point we can format the amount like bellow (also for other actions)
		 * we cannot use $currencies->format because it uses thousand separator, and the API isn't happy with that
		 * 
		 * $currencyData = $currencies->currencies[$data['currency']];
		 * $this->totalAmount = number_format((float)$data['amount'], $currencyData['decimal_places'], $currencyData['decimal_point'], '');
		 */ 

		$transaction = $this->get_transaction_by_order_id( $order_id );
		$transaction_ID = $transaction->fields['transaction_id'] ?? '';
		if ( ! $transaction_ID ) {
			return false;
		}

		try {
			//@TODO: Read current order status and determine best status to set this to

			$new_order_status = (int) $this->getConfig('CAPTURE_ORDER_STATUS_ID');
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
				$this->writeLog( $error, __LINE__, __FILE__ );
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
		global $messageStack, $order, $currency, $currencies;

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

			$amountToRefund = $refundAmount + ($_POST['refundedAmt'] ? $_POST['refundedAmt'] : 0);

			// used to detect the difference in floating point precision
			$diff = 1 / (10 ** $currency['decimal_places']);

			// new order status if full refund
			if ( $amountToRefund == $this->totalAmount || abs($amountToRefund - $this->totalAmount) <= $diff) {
				$new_order_status = (int) $this->getConfig('REFUND_ORDER_STATUS_ID');
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
				$this->writeLog( $error, __LINE__, __FILE__ );
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
			$new_order_status = (int) $this->getConfig('VOID_ORDER_STATUS_ID');
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
				$this->writeLog( $error, __LINE__, __FILE__ );
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
		$this->writeLog( $message . PHP_EOL . json_encode( $exception->getJsonBody() ), $line, $file );

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
			if (isset($result['declinedReason'])) {
				return $result['declinedReason']['error'];
			}

            foreach ($result as $fieldError) {
				if (isset($fieldError['field']) && isset($fieldError['message'])) {
					$error[] = $fieldError['field'] . ':' . $fieldError['message'];
				} else {
					$error = $fieldError;
				}
            }
        }

        return implode(' ', $error);
    }

    /**
     * 
     */
    private function writeLog($error, $lineNo = 0, $file = '')
    {
		LunarHelper::writeLog($error, $lineNo, $file);
    }

    /**
     * 
     */
    public function getConfig($key)
    {
		$constantName = 'MODULE_PAYMENT_LUNAR_' . strtoupper($this->paymentMethod) . '_' . $key;
		return defined($constantName) ? constant($constantName) : null;
    }

	/**
	 * install lunar payment model
	 */
	public function install()
	{
		global $db;

		$defaultTitle = constant('LUNAR_ADMIN_METHOD_TITLE_VALUE_' . strtoupper($this->paymentMethod));
		$defaultDescription = constant('LUNAR_ADMIN_METHOD_DESCRIPTION_VALUE_' . strtoupper($this->paymentMethod));

		$db->Execute( "INSERT INTO " . TABLE_CONFIGURATION .
		              " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added)
		              VALUES ('" . LUNAR_ADMIN_ENABLE_TITLE . "', '" . $this->getKey('STATUS') . "', 'True', '" . LUNAR_ADMIN_ENABLE_DESCRIPTION . "', '6', '10', 'zen_cfg_select_option(array(\'True\', \'False\'), ', now());" );
		$db->Execute( "INSERT INTO " . TABLE_CONFIGURATION .
					" (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added)
					VALUES ('" . LUNAR_ADMIN_APP_KEY_TITLE . "', '" . $this->getKey('APP_KEY') . "', '', '" . LUNAR_ADMIN_APP_KEY_DESCRIPTION . "', '6', '20', now())" );
		$db->Execute( "INSERT INTO " . TABLE_CONFIGURATION .
					" (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added)
					VALUES ('" . LUNAR_ADMIN_PUBLIC_KEY_TITLE . "', '" . $this->getKey('PUBLIC_KEY') . "', '', '" . LUNAR_ADMIN_PUBLIC_KEY_DESCRIPTION . "', '6', '30', now())" );
		$db->Execute( "INSERT INTO " . TABLE_CONFIGURATION .
		              " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added)
		              VALUES ('" . LUNAR_ADMIN_LOGO_URL_TITLE . "', '" . $this->getKey('LOGO_URL') . "', '', '" . LUNAR_ADMIN_METHOD_LOGO_URL_DESCRIPTION . "', '6', '40', now())" );
		$db->Execute( "INSERT INTO " . TABLE_CONFIGURATION .
		              " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added)
		              VALUES ('" . LUNAR_ADMIN_METHOD_TITLE_TITLE . "', '" . $this->getKey('TITLE') . "', '" . $defaultTitle . "', '" . LUNAR_ADMIN_METHOD_TITLE_DESCRIPTION . "', '6', '50', now())" );
		$db->Execute( "INSERT INTO " . TABLE_CONFIGURATION .
		              " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added)
		              VALUES ('" . LUNAR_ADMIN_METHOD_DESCRIPTION_TITLE . "', '" . $this->getKey('DESCRIPTION') . "', '" . $defaultDescription . "', '" . LUNAR_ADMIN_METHOD_DESCRIPTION_DESCRIPTION . "', '6', '60', now())" );
		$db->Execute( "INSERT INTO " . TABLE_CONFIGURATION .
		              " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added)
		              VALUES ('" . LUNAR_ADMIN_CAPTURE_MODE_TITLE . "', '" . $this->getKey('CAPTURE_MODE') . "', '" . LUNAR_ADMIN_CAPTURE_MODE_INSTANT . "', '" . LUNAR_ADMIN_CAPTURE_MODE_DESCRIPTION . "', '6', '70', 'zen_cfg_select_option(array(\'" . LUNAR_ADMIN_CAPTURE_MODE_DELAYED . "\', \'" . LUNAR_ADMIN_CAPTURE_MODE_INSTANT . "\'), ', now())" );
		$db->Execute( "INSERT INTO " . TABLE_CONFIGURATION .
		              " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added)
		             VALUES ('" . LUNAR_ADMIN_SHOP_TITLE . "', '" . $this->getKey('SHOP_TITLE') . "', '" . (defined( 'STORE_NAME' ) ? STORE_NAME : '') . "', '" . LUNAR_ADMIN_SHOP_DESCRIPTION . "', '6', '80', now())" );
		$db->Execute( "INSERT INTO " . TABLE_CONFIGURATION .
		              " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, use_function, set_function, date_added)
		              VALUES ('" . LUNAR_ADMIN_PAYMENT_ZONE_TITLE . "', '" . $this->getKey('ZONE') . "', '0', '" . LUNAR_ADMIN_PAYMENT_ZONE_DESCRIPTION . "', '6', '90', 'zen_get_zone_class_title', 'zen_cfg_pull_down_zone_classes(', now())" );
		$db->Execute( "INSERT INTO " . TABLE_CONFIGURATION .
		              " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, use_function, date_added)
		               VALUES ('" . LUNAR_ADMIN_CAPTURE_STATUS_TITLE . "', '" . $this->getKey('CAPTURE_ORDER_STATUS_ID') . "', '2', '" . LUNAR_ADMIN_CAPTURE_STATUS_DESCRIPTION . "', '6', '100', 'zen_cfg_pull_down_order_statuses(', 'zen_get_order_status_name', now())" );
		$db->Execute( "INSERT INTO " . TABLE_CONFIGURATION .
		              " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, use_function, date_added)
		               VALUES ('" . LUNAR_ADMIN_REFUND_STATUS_TITLE . "', '" . $this->getKey('REFUND_ORDER_STATUS_ID') . "', '4', '" . LUNAR_ADMIN_REFUND_STATUS_DESCRIPTION . "', '6', '110', 'zen_cfg_pull_down_order_statuses(', 'zen_get_order_status_name', now())" );
		$db->Execute( "INSERT INTO " . TABLE_CONFIGURATION .
		              " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, use_function, date_added)
		               VALUES ('" . LUNAR_ADMIN_CANCEL_STATUS_TITLE . "', '" . $this->getKey('VOID_ORDER_STATUS_ID') . "', '4', '" . LUNAR_ADMIN_CANCEL_STATUS_DESCRIPTION . "', '6', '120', 'zen_cfg_pull_down_order_statuses(', 'zen_get_order_status_name', now())" );
		$db->Execute( "INSERT INTO " . TABLE_CONFIGURATION .
		              " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added)
		               VALUES ('" . LUNAR_ADMIN_SORT_ORDER_TITLE . "', '" . $this->getKey('SORT_ORDER') . "', '0', '" . LUNAR_ADMIN_SORT_ORDER_DESCRIPTION . "', '6', '130', now())" );
					   
		if ($this->isMobilePay) {
			$db->Execute( "INSERT INTO " . TABLE_CONFIGURATION .
						" (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added)
						VALUES ('" . LUNAR_ADMIN_CONFIGURATION_ID_TITLE . "', '" . $this->getKey('CONFIGURATION_ID') . "', '', '" . LUNAR_ADMIN_CONFIGURATION_ID_DESCRIPTION . "', '6', '31', now())" );
		}
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
			transaction_type ENUM('" . implode("','", LunarHelper::PAYMENT_TYPES) . "') NOT NULL,
			method_code VARCHAR(50) NOT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id) );"
		);
	}

	/**
	 * @return array
	 */
	public function keys($associative = false)
	{
		$upperMethodCode = strtoupper($this->paymentMethod);
		$configKeys = $this->configKeys();

		$keys = [];
		foreach ($configKeys as $configKey) {
			if ($associative) {
				$keys[$configKey] = 'MODULE_PAYMENT_LUNAR_' . $upperMethodCode . '_' . $configKey;
			} else {
				$keys[] = 'MODULE_PAYMENT_LUNAR_' . $upperMethodCode . '_' . $configKey;
			}
		}

		return $keys;
	}

	/**
	 * @return array
	 */
	private function configKeys()
	{
		$keys = [
			'STATUS',
			'APP_KEY',
			'PUBLIC_KEY',
		];

		if ($this->isMobilePay) {
			/**
			 * put here to have the field in this order after keys
			 * the sort_order value from DB doesn't work (need to check why)
			 */
			$keys[] = 'CONFIGURATION_ID';
		}

		$keys = array_merge($keys, [
			'LOGO_URL',
			'TITLE',
			'DESCRIPTION',
			'SHOP_TITLE',
			'CAPTURE_MODE',
			'ZONE',
			'CAPTURE_ORDER_STATUS_ID',
			'REFUND_ORDER_STATUS_ID',
			'VOID_ORDER_STATUS_ID',
			'SORT_ORDER',
		]);

		return $keys;
	}

	/**
	 * @return array
	 */
	private function getKey($key)
	{
		return $this->keys(true)[$key];
	}


}