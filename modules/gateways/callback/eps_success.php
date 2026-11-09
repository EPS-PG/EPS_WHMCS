<?php
/**
 * EPS return URL (success, fail and cancel all land here).
 * Nothing in the query string is trusted: the transaction is looked up in our own table
 * and the result is re-verified server-side with EPS before any payment is recorded.
 */
use WHMCS\Database\Capsule;

require_once __DIR__ . '/../../../init.php';
require_once ROOTDIR . '/includes/gatewayfunctions.php';
require_once ROOTDIR . '/includes/invoicefunctions.php';
require_once __DIR__ . '/../eps/lib.php';

$gateway = getGatewayVariables('eps');
if (empty($gateway['type'])) {
    die("Module Not Activated");
}
$systemurl = rtrim($gateway['systemurl'], '/');

eps_ensure_table();
$tx = preg_replace('/[^A-Za-z0-9\-]/', '', (string)($_GET['tx'] ?? ''));
$row = $tx === '' ? null : Capsule::table(EPS_TABLE)->where('merchant_tx', $tx)->first();

if (!$row) {
    logTransaction($gateway['name'], $_GET, 'Unknown Transaction');
    header('Location: ' . $systemurl . '/clientarea.php?action=invoices');
    exit;
}

$result = eps_settle($gateway, $row, (string)($_GET['Status'] ?? ''));
header('Location: ' . $systemurl . '/viewinvoice.php?id=' . (int)$row->invoice_id . '&epsstatus=' . $result);
exit;
