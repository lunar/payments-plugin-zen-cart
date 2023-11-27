<?php

use Lunar\Payment\helpers\LunarHelper;

/**
 * 
 */
class lunar_admin_actions {

	public $output;
	public $order_id;
	public $fields;
	public $total_captured = 0;
	public $total_refunded = 0;
	public $total_voided = 0;
	public $refund_remaining = null;
	public $amount_total;
	private $lunar_admin;

	/**
	 * constructor
	 * @param $order_id
	 */
	public function __construct( $order_id, $paymentMethodCode )
	{
		$this->order_id = $order_id;
		$this->lunar_admin = new lunar_admin($paymentMethodCode);
		$this->set_transaction_history()->set_totals();
	}


	/**
	 * @return string
	 */
	public function output()
	{
		if ( ! $this->fields['transaction_id'] ) {
			return '<div class="alert alert-danger">' . LUNAR_ORDER_ERROR_TRANSACTION_FAILURE . '</div>';
		}


		// prepare output based on suitable content components
		$output = '<!-- BOF: lunar admin transaction processing tools -->';
		$output .= $this->get_start_block();
		$output .= $this->get_table();
		$output .= $this->get_end_block();
		$output .= $this->get_capture_form();
		$output .= $this->get_refund_form();
		$output .= $this->get_void_form();
		$output .= '<!-- EOF: lunar admin transaction processing tools -->';


		return $output;
	}

	/**
	 * @return string
	 */
	private function get_start_block()
	{
		$start_html = '<table class="table noprint" style="width:100%;border-style:dotted;">' . "\n";

		return $this->get_javascript() . $start_html;
	}

	/**
	 * @return string
	 */
	private function get_end_block()
	{
		return '</table>' . "\n";
	}

	/**
	 * @return string
	 */
	private function get_void_form()
	{
		$void_form = '</div><div class="row lunar_form">' . "\n";
		$void_form .= zen_draw_form( 'lunar-void', FILENAME_ORDERS, zen_get_all_get_params( array( 'action' ) ) . 'action=doVoid', 'post', 'id="void_form"', true ) . zen_hide_session_id() . "\n";
		$void_form .= '</form>' . "\n";

		return $void_form;
	}

	/**
	 * @return string
	 */
	private function get_refund_form()
	{
		if (!$this->refund_remaining) {
			return '';
		}

		$output_refund = '</div><div class="row lunar_form" id="lunar_refundForm">' . "\n";
		$output_refund .= '<table class="table noprint" style="width:100%;border-style:dotted;">' . "\n";
		$output_refund .= '<tr>' . "\n";
		$output_refund .= '<td class="main">' . '<strong>'.LUNAR_REFUND_SECTION_TITLE.'</strong>' . '<br />' . "\n";
		$output_refund .= zen_draw_form( 'lunar-refund', FILENAME_ORDERS, zen_get_all_get_params( array( 'action' ) ) . 'action=doRefund', 'post', '', true ) . zen_hide_session_id();

		// full refund
		if ( $this->total_refunded == 0 ) {
			$output_refund .= LUNAR_ACTION_FULL_REFUND;
			$output_refund .= '<br /><input type="submit" name="fullrefund" value="' . LUNAR_REFUND_BUTTON_TEXT_FULL . '" title="' . LUNAR_REFUND_BUTTON_TEXT_FULL . '" />' . '<br /><br />';
			$output_refund .= LUNAR_REFUND_TEXT_FULL_OR;
		}

		//partial refund - input field
		$output_refund .= LUNAR_REFUND_PARTIAL_TEXT . ' ' . zen_draw_input_field( 'refamt', '', 'length="8" placeholder="'.LUNAR_REFUND_AMOUNT_TEXT.'" value="' . $this->refund_remaining .'"' );
		$output_refund .= '<input type="submit" name="partialrefund" value="' . LUNAR_REFUND_BUTTON_TEXT_PARTIAL . '" title="' . LUNAR_REFUND_BUTTON_TEXT_PARTIAL . '" /><br />';
		$output_refund .= zen_draw_hidden_field( 'refundedAmt', $this->total_refunded ) . '<br />';

		//message text
		$output_refund .= '</form>';
		$output_refund .= '</td></tr></table>' . "\n";

		return $output_refund;
	}

	/**
	 * @return string
	 */
	private function get_capture_form()
	{
		$output_capture = '</div><div class="row lunar_form">' . "\n";
		$output_capture .= zen_draw_form( 'lunar-capture', FILENAME_ORDERS, zen_get_all_get_params( array( 'action' ) ) . 'action=doCapture', 'post', 'id="capture_form"', true ) . zen_hide_session_id() . "\n";
		$output_capture .= '</form>' . "\n";

		return $output_capture;
	}

	/**
	 * @return string
	 */
	private function get_table()
	{
		$table = '<tr class="dataTableHeadingRow">' . "\n";
		$table .= '<th class="dataTableHeadingContent">' . LUNAR_TEXT_TXN_ID . '</th>' . "\n";
		$table .= '<th class="dataTableHeadingContent">' . LUNAR_TEXT_PAYMENT_STATUS . '</th>' . "\n";
		$table .= '<th class="dataTableHeadingContent">' . nl2br( LUNAR_TEXT_PAYMENT_DATE, false ) . '</th>' . "\n";
		$table .= '<th class="dataTableHeadingContent">' . LUNAR_TEXT_ACTION . '</th>' . "\n";
		$table .= '</tr>' . "\n";
		// values
		$table .= '<tr class="dataTableRow">' . "\n";
		$table .= '<td class="dataTableContent">' . $this->fields['transaction_id'] . '</td>' . "\n";
		$table .= '<td class="dataTableContent">' . LunarHelper::PAYMENT_TYPES[ $this->fields['transaction_type'] ] . '</td>' . "\n";
		$table .= '<td class="dataTableContent">' . $this->fields['created_at'] . '</td>' . "\n";
		$table .= '<td class="dataTableContent">' . $this->get_buttons_list() . '</td>' . "\n";
		$table .= '</tr>' . "\n";

		return $table;
	}

	/**
	 * @return string
	 */
	private function get_buttons_list()
	{
		$buttons_list   = '';
		$capture_button = '<a href="javascript:void(0)" id="capture_click" title="'.LUNAR_CAPTURE_BUTTON_TEXT_FULL.'" style="padding-right:10px;"><i class="fa fa-check-circle" style="margin-right:5px;" aria-hidden="true"></i>'.LUNAR_CAPTURE_BUTTON_TEXT_FULL.'</a>';
		$refund_button  = '<a href="javascript:void(0)" id="refund_click" title="'.LUNAR_REFUND_BUTTON_TEXT.'" style="padding-right:10px;"><i class="fa fa-reply-all" style="margin-right:5px;" aria-hidden="true"></i>'.LUNAR_REFUND_BUTTON_TEXT.'</a>';
		$void_button    = '<a href="javascript:void(0)" id="void_click" title="'.LUNAR_VOID_BUTTON_TEXT_FULL.'" style="padding-right:10px;"><i class="fa fa-times-circle" style="margin-right:5px;" aria-hidden="true"></i>'.LUNAR_VOID_BUTTON_TEXT_FULL.'</a>';

		if ( ! $this->total_captured && ! $this->total_refunded && ! $this->total_voided ) {
			$buttons_list .= $capture_button . $void_button;
		}

		if ( $this->total_captured && $this->refund_remaining) {
			$buttons_list .= $refund_button;
		}
		
		return $buttons_list;
	}

	/**
	 * @return string
	 */
	private function get_javascript()
	{
		return '<script>
				    $(window).on("load", function() {
				      $(".lunar_form").hide();
				      $(document).on("click", "#refund_click", function () {
				        $("#lunar_refundForm").toggle();
				      });
				      $(document).on("click", "#capture_click", function () {
				        $("#capture_form").submit();
				      });
				      $(document).on("click", "#void_click", function () {
				        $("#void_form").submit();
				      });
				    });
				  </script>
				  ';
	}

	/**
	 * @return self
	 */
	private function set_totals()
	{
		$this->amount_total = $this->fields['order_amount'];

		$transactionType = $this->fields['transaction_type'];
		$transactionAmount = $this->fields['transaction_amount'];
		

		if (in_array($transactionType, ['partial_refund', 'capture', 'refund'])) {
			$this->total_captured = $this->amount_total;
		}
		if ('partial_refund' == $transactionType) {
			$this->total_refunded = $transactionAmount;
			$this->refund_remaining = $this->amount_total - $transactionAmount;
		}
		if ('capture' == $transactionType) {
			$this->refund_remaining = $this->amount_total;
		}
		if ('refund' == $transactionType) {
			$this->total_refunded = $this->amount_total;
		}
		if ('void' == $transactionType) {
			$this->total_voided = $transactionAmount;
		}

		return $this;
	}

	/**
	 * @return self
	 */
	private function set_transaction_history()
	{
		$transaction = $this->lunar_admin->get_transaction_by_order_id($this->order_id);

		if ( !$transaction ) {
			return $this;
		}
		$this->fields = $transaction->fields;
		// strip slashes in case they were added to handle apostrophes:
		foreach ( $this->fields as $key => $value ) {
			$this->fields[ $key ] = stripslashes( $value );
		}

		return $this;

	}
}