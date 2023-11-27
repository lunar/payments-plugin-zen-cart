<?php
/** Prevent double load of API SDK library. */
if (! class_exists('Lunar\\Lunar') ) {
	require_once(__DIR__ . '/vendor/autoload.php' );
}
require_once(__DIR__ . '/lunar_admin.php' );
require_once(__DIR__ . '/lunar_admin_actions.php' );

use Lunar\Payment\helpers\LunarHelper;

/**
 *
 */
abstract class lunar_abstract_method extends base
{
	const METHOD_CODE = '';

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
	private array $lunarData = [];
	private $lunar_admin;
	
	/**
	 * constructor
	 */
	public function __construct()
	{
		$this->lunar_admin     = new lunar_admin(static::METHOD_CODE);
		$status = $this->getConfig('STATUS');
		$sortOrder = $this->getConfig('SORT_ORDER');

		$this->code            = LunarHelper::LUNAR_METHODS[static::METHOD_CODE];
		$this->enabled         = $status == 'True';
		$this->title           = $this->getConfig('TITLE');
		$this->description     = $this->getConfig('DESCRIPTION');
		$this->sort_order      = $sortOrder ?? 0; // Sort Order in the checkout page
		$this->order 		   =  $GLOBALS['order'];
		$this->testMode 	   = !!$_COOKIE['lunar_testmode'];
		$this->isInstantMode   = $this->getConfig('CAPTURE_MODE') === 'Instant';

		if ( IS_ADMIN_FLAG === true ) {
			if ( $this->getConfig('APP_KEY') == '' || $this->getConfig('PUBLIC_KEY') == '' ) {
				$alertHtml   = '<span class="alert"> (' . LUNAR_WARNING_NOT_CONFIGURED . ')</span>';
				$this->title .= $alertHtml;
			}

			$this->title = $this->getConfig('ADMIN_TITLE');
			$this->description = $this->getConfig('ADMIN_DESCRIPTION');

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
		global $order;

		$paymentIntentId = $this->lunar_admin->getPaymentIntentCookie();

		if (!isset($_GET['lunar_method'])) {
			
			$this->setArgs();

			/** Another check to see if order total is different from payment */
			if ($paymentIntentId) {
				$response = $this->lunar_admin->fetchApiTransaction($paymentIntentId);
				if (is_array($response) && isset($response['amount'])) {
					if ($response['amount']['decimal'] != $this->args['amount']['decimal']) {
						$paymentIntentId = null;
					}
				} else if ($response) {
					$paymentIntentId = null;
				}
			}
			
			if (!$paymentIntentId) {
				$paymentIntentId = $this->lunar_admin->createPaymentIntent($this->args);
				$this->lunar_admin->savePaymentIntentCookie($paymentIntentId);
			}
			
			if ( !$paymentIntentId ) {
				$this->redirectToCheckoutPage();
			}
			
			zen_redirect(($this->testMode ? self::TEST_REMOTE_URL : self::REMOTE_URL) . $paymentIntentId);
		}
		
		/**
		 * run the following only after redirect
		 */
	
		if (!$paymentIntentId) {
			$this->redirectToCheckoutPage(LUNAR_ORDER_ERROR_PAYMENT_INTENT_NOT_FOUND);
		}

		$apiResponse = $this->lunar_admin->fetchApiTransaction($paymentIntentId);

		if (!is_array($apiResponse)) {
			$this->redirectToCheckoutPage($apiResponse);
		}

		if (
			isset($apiResponse['amount'])
			&&
			($apiResponse['amount']['decimal'] != $order->info['total'] 
			|| $apiResponse['amount']['currency'] != $order->info['currency'])
		) {
			$this->redirectToCheckoutPage(LUNAR_ORDER_ERROR_AMOUNT_CURRENCY_MISMATCH);
		}

		$this->lunarData = [
			'transaction_id' => $paymentIntentId,
			'currency'       => $apiResponse['amount']['currency'],
			'amount'         => $apiResponse['amount']['decimal'],
		];

		setcookie(LunarHelper::INTENT_KEY, '', 1);
	}

	/**
	 * Post-processing activities
	 * Update order, capture if needed, store transaction
	 */
	public function after_process()
	{
		global $insert_id;

		$this->insert_lunar_transaction( $this->lunarData, $insert_id );

		$this->insert_order_history( $this->lunarData, $insert_id );

		zen_db_perform( TABLE_ORDERS, array( 'orders_status' => (int) $this->getConfig('AUTHORIZE_ORDER_STATUS_ID') ), 'update', 'orders_id = "' . (int) $insert_id . '"' );

		// payment capture
		if ($this->isInstantMode) {
			$this->lunar_admin->capture( $insert_id, $this->lunarData, true );
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
                'key' => $this->getConfig('PUBLIC_KEY'),
                'name' => $this->getConfig('SHOP_TITLE') ?? STORE_NAME ?? '',
                'logo' => $this->getConfig('LOGO_URL'),
			],
			'amount'     => [
				'currency' => $order->info['currency'],
				'decimal' => (string) $order->info['total'],
			],
			'custom' => [
				// 'orderId'    => '', // we don't have the order at this time, so use email
				'email'   => $order->customer['email_address'],
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
            'redirectUrl' => str_replace('&amp;', '&', zen_href_link(FILENAME_CHECKOUT_PROCESS, 'lunar_method=' . static::METHOD_CODE, 'SSL')),
            'preferredPaymentMethod' => static::METHOD_CODE,
        ];

        if ($this->getConfig('CONFIGURATION_ID')) {
            $this->args['mobilePayConfiguration'] = [
                'configurationID' => $this->getConfig('CONFIGURATION_ID'),
                'logo' => $this->getConfig('LOGO_URL'),
            ];
        }

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
			&& (int) $this->getConfig('ZONE') > 0 
			&& isset($order->delivery['country']['id'])
		) {
			$checkFlag = false;
			$sql       = "SELECT zone_id FROM " . TABLE_ZONES_TO_GEO_ZONES . " 
							WHERE geo_zone_id = '" . $this->getConfig('ZONE') . "' 
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
		if ( $this->getConfig('APP_KEY') == '' || $this->getConfig('PUBLIC_KEY') == '' ) {
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
		$comments = LUNAR_COMMENT_AUTHORIZE . $data['transaction_id'] . "\n" 
					. LUNAR_COMMENT_AMOUNT . $data['amount'] . ' ' . $data['currency'];
		$data     = [
			'comments'          => $comments,
			'orders_id'         => (int) $order_id,
			'orders_status_id'  => (int) $this->getConfig('AUTHORIZE_ORDER_STATUS_ID'),
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
		global $order;

		$data = [
			'order_id'           => (int) $order_id,
			'transaction_id'     => $data['transaction_id'],
			'transaction_type'   => 'authorize',
			'order_amount'       => $order->info['total'],
			'transaction_amount' => $data['amount'],
			'method_code' 		 => static::METHOD_CODE,
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
		if ( ! $this->getConfig('STATUS' ) ) {
			return '';
		}
		if ( empty($order_id) || $order_id < 1 ) {
			return '';
		}

		$actions = new lunar_admin_actions( $order_id, static::METHOD_CODE );

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
			$check_query  = $db->Execute( "SELECT configuration_value FROM " . TABLE_CONFIGURATION . " WHERE configuration_key = '" . $this->keys(true)['STATUS'] . "'" );
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
	public function keys($associative = false)
	{
		return $this->lunar_admin->keys($associative);
	}

	/**
	 * Error Log
	 */
	public function debug( $error, $lineNo = 0, $file = '' )
	{
		LunarHelper::writeLog( $error, $lineNo, $file );
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
	private function getConfig($key)
	{
		return $this->lunar_admin->getConfig($key);
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