<?php


/**
 * Write debug information to log file
 *
 * @param        $error
 * @param int    $lineNo
 * @param string $file
 */
function lunar_debug( $error, $lineNo = 0, $file = '' ) {
	$lunar_instance_id = time();
	$logfilename         = 'includes/modules/payment/lunar/logs/lunar_' . $lunar_instance_id . '.log';
	if ( defined( 'DIR_FS_LOGS' ) ) {
		$logfilename = DIR_FS_LOGS . '/lunar__' . $lunar_instance_id . '.log';
	}
	$fp = @fopen( $logfilename, 'a' );
	@fwrite( $fp, date( 'M d Y G:i' ) . ' -- ' . $error . "\n File:" . $file . "\n Line:" . $lineNo . "\n\n" );
	@fclose( $fp );
}

?>