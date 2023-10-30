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
	/** @see includes/classes/payment.php */
    protected $_check;
    public $code;
    public $description;
    public $enabled;
	public $title;
	public $form_action_url;
	public $sort_order;
	
	public $args;
	public $order;
	private bool $testMode = false;
	
	/**
	 * constructor
	 */
	public function __construct()
	{
		$this->code            = 'lunar_card';
		$this->enabled         = defined( 'MODULE_PAYMENT_LUNAR_STATUS' ) && MODULE_PAYMENT_LUNAR_STATUS == 'True';
		$this->title           = MODULE_PAYMENT_LUNAR_TEXT_TITLE;
		$this->description     = MODULE_PAYMENT_LUNAR_TEXT_DESCRIPTION; // Descriptive Info about module in Admin
		// $this->form_action_url = '';

		$this->sort_order      = defined( 'MODULE_PAYMENT_LUNAR_SORT_ORDER' ) ? MODULE_PAYMENT_LUNAR_SORT_ORDER : 0; // Sort Order in the checkout page

		$this->order =  $GLOBALS['order'];
		$this->testMode = !!$_COOKIE['lunar_testmode'];

		if ( IS_ADMIN_FLAG === true ) {
			if ( MODULE_PAYMENT_LUNAR_APP_KEY == '' || MODULE_PAYMENT_LUNAR_PUBLIC_KEY == '' ) {
				$alertHtml   = '<span class="alert"> (' . LUNAR_WARNING_NOT_CONFIGURED . ')</span>';
				$this->title .= $alertHtml;
			}
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
	 * 
	 */
	public function setArgs()
	{
		$locale = ( $_SESSION['languages_code'] ) ? $_SESSION['languages_code'] : 'en_US';

		// construct payment payload
		$this->args = [
			'integration' => [
                'key' => MODULE_PAYMENT_LUNAR_PUBLIC_KEY,
                'name' => MODULE_PAYMENT_LUNAR_SHOP_TITLE ?? STORE_NAME ?? '',
                'logo' => MODULE_PAYMENT_LUNAR_LOGO_URL,
			],
			'amount'     => [
				'currency' => $this->order->info['currency'],
				'decimals' => $this->order->info['total'],
			],
			'custom' => [
				// 'orderId'    => '', // we don't have the order at this time
				'products'   => $this->getFormattedProducts(),
				'customer'   => [
					'name'    => $this->order->customer['firstname'] . ' ' . $this->order->billing['lastname'],
					'address' => $this->order->customer['street_address'] . ', ' . $this->order->customer['suburb'] . ', ' 
									. $this->order->customer['city'] . ', ' . $this->order->customer['state'] . ', ' 
									. $this->order->customer['country']['title'],
					'email'   => $this->order->customer['email_address'],
					'phoneNo' => $this->order->customer['telephone'],
					'ip'      => $_SERVER['REMOTE_ADDR']
				],
				'platform' => [
					'name' => 'ZenCart',
					'version' => PROJECT_VERSION_MAJOR . '.' . PROJECT_VERSION_MINOR . ( PROJECT_VERSION_PATCH1 ? 'p' . PROJECT_VERSION_PATCH1 : '' ),
				],
				'lunarPluginVersion' => LunarHelper::pluginVersion(),
			],
            'redirectUrl' => zen_href_link(FILENAME_CHECKOUT_CONFIRMATION, '', 'SSL'),
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

		if ( $this->enabled && (int) MODULE_PAYMENT_LUNAR_ZONE > 0 && isset( $this->order->delivery['country']['id'] ) ) {
			$checkFlag = false;
			$sql       = "select zone_id from " . TABLE_ZONES_TO_GEO_ZONES . " where geo_zone_id = '" . MODULE_PAYMENT_LUNAR_ZONE . "' and zone_country_id = '" . $this->order->delivery['country']['id'] . "' order by zone_id";
			$result    = $db->Execute( $sql );

			if ( $result ) {
				while ( ! $result->EOF ) {
					if ( $result->fields['zone_id'] < 1 ) {
						$checkFlag = true;
						break;
					} elseif ( $result->fields['zone_id'] == $this->order->delivery['zone_id'] ) {
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

	/**
	 * javascript validation.
	 *
	 * @return string
	 */
	public function javascript_validation()
	{
		$js = '';

		return $js;
	}

	/**
	 * data used to display the module on the backend
	 *
	 * @return array
	 */
	public function selection()
	{
		$selection = array( 'id' => $this->code, 'module' => $this->title );

		return $selection;
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
			$messageStack->add_session( 'checkout_payment', LUNAR_WARNING_NOT_CONFIGURED_FRONTEND . ' <!-- [' . $this->code . '] -->', 'error' );
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
	 *
	 */
	public function process_button()
	{
		return '';
		// return '<script type="text/javascript">' . "\n" .
		// 		'$(window).on("load", function() { ' . "\n" .
		// 		'	$("#btn_submit").attr("type", "button").attr("onclick", "pay(event)") ' . "\n" .
		// 		'}) ' . "\n\n" .
		// 		'</script>';
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
	 *
	 */
	public function before_process()
	{
		global $messageStack, $currencies;

		// if ( empty($_POST['txn_no']) ) {
		// 	$messageStack->add_session( 'checkout_payment', LUNAR_ORDER_ERROR_TRANSACTION_MISSING . ' <!-- [' . $this->code . '] -->', 'error' );
		// 	zen_redirect( zen_href_link( FILENAME_CHECKOUT_PAYMENT, '', 'SSL', true, false ) );

		// 	return;
		// }
		// transaction history
		// $lunar_admin = new lunar_admin();
		// $response    = $lunar_admin->getTransactionHistory( $_POST['txn_no'] );
		// if ( !$response ) {
		// 	$messageStack->add_session( 'checkout_payment', LUNAR_ORDER_ERROR_TRANSACTION_MISMATCH . ' <!-- [' . $this->code . '] -->', 'error' );
		// 	zen_redirect( zen_href_link( FILENAME_CHECKOUT_PAYMENT, '', 'SSL', true, false ) );

		// 	return;
		// }

		// if ( $response['amount']['decimal'] != $this->order->info['total'] ) {
		// 	$messageStack->add_session( 'checkout_payment', LUNAR_ORDER_ERROR_TRANSACTION_AMOUNT_MISMATCH . ' <!-- [' . $this->code . '] -->', 'error' );
		// 	zen_redirect( zen_href_link( FILENAME_CHECKOUT_PAYMENT, '', 'SSL', true, false ) );

		// 	return;
		// }
	}

	/**
	 * Post-processing activities
	 * Update order, capture if needed, store tr
	 */
	public function after_process()
	{
		global $insert_id;

		$data = [
			'transaction_id' => $_POST['txn_no'],
			'amount'         => $this->order->info['total'],
			'currency'       => $this->order->info['currency'],
		];

		$this->update_transaction_records( $data, $insert_id );

		$this->update_order_history( $data, $insert_id );

		// update order status
		zen_db_perform( TABLE_ORDERS, array( 'orders_status' => (int) MODULE_PAYMENT_LUNAR_AUTHORIZE_ORDER_STATUS_ID ), 'update', 'orders_id = "' . (int) $insert_id . '"' );

		// payment capture
		if ( MODULE_PAYMENT_LUNAR_CAPTURE_MODE === 'Instant' ) {
			$lunar_admin = new lunar_admin();
			$lunar_admin->capture( $insert_id, 'Complete', $this->order->info['total'], $data['currency'], '', true );
		}
	}

	/**
	 * @param $data
	 * @param $order_id
	 */
	public function update_order_history( $data, $order_id
	)
	{
		// TABLE_ORDERS_STATUS_HISTORY
		$comments = LUNAR_COMMENT_AUTHORIZE . $data['transaction_id'] . "\n" . LUNAR_COMMENT_AMOUNT . $this->order->info['total'];
		$sql1     = [
			'comments'          => $comments,
			'orders_id'         => (int) $order_id,
			'orders_status_id'  => (int) MODULE_PAYMENT_LUNAR_AUTHORIZE_ORDER_STATUS_ID,
			'customer_notified' => - 1,
			'date_added'        => $data['time'],
			'updated_by'         => 'system'
		];
		zen_db_perform( TABLE_ORDERS_STATUS_HISTORY, $sql1 );
	}

	/**
	 * @param $data
	 * @param $order_id
	 */
	public function update_transaction_records( $data, $order_id )
	{
		$data = [
			'order_id'           => (int) $order_id,
			'transaction_id'     => $data['transaction_id'],
			'transaction_type'   => 'authorize',
			'time'               => date( "Y-m-d h:i:s" ),
			'method_code' 		 => 'lunar_card',
		];
		zen_db_perform( 'lunar', $data );
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
		if ( $order_id == '' || $order_id < 1 ) {
			return '';
		}

		$actions = new lunar_admin_actions( $order_id );

		echo $actions->output();
	}

	/**
	 * get error
	 *
	 * @return type
	 */
	function get_error()
	{
		$error = array( 'error' => stripslashes( urldecode( $_GET['error'] ) ) );

		return $error;
	}

	/**
	 * check function
	 *
	 * @global type $db
	 * @return type
	 */
	public function check()
	{
		global $db;
		if ( ! isset( $this->_check ) ) {
			$check_query  = $db->Execute( "select configuration_value from " . TABLE_CONFIGURATION . " where configuration_key = 'MODULE_PAYMENT_LUNAR_STATUS'" );
			$this->_check = $check_query->RecordCount();
		}

		return $this->_check;
	}

	/**
	 * Check and fix table 'lunar'
	 */
	public function tableCheckup()
	{
		global $sniffer;
		$tableOkay = ( method_exists( $sniffer, 'table_exists' ) ) ? $sniffer->table_exists( LunarHelper::LUNAR_DB_TABLE ) : false;
		if ( $tableOkay !== true ) {
			$lunar_admin = new lunar_admin();
			$lunar_admin->create_transactions_table();
		}
	}

	/**
	 * install lunar payment model
	 *
	 * @global type $db
	 */
	public function install()
	{
		$lunar_admin = new lunar_admin();
		$lunar_admin->install();
	}

	/**
	 * remove module
	 *
	 * @global type $db
	 */
	public function remove()
	{
		$lunar_admin = new lunar_admin();
		$lunar_admin->remove();
	}

	/**
	 * keys
	 *
	 * @return type
	 */
	public function keys()
	{
		$lunar_admin = new lunar_admin();

		return $lunar_admin->keys();
	}

	/**
	 * Error Log
	 *
	 * @param        $error
	 * @param int    $lineNo
	 * @param string $file
	 */
	public function debug( $error, $lineNo = 0, $file = '' )
	{
		lunar_debug( $error, $lineNo, $file );
	}

	/**
	 * Used to capture part or all of a given previously-authorized transaction.
	 *
	 * @param        $order_id
	 * @param string $captureType
	 * @param int    $amt
	 * @param string $currency
	 * @param string $note
	 *
	 * @return bool
	 */
	public function _doCapt( $order_id, $captureType = 'Complete', $amt = 0, $currency = 'USD', $note = '' )
	{
		$lunar_admin = new lunar_admin();

		return $lunar_admin->capture( $order_id, $captureType, $amt, $currency, $note );
	}

	/**
	 * Used to submit a refund for a given transaction.
	 *
	 * @param        $order_id
	 * @param string $amount
	 * @param string $note
	 *
	 * @return bool
	 */
	public function _doRefund( $order_id, $amount = 'Full', $note = '' )
	{
		$lunar_admin = new lunar_admin();

		return $lunar_admin->refund( $order_id, $amount, $note );
	}

	/**
	 * Used to void a given previously-authorized transaction.
	 *
	 * @param        $order_id
	 * @param string $note
	 *
	 * @return bool
	 */
	public function _doVoid( $order_id, $note = '' )
	{
		$lunar_admin = new lunar_admin();

		return $lunar_admin->void( $order_id, $note );
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