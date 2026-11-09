<?php
/**
 * EPS hooks:
 *  - AfterCronJob: re-verify pending EPS transactions (covers customers who pay and close the tab).
 *  - ClientAreaFooterOutput: show a status banner on the invoice page after returning from EPS.
 */
use WHMCS\Database\Capsule;

add_hook('AfterCronJob', 1, function () {
    require_once ROOTDIR . '/includes/gatewayfunctions.php';
    require_once ROOTDIR . '/includes/invoicefunctions.php';
    require_once ROOTDIR . '/modules/gateways/eps/lib.php';

    $gateway = getGatewayVariables('eps');
    if (empty($gateway['type']) || !Capsule::schema()->hasTable(EPS_TABLE)) {
        return;
    }

    // Abandoned attempts.
    Capsule::table(EPS_TABLE)->where('status', 'pending')
        ->where('created_at', '<', date('Y-m-d H:i:s', time() - EPS_STALE_SECONDS))
        ->update(['status' => 'expired', 'updated_at' => date('Y-m-d H:i:s')]);

    // Give the customer 5 minutes to finish before we poll.
    $rows = Capsule::table(EPS_TABLE)->whereIn('status', ['pending', 'processing'])
        ->where('updated_at', '<', date('Y-m-d H:i:s', time() - 300))
        ->orderBy('id')->limit(50)->get();
    foreach ($rows as $row) {
        eps_settle($gateway, $row);
    }
});

add_hook('ClientAreaFooterOutput', 1, function ($vars) {
    if (($vars['filename'] ?? '') !== 'viewinvoice' || !isset($_GET['epsstatus'])) {
        return '';
    }
    $messages = [
        'paid' => ['success', 'Payment received. Thank you.'],
        'already' => ['info', 'This invoice has already been paid or the payment is already recorded.'],
        'failed' => ['danger', 'Your payment was not successful. You have not been charged. Please try again.'],
        'cancelled' => ['warning', 'Payment was cancelled. You can try again at any time.'],
        'pending' => ['info', 'We are still confirming your payment with EPS. If you paid, this invoice will update shortly; do not pay again.'],
        'mismatch' => ['danger', 'The paid amount did not match the invoice. Please contact support with your payment details.'],
        'error' => ['warning', 'We could not confirm your payment right now. If you paid, this invoice will update automatically; do not pay again.'],
        'phone' => ['warning', 'Please add a valid mobile number to your account profile, then try again.'],
        'init' => ['danger', 'The payment gateway is temporarily unavailable. Please try again in a few minutes.'],
    ];
    $key = (string)$_GET['epsstatus'];
    if (!isset($messages[$key])) {
        return '';
    }
    list($class, $text) = $messages[$key];
    return '<script>document.addEventListener("DOMContentLoaded",function(){var d=document.createElement("div");'
        . 'd.className="alert alert-' . $class . '";d.textContent=' . json_encode($text) . ';'
        . 'var t=document.querySelector(".invoice-container")||document.body;t.insertBefore(d,t.firstChild);});</script>';
});
