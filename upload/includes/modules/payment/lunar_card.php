<?php
/** Prevent double load of API SDK library. */
if (! class_exists('Lunar\\Lunar') ) {
	require_once( __DIR__ . '/lunar/vendor/autoload.php' );
}
require_once( __DIR__ . '/lunar/lunar_admin.php' );
require_once( __DIR__ . '/lunar/lunar.php' );
require_once( __DIR__ . '/lunar/lunar_admin_actions.php' );

use Lunar\Payment\helpers\LunarHelper;

/**
 *
 */
class lunar_card extends base 
{
	const METHOD_CODE = 'card';

	const REMOTE_URL = 'https://pay.lunar.money/?id=';
    const TEST_REMOTE_URL = 'https://hosted-checkout-git-develop-lunar-app.vercel.app/?id=';

	/** @see also includes/classes/payment.php */
    public $_check;
    public $code;
    public $description;
    public $enabled;
	public $title;
	public $sort_order;
	// public $form_action_url;
	
	public $order;
	public array $args;

	private bool $testMode = false;
	private bool $isInstantMode = false;
	private $lunar_admin;
	
	/**
	 * constructor
	 */
	public function __construct()
	{
		$this->code            = LunarHelper::LUNAR_METHODS[self::METHOD_CODE];
		$this->enabled         = defined( 'MODULE_PAYMENT_LUNAR_STATUS' ) && MODULE_PAYMENT_LUNAR_STATUS == 'True';
		$this->title           = MODULE_PAYMENT_LUNAR_TITLE;
		$this->description     = MODULE_PAYMENT_LUNAR_DESCRIPTION;
		$this->sort_order      = defined( 'MODULE_PAYMENT_LUNAR_SORT_ORDER' ) ? MODULE_PAYMENT_LUNAR_SORT_ORDER : 0; // Sort Order in the checkout page
		$this->order 		   =  $GLOBALS['order'];
		$this->testMode 	   = !!$_COOKIE['lunar_testmode'];
		$this->isInstantMode   = MODULE_PAYMENT_LUNAR_CAPTURE_MODE === 'Instant';
		$this->lunar_admin     = new lunar_admin();

		if ( IS_ADMIN_FLAG === true ) {
			if ( MODULE_PAYMENT_LUNAR_APP_KEY == '' || MODULE_PAYMENT_LUNAR_PUBLIC_KEY == '' ) {
				$alertHtml   = '<span class="alert"> (' . LUNAR_WARNING_NOT_CONFIGURED . ')</span>';
				$this->title .= $alertHtml;
			}

			$this->title = MODULE_PAYMENT_LUNAR_ADMIN_TITLE;
			$this->description = MODULE_PAYMENT_LUNAR_ADMIN_DESCRIPTION;

		}

		if ( is_object( $this->order ) ) {
			$this->update_status();
		}

		// verify table
		if ( IS_ADMIN_FLAG === true ) {
			$this->tableCheckup();
		}

	}

	/**
	 * Before processing
	 * We redirect the customer to the hosted checkout, then back to the store
	 */
	public function before_process()
	{
		// skip if the request is from redirect
		if (isset($_GET['lunar_method'])) {
			return;
		}

		$this->setArgs();

		$paymentIntentId = $this->lunar_admin->getPaymentIntentCookie();
		if (!$paymentIntentId) {
			$paymentIntentId = $this->lunar_admin->createPaymentIntent($this->args);
		}

		if ( !$paymentIntentId ) {
			$this->redirectToCheckoutPage();
		}

		$this->lunar_admin->savePaymentIntentCookie($paymentIntentId);

		zen_redirect(($this->testMode ? self::TEST_REMOTE_URL : self::REMOTE_URL) . $paymentIntentId);
	}

	/**
	 * Post-processing activities
	 * Update order, capture if needed, store transaction
	 */
	public function after_process()
	{
		global $order, $insert_id;

		$paymentMethod = $_GET['lunar_method'] ?? '';
		if (!$paymentMethod) {
			$this->redirectToCheckoutPage(LUNAR_ORDER_ERROR_METHOD_MISSING);
		}

		$paymentIntentId = $this->lunar_admin->getPaymentIntentCookie();

		if ($paymentIntentId) {
			$this->redirectToCheckoutPage(LUNAR_ORDER_ERROR_PAYMENT_INTENT_NOT_FOUND);
		}

		$apiResponse = $this->lunar_admin->fetchApiTransaction($paymentIntentId);

		if (!is_array($apiResponse)) {
			$this->redirectToCheckoutPage($apiResponse);
		}

		if ($apiResponse['amount']['decimal'] != $order->info['total'] || $apiResponse['amount']['currency'] != $order->info['currency']) {
			$this->redirectToCheckoutPage(LUNAR_ORDER_ERROR_AMOUNT_CURRENCY_MISMATCH);
		}

		$data = [
			'transaction_id' => $paymentIntentId,
			'amount'         => $apiResponse['amount']['decimal'],
			'currency'       => $apiResponse['amount']['currency'],
		];

		setcookie(LunarHelper::INTENT_KEY, '', 1);

		$this->insert_lunar_transaction( $data, $insert_id );

		$this->insert_order_history( $data, $insert_id );

		zen_db_perform( TABLE_ORDERS, array( 'orders_status' => (int) MODULE_PAYMENT_LUNAR_AUTHORIZE_ORDER_STATUS_ID ), 'update', 'orders_id = "' . (int) $insert_id . '"' );

		// payment capture
		if ($this->isInstantMode) {
			$this->lunar_admin->capture( $insert_id, $data, true );
		}
	}

	/**
	 * 
	 */
	public function setArgs()
	{
		global $order;

		$this->order = $order;

		$this->args = [
			'integration' => [
                'key' => MODULE_PAYMENT_LUNAR_PUBLIC_KEY,
                'name' => MODULE_PAYMENT_LUNAR_SHOP_TITLE ?? STORE_NAME ?? '',
                'logo' => MODULE_PAYMENT_LUNAR_LOGO_URL,
			],
			'amount'     => [
				'currency' => $order->info['currency'],
				'decimal' => (string) $order->info['total'],
			],
			'custom' => [
				'email'   => $order->customer['email_address'],
				// 'orderId'    => '', // we don't have the order at this time
				'products'   => $this->getFormattedProducts(),
				'customer'   => [
					'name'    => $order->customer['firstname'] . ' ' . $order->billing['lastname'],
					'address' => $order->customer['street_address'] . ', ' . $order->customer['suburb'] . ', ' 
									. $order->customer['city'] . ', ' . $order->customer['state'] . ', ' 
									. $order->customer['country']['title'],
					'email'   => $order->customer['email_address'],
					'phoneNo' => $order->customer['telephone'],
					'ip'      => $_SERVER['REMOTE_ADDR']
				],
				'platform' => [
					'name' => 'ZenCart',
					'version' => PROJECT_VERSION_MAJOR . '.' . PROJECT_VERSION_MINOR . ( PROJECT_VERSION_PATCH1 ? 'p' . PROJECT_VERSION_PATCH1 : '' ),
				],
				'lunarPluginVersion' => LunarHelper::pluginVersion(),
			],
            'redirectUrl' => str_replace('&amp;', '&', zen_href_link(FILENAME_CHECKOUT_PROCESS, 'lunar_method=card', 'SSL')),
            'preferredPaymentMethod' => 'card',
        ];

        // if (defined('MODULE_PAYMENT_LUNAR_MOBILEPAY_CONFIGURATION_ID')) {
        //     $this->args['mobilePayConfiguration'] = [
        //         'configurationID' => MODULE_PAYMENT_LUNAR_MOBILEPAY_CONFIGURATION_ID,
        //         'logo' => MODULE_PAYMENT_LUNAR_MOBILEPAY_LOGO_URL,
        //     ];
        // }

        if ($this->testMode) {
            $this->args['test'] = $this->getTestObject();
        }
	}

	/**
	 * update payment method status based on the order
	 * disable payment for certain cases
	 *
	 * @global type $order
	 * @global type $db
	 */
	public function update_status()
	{
		global $order, $db;

		if (
			$this->enabled 
			&& (int) MODULE_PAYMENT_LUNAR_ZONE > 0 
			&& isset($order->delivery['country']['id'])
		) {
			$checkFlag = false;
			$sql       = "SELECT zone_id FROM " . TABLE_ZONES_TO_GEO_ZONES . " 
							WHERE geo_zone_id = '" . MODULE_PAYMENT_LUNAR_ZONE . "' 
							AND zone_country_id = '" . $order->delivery['country']['id'] . "' 
							ORDER BY zone_id";
			$result    = $db->Execute( $sql );

			if ( $result ) {
				while ( ! $result->EOF ) {
					if ( $result->fields['zone_id'] < 1 ) {
						$checkFlag = true;
						break;
					} elseif ( $result->fields['zone_id'] == $order->delivery['zone_id'] ) {
						$checkFlag = true;
						break;
					}
					$result->MoveNext();
				}
			}

			if ( $checkFlag == false ) {
				$this->enabled = false;
			}
		}
	}

	/** javascript validation */
	public function javascript_validation()
	{
		return '';
	}
	/** data used to display the module on the backend */
	public function selection()
	{
		return [
			'id' => $this->code,
			'module' => $this->title
		];
	}

	/**
	 * Evaluates the lunar configuration set properly
	 *
	 * @return void
	 */
	public function pre_confirmation_check()
	{
		global $messageStack;
		if ( MODULE_PAYMENT_LUNAR_APP_KEY == '' || MODULE_PAYMENT_LUNAR_PUBLIC_KEY == '' ) {
			$messageStack->add_session( FILENAME_CHECKOUT_PAYMENT, LUNAR_WARNING_NOT_CONFIGURED_FRONTEND . ' <!-- [' . $this->code . '] -->', 'error' );
			zen_redirect( zen_href_link( FILENAME_CHECKOUT_PAYMENT, '', 'SSL', true, false ) );
		}
	}

	/**
	 * Display Information on the Checkout Confirmation Page
	 * @return array
	 */
	public function confirmation()
	{
		return ['title' => $this->description];
	}

	/**
	 * Build the data and actions to process when the "Submit" button is pressed on the order-confirmation screen.
	 * This sends the data to the payment gateway for processing.
	 */
	public function process_button()
	{
		return '';
	}

	/**
	 * 
	 */
	private function getFormattedProducts()
	{
		$products = [];
		foreach ( $this->order->products as $product ) {
			$row        = [
				'ID'       => $product['id'],
				'name'     => $product['name'],
				'quantity' => isset( $product['quantity'] ) ? $product['quantity'] : $product['qty'],
			];
			$products[] = $row;
		}

		return str_replace("\u0022","\\\\\"", json_encode($products, JSON_HEX_QUOT));
	}

	/**
	 * @param $data
	 * @param $order_id
	 */
	private function insert_order_history( $data, $order_id )
	{
		// TABLE_ORDERS_STATUS_HISTORY
		$comments = LUNAR_COMMENT_AUTHORIZE . $data['transaction_id'] . "\n" . LUNAR_COMMENT_AMOUNT . $data['amount'];
		$data     = [
			'comments'          => $comments,
			'orders_id'         => (int) $order_id,
			'orders_status_id'  => (int) MODULE_PAYMENT_LUNAR_AUTHORIZE_ORDER_STATUS_ID,
			'customer_notified' => - 1,
			'date_added'        => 'NOW()',
			'updated_by'        => 'system'
		];
		zen_db_perform( TABLE_ORDERS_STATUS_HISTORY, $data );
	}

	/**
	 *
	 */
	public function redirectToCheckoutPage($errorMessage = null)
	{
		global $messageStack;

		// make sure we don't keep old payment id
		setcookie(LunarHelper::INTENT_KEY, '', 1);

		$errorMessage = $errorMessage ?? LUNAR_ERROR_INVALID_REQUEST;
		$messageStack->add_session( FILENAME_CHECKOUT_CONFIRMATION, $errorMessage, 'error' );
		zen_redirect( zen_href_link( FILENAME_CHECKOUT_CONFIRMATION, '', 'SSL', true, false ) );
	}

	/**
	 * @param $data
	 * @param $order_id
	 */
	public function insert_lunar_transaction( $data, $order_id )
	{
		$data = [
			'order_id'           => (int) $order_id,
			'transaction_id'     => $data['transaction_id'],
			'transaction_type'   => 'authorize',
			'order_amount'       => $data['amount'],
			'transaction_amount' => $data['amount'],
			'method_code' 		 => self::METHOD_CODE,
		];
		zen_db_perform( LunarHelper::LUNAR_DB_TABLE, $data );
	}

	/**
	 * Build admin-page components
	 *
	 * @param int $order_id
	 *
	 * @return string
	 */
	public function admin_notification( $order_id )
	{
		if ( ! defined( 'MODULE_PAYMENT_LUNAR_STATUS' ) ) {
			return '';
		}
		if ( empty($order_id) || $order_id < 1 ) {
			return '';
		}

		$actions = new lunar_admin_actions( $order_id );

		echo $actions->output();
	}

	/**
	 *
	 */
	public function get_error()
	{
		return ['error' => stripslashes(urldecode($_GET['error']))];
	}

	/**
	 * check function
	 */
	public function check()
	{
		global $db;
		if ( ! isset( $this->_check ) ) {
			$check_query  = $db->Execute( "SELECT configuration_value FROM " . TABLE_CONFIGURATION . " WHERE configuration_key = 'MODULE_PAYMENT_LUNAR_STATUS'" );
			$this->_check = $check_query->RecordCount();
		}

		return $this->_check;
	}

	/**
	 * Check and fix custom table
	 */
	public function tableCheckup()
	{
		global $sniffer;
		$tableExists = ( method_exists( $sniffer, 'table_exists' ) ) ? $sniffer->table_exists( LunarHelper::LUNAR_DB_TABLE ) : false;
		if ( $tableExists !== true ) {
			$this->lunar_admin->create_transactions_table();
		}
	}

	/**
	 * install lunar payment model
	 */
	public function install()
	{
		$this->lunar_admin->install();
	}

	/**
	 * remove module
	 */
	public function remove()
	{
		$this->lunar_admin->remove();
	}

	/**
	 * keys
	 */
	public function keys()
	{
		return $this->lunar_admin->keys();
	}

	/**
	 * Error Log
	 */
	public function debug( $error, $lineNo = 0, $file = '' )
	{
		lunar_debug( $error, $lineNo, $file );
	}

	/**
	 * Used to capture part or all of a given previously-authorized transaction.
	 * @see orders.php
	 * @return bool
	 */
	public function _doCapt( $order_id, $status = '', $amount, $currency)
	{
		$data = [
			'currency' => $currency,
			'amount' => (string) $amount,
		];
		return $this->lunar_admin->capture($order_id, $data);
	}

	/**
	 * Used to submit a refund for a given transaction.
	 * @see orders.php
	 * @return bool
	 */
	public function _doRefund( $order_id )
	{
		return $this->lunar_admin->refund( $order_id );
	}

	/**
	 * Used to void a given previously-authorized transaction.
	 * @see orders.php
	 * @return bool
	 */
	public function _doVoid( $order_id )
	{
		return $this->lunar_admin->void( $order_id );
	}

    /**
     *
     */
    private function getTestObject(): array
    {
        return [
            "card"        => [
                "scheme"  => "supported",
                "code"    => "valid",
                "status"  => "valid",
                "limit"   => [
                    "decimal"  => "25000.99",
                    "currency" => $this->order->info['currency'],
                    
                ],
                "balance" => [
                    "decimal"  => "25000.99",
                    "currency" => $this->order->info['currency'],
                    
                ]
            ],
            "fingerprint" => "success",
            "tds"         => array(
                "fingerprint" => "success",
                "challenge"   => true,
                "status"      => "authenticated"
            ),
        ];
    }

}