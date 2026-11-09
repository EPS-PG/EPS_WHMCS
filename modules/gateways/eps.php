<?php

/**
 * WHMCS EPS Payment Gateway Module
 * @see https://eps.com.bd/
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

require_once __DIR__ . '/eps/lib.php';

function eps_MetaData()
{
    return array(
        'DisplayName' => 'EPS Payment Gateway',
        'APIVersion' => '1.0'
    );
}

// Config keys are kept (even the misspelled merchent_id) so existing saved settings keep working.
function eps_config()
{
    return array(
        "FriendlyName" => array("Type" => "System", "Value" => "EPS Payment Gateway"),
        "button_text" => array("FriendlyName" => "Payment Button Label", "Type" => "text", "Size" => "50", 'Default' => 'Proceed to Payment'),
        "user_name" => array("FriendlyName" => "Username", "Type" => "text", "Size" => "100"),
        "user_password" => array("FriendlyName" => "Password", "Type" => "password", "Size" => "100"),
        "hashkey" => array("FriendlyName" => "Hash key", "Type" => "password", "Size" => "100"),
        "merchent_id" => array("FriendlyName" => "Merchant ID", "Type" => "text", "Size" => "100"),
        "store_id" => array("FriendlyName" => "Store ID", "Type" => "text", "Size" => "100"),
        "testmode" => array("FriendlyName" => "Enable Sandbox / Testmode?", "Type" => "yesno", "Description" => "Choose 'NO' in live environment."),
    );
}

/**
 * Renders the pay button only. No API call here: this runs on every invoice
 * view/list/checkout render, so initializing a payment here creates a
 * new EPS transaction per page view. Initialization happens in callback/eps_pay.php.
 */
function eps_link($params)
{
    $hashkey = trim($params['hashkey'] ?? '');
    if ($hashkey === '') {
        return '';
    }
    $action = rtrim($params['systemurl'], '/') . '/modules/gateways/callback/eps_pay.php';
    $label = htmlspecialchars(trim($params['button_text']) ?: 'Proceed to Payment', ENT_QUOTES);

    return '<form method="post" action="' . htmlspecialchars($action, ENT_QUOTES) . '" onsubmit="var b=this.querySelector(\'button\');setTimeout(function(){b.disabled=true},0)">'
        . '<input type="hidden" name="invoiceid" value="' . (int)$params['invoiceid'] . '">'
        . '<input type="hidden" name="sig" value="' . eps_pay_sig($params['invoiceid'], $hashkey) . '">'
        . '<button type="submit" class="btn btn-success" id="epsPayBtn">' . $label . '</button>'
        . '</form>';
}
