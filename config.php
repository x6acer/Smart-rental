<?php
// Global site configuration

// Currency settings
if (!defined('CURRENCY_SYMBOL')) define('CURRENCY_SYMBOL', '₵'); // Ghana cedi symbol
if (!defined('CURRENCY_CODE')) define('CURRENCY_CODE', 'GHS');

function format_money($amount) {
    return CURRENCY_SYMBOL . number_format(floatval($amount), 2);
}

?>