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

    public static function pluginVersion()
    {
        return json_decode(file_get_contents(dirname(__DIR__) . '/composer.json'))->version;
    }
}