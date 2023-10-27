<?php
/**
 *  Zencart Admin Configuration
 *  Copyright (c) 2022 Lunar
 */

class lunar_admin_actions {

	public $output;
	public $order_id;
	public $app_id;
	public $fields;
	public $capture_total;
	public $refund_total;
	public $void_total;
	public $amount_total;

	/**
	 * lunar_admin_actions constructor.
	 *
	 * @param $order_id
	 * @param $app_id
	 */
	public function __construct( $order_id, $app_id ) {
		$this->order_id = $order_id;
		$this->app_id   = $app_id;
		$this->set_transaction_history()->set_totals();
	}


	/**
	 * @return string
	 */
	public function output() {
		if ( ! $this->fields['transaction_id'] ) {
			return '<div class="alert alert-danger">' . LUNAR_ORDER_ERROR_TRANSACTION_FAILURE . '</div>';
		}


		// prepare output based on suitable content components
		$output = '<!-- BOF: pl admin transaction processing tools -->';
		$output .= $this->get_start_block();
		$output .= $this->get_table();
		$output .= $this->get_end_block();
		$output .= $this->get_capture_form();
		$output .= $this->get_refund_form();
		$output .= $this->get_void_form();
		$output .= '<!-- EOF: pl admin transaction processing tools -->';


		return $output;
	}

	/**
	 * @return string
	 */
	private function get_start_block() {
		$start_html = '<table class="table noprint" style="width:100%;border-style:dotted;">' . "\n";

		return $this->get_javascript() . $start_html;
	}

	/**
	 * @return string
	 */
	private function get_end_block() {
		return '</table>' . "\n";
	}

	/**
	 * @return string
	 */
	private function get_void_form() {
		$void_form = '</div><div class="row lunar_form">' . "\n";
		$void_form .= zen_draw_form( 'plvoid', FILENAME_ORDERS, zen_get_all_get_params( array( 'action' ) ) . 'action=doVoid', 'post', 'id="void_form"', true ) . zen_hide_session_id() . "\n";
		$void_form .= '</form>' . "\n";

		return $void_form;
	}

	/**
	 * @return string
	 */
	private function get_refund_form() {
		$output_refund = '</div><div class="row lunar_form" id="lunar_refundForm">' . "\n";
		$output_refund .= '<table class="table noprint" style="width:100%;border-style:dotted;">' . "\n";
		$output_refund .= '<tr>' . "\n";
		$output_refund .= '<td class="main">' . '<strong>'.LUNAR_REFUND_SECTION_TITLE.'</strong>' . '<br />' . "\n";
		$output_refund .= zen_draw_form( 'plrefund', FILENAME_ORDERS, zen_get_all_get_params( array( 'action' ) ) . 'action=doRefund', 'post', '', true ) . zen_hide_session_id();

		// full refund
		if ( $this->refund_total == 0 ) {
			$output_refund .= LUNAR_ACTION_FULL_REFUND;
			$output_refund .= '<br /><input type="submit" name="fullrefund" value="' . LUNAR_REFUND_BUTTON_TEXT_FULL . '" title="' . LUNAR_REFUND_BUTTON_TEXT_FULL . '" />' . '<br /><br />';
			$output_refund .= LUNAR_REFUND_TEXT_FULL_OR;
		}

		//partial refund - input field
		$output_refund .= LUNAR_REFUND_PARTIAL_TEXT . ' ' . zen_draw_input_field( 'refamt', '', 'length="8" placeholder="'.LUNAR_REFUND_AMOUNT_TEXT.'"' );
		$output_refund .= '<input type="submit" name="partialrefund" value="' . LUNAR_REFUND_BUTTON_TEXT_PARTIAL . '" title="' . LUNAR_REFUND_BUTTON_TEXT_PARTIAL . '" /><br />';
		$output_refund .= zen_draw_hidden_field( 'refundedAmt', $this->refund_total ) . '<br />';

		//message text
		$output_refund .= '</form>';
		$output_refund .= '</td></tr></table>' . "\n";

		return $output_refund;
	}

	/**
	 * @return string
	 */
	private function get_capture_form() {
		$output_capture = '</div><div class="row lunar_form">' . "\n";
		$output_capture .= zen_draw_form( 'plcapture', FILENAME_ORDERS, zen_get_all_get_params( array( 'action' ) ) . 'action=doCapture', 'post', 'id="capture_form"', true ) . zen_hide_session_id() . "\n";
		$output_capture .= '</form>' . "\n";

		return $output_capture;
	}

	/**
	 * @return string
	 */
	private function get_table() {
		$payment_status = [
			'authorize'      => LUNAR_STATUS_AUTHORIZED,
			'capture'        => LUNAR_STATUS_CAPTURED,
			'partial_refund' => LUNAR_STATUS_PARTIALLY_REFUNDED,
			'refund'         => LUNAR_STATUS_REFUNDED,
			'void'           => LUNAR_STATUS_CANCELLED
		];

		$table = '<tr class="dataTableHeadingRow">' . "\n";
		$table .= '<th class="dataTableHeadingContent">' . LUNAR_TEXT_TXN_ID . '</th>' . "\n";
		$table .= '<th class="dataTableHeadingContent">' . LUNAR_TEXT_PAYMENT_STATUS . '</th>' . "\n";
		$table .= '<th class="dataTableHeadingContent">' . nl2br( LUNAR_TEXT_PAYMENT_DATE, false ) . '</th>' . "\n";
		$table .= '<th class="dataTableHeadingContent">' . LUNAR_TEXT_ACTION . '</th>' . "\n";
		$table .= '</tr>' . "\n";
		// values
		$table .= '<tr class="dataTableRow">' . "\n";
		$table .= '<td class="dataTableContent">' . $this->fields['transaction_id'] . '</td>' . "\n";
		$table .= '<td class="dataTableContent">' . $payment_status[ $this->fields['transaction_status'] ] . '</td>' . "\n";
		$table .= '<td class="dataTableContent">' . $this->fields['time'] . '</td>' . "\n";
		$table .= '<td class="dataTableContent">' . $this->get_buttons_list() . '</td>' . "\n";
		$table .= '</tr>' . "\n";

		return $table;
	}

	/**
	 * @return string
	 */
	private function get_buttons_list() {
		$buttons_list   = '';
		$capture_button = '<a href="javascript:void(0)" id="capture_click" title="'.LUNAR_CAPTURE_BUTTON_TEXT_FULL.'" style="padding-right:10px;"><i class="fa fa-check-circle" style="margin-right:5px;" aria-hidden="true"></i>'.LUNAR_CAPTURE_BUTTON_TEXT_FULL.'</a>';
		$refund_button  = '<a href="javascript:void(0)" id="refund_click" title="'.LUNAR_REFUND_BUTTON_TEXT.'" style="padding-right:10px;"><i class="fa fa-reply-all" style="margin-right:5px;" aria-hidden="true"></i>'.LUNAR_REFUND_BUTTON_TEXT.'</a>';
		$void_button    = '<a href="javascript:void(0)" id="void_click" title="'.LUNAR_VOID_BUTTON_TEXT_FULL.'" style="padding-right:10px;"><i class="fa fa-times-circle" style="margin-right:5px;" aria-hidden="true"></i>'.LUNAR_VOID_BUTTON_TEXT_FULL.'</a>';
		if ( ! $this->capture_total && ! $this->refund_total && ! $this->void_total ) {
			$buttons_list .= $capture_button . $void_button;
		}
		if ( $this->capture_total && $this->void_total != $this->amount_total && $this->refund_total < $this->amount_total ) {
			$buttons_list .= $refund_button;
		}

		return $buttons_list;
	}

	/**
	 * @return string
	 */
	private function get_javascript() {
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
	 * @return $this
	 */
	private function set_totals() {
		$lunar_admin      = new lunar_admin();
		$response           = $lunar_admin->getTransactionHistory( $this->app_id, $this->fields['transaction_id'] );
		$this->amount_total = $response['amount'];

		if ( ! ( isset( $response['trail'] ) && is_array( $response['trail'] ) && sizeof( $response['trail'] ) ) ) {
			return $this;
		}

		// loop trough all transactions and add up the amounts
		foreach ( $response['trail'] as $key => $value ) {
			if ( isset( $value['capture'] ) ) {
				$this->capture_total += $value['amount'];
			}
			if ( isset( $value['refund'] ) ) {
				$this->refund_total += $value['amount'];
			}
			if ( isset( $value['void'] ) ) {
				$this->refund_total += $value['amount'];
			}
		}

		return $this;
	}

	/**
	 * @return $this
	 */
	private function set_transaction_history() {
		global $db;
		$sql     = "select * from lunar where order_id = '" . (int) $this->order_id . "'";
		$history = $db->Execute( $sql );

		if ( !$history->RecordCount() > 0 ) {
			return $this;
		}
		$this->fields = $history->fields;
		// strip slashes in case they were added to handle apostrophes:
		foreach ( $this->fields as $key => $value ) {
			$this->fields[ $key ] = stripslashes( $value );
		}

		return $this;

	}
}