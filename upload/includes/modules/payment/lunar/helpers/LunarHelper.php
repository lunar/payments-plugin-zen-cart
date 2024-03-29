<?php

namespace Lunar\Payment\helpers;

class LunarHelper
{
    const LUNAR_DB_TABLE = DB_PREFIX . 'lunar_transactions';

    const LUNAR_METHODS = [
        'card' => 'lunar_card',
        'mobilePay' => 'lunar_mobilepay',
    ];

    const LUNAR_CARD_CODE = 'card';
    const LUNAR_CARD_CONFIG_CODE = 'MODULE_PAYMENT_LUNAR_CARD_';

    const LUNAR_MOBILEPAY_CODE = 'mobilePay';
    const LUNAR_MOBILEPAY_CONFIG_CODE = 'MODULE_PAYMENT_LUNAR_MOBILEPAY_';

    const INTENT_KEY = '_lunar_intent_id'; 

    const PAYMENT_TYPES = [
        'authorize'      => LUNAR_STATUS_AUTHORIZED,
        'capture'        => LUNAR_STATUS_CAPTURED,
        'partial_refund' => LUNAR_STATUS_PARTIALLY_REFUNDED,
        'refund'         => LUNAR_STATUS_REFUNDED,
        'void'           => LUNAR_STATUS_CANCELLED
    ];

    public static function pluginVersion()
    {
        return json_decode(file_get_contents(dirname(__DIR__) . '/composer.json'))->version;
    }

    /**
     * Write debug information to log file
     *
     * @param        $error
     * @param int    $lineNo
     * @param string $file
     */
    public static function writeLog( $error, $lineNo = 0, $file = '' ) {
        $dateTime = date('Y-m-d_H-i-s');
        $logfilename = dirname(__DIR__, 5) . '/includes/modules/payment/lunar/logs/lunar_' . $dateTime . '.log';
        if ( defined( 'DIR_FS_LOGS' ) ) {
            $logfilename = DIR_FS_LOGS . '/lunar__' . $dateTime . '.log';
        }
        $fp = @fopen( $logfilename, 'a' );
        @fwrite( $fp, date( 'M d Y G:i' ) . ' -- ' . $error . "\n File:" . $file . "\n Line:" . $lineNo . "\n\n" );
        @fclose( $fp );
    }
}