<?php
/**
 * Creates (at most one live) EPS transaction for an invoice and redirects the customer to EPS.
 * Triggered by the Pay button, never by page render.
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
$invoiceId = (int)($_POST['invoiceid'] ?? 0);

function eps_back($systemurl, $invoiceId, $code)
{
    header('Location: ' . $systemurl . '/viewinvoice.php?id=' . (int)$invoiceId . '&epsstatus=' . $code);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$invoiceId
    || !hash_equals(eps_pay_sig($invoiceId, trim($gateway['hashkey'])), (string)($_POST['sig'] ?? ''))) {
    header('Location: ' . $systemurl . '/clientarea.php?action=invoices');
    exit;
}

$invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
if (!$invoice || $invoice->status !== 'Unpaid') {
    eps_back($systemurl, $invoiceId, 'already');
}

eps_ensure_table();

// Serialize concurrent clicks / tabs for the same invoice.
$lock = 'eps_pay_' . $invoiceId;
Capsule::select('SELECT GET_LOCK(?, 10) AS l', [$lock]);
register_shutdown_function(function () use ($lock) {
    try { Capsule::select('SELECT RELEASE_LOCK(?)', [$lock]); } catch (Exception $e) {}
});

try {
    // 1. Double click / back button: reuse a link created in the last 60s.
    $fresh = Capsule::table(EPS_TABLE)->where('invoice_id', $invoiceId)->where('status', 'pending')
        ->where('created_at', '>', date('Y-m-d H:i:s', time() - 60))->orderBy('id', 'desc')->first();
    if ($fresh && $fresh->redirect_url) {
        header('Location: ' . $fresh->redirect_url);
        exit;
    }

    // 2. An older attempt may have been paid without the customer returning: apply it, don't charge twice.
    $older = Capsule::table(EPS_TABLE)->where('invoice_id', $invoiceId)->whereIn('status', ['initiating', 'pending', 'processing'])
        ->where('created_at', '>', date('Y-m-d H:i:s', time() - EPS_STALE_SECONDS))
        ->orderBy('id', 'desc')->limit(3)->get();
    foreach ($older as $row) {
        if (in_array(eps_settle($gateway, $row), ['paid', 'already'], true)) {
            eps_back($systemurl, $invoiceId, 'paid');
        }
    }

    // 3. Amounts. Balance due is in the client's currency; EPS charges BDT.
    $client = Capsule::table('tblclients')->where('id', $invoice->userid)->first();
    $cur = Capsule::table('tblcurrencies')->where('id', $client->currency)->first();
    $inv = localAPI('GetInvoice', ['invoiceid' => $invoiceId]);
    $balance = round((float)($inv['balance'] ?? 0), 2); // amount still due, client currency
    if ($balance <= 0) {
        eps_back($systemurl, $invoiceId, 'already');
    }

    $epsAmount = $balance;
    if (strtoupper($cur->code) !== 'BDT') {
        $bdt = Capsule::table('tblcurrencies')->where('code', 'BDT')->first();
        if (!$bdt) {
            throw new RuntimeException('Invoice currency ' . $cur->code . ' is not BDT and no BDT currency exists in WHMCS');
        }
        $epsAmount = round(convertCurrency($balance, $cur->id, $bdt->id), 2);
    }
    if ($epsAmount <= 0) {
        throw new RuntimeException('Computed EPS amount is zero');
    }

    // 4. Customer data with fallbacks for fields EPS marks mandatory.
    $phone = preg_replace('/\D+/', '', (string)$client->phonenumber);
    if (strpos($phone, '880') === 0 && strlen($phone) === 13) {
        $phone = '0' . substr($phone, 3);
    }
    if (strlen($phone) < 10) {
        eps_back($systemurl, $invoiceId, 'phone');
    }
    $city = trim($client->city) ?: 'Dhaka';
    $name = trim($client->firstname . ' ' . $client->lastname) ?: 'Customer';
    $item = Capsule::table('tblinvoiceitems')->where('invoiceid', $invoiceId)->orderBy('id')->value('description');
    $product = mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags((string)$item))) ?: ('Invoice #' . $invoiceId), 0, 100);

    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP']) && filter_var($_SERVER['HTTP_CF_CONNECTING_IP'], FILTER_VALIDATE_IP)) {
        $ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
    }

    $tx = date('ymdHis') . random_int(100, 999); // 13 digits, unique per attempt
    $ret = $systemurl . '/modules/gateways/callback/eps_success.php?tx=' . $tx;
    $address = trim($client->address1) ?: 'N/A';
    $address2 = trim($client->address2) ?: $address;
    $state = trim($client->state) ?: $city;
    $postcode = trim($client->postcode) ?: '1000';
    $country = trim($client->country) ?: 'BD';

    $payload = [
        'CustomerOrderId' => 'order-' . $invoiceId,
        'totalAmount' => number_format($epsAmount, 2, '.', ''),
        'ipAddress' => (string)$ip,
        'successUrl' => $ret, 'failUrl' => $ret, 'cancelUrl' => $ret,
        'customerName' => $name, 'customerEmail' => (string)$client->email,
        'customerAddress' => $address, 'customerAddress2' => $address2,
        'customerCity' => $city, 'customerState' => $state,
        'customerPostcode' => $postcode, 'customerCountry' => $country, 'customerPhone' => $phone,
        'shipmentName' => $name, 'shipmentAddress' => $address, 'shipmentAddress2' => $address2,
        'shipmentCity' => $city, 'shipmentState' => $state,
        'shipmentPostcode' => $postcode, 'shipmentCountry' => $country,
        'valueA' => 'Invoice #' . $invoiceId, 'valueB' => '', 'valueC' => (string)$invoiceId,
        'shippingMethod' => 'NO', 'noOfItem' => '1',
        'productName' => $product, 'productProfile' => 'general', 'productCategory' => 'Domain-Hosting',
        'ProductList' => [[
            'ProductName' => $product, 'NoOfItem' => '1', 'ProductProfile' => 'general',
            'ProductCategory' => 'Domain-Hosting', 'ProductPrice' => number_format($epsAmount, 2, '.', ''),
        ]],
    ];

    $startedAt = microtime(true);
    $client = new EPSClient($gateway); // validates settings before anything is written

    // Row first: if EPS creates the transaction but the response is lost, the cron can still settle it.
    $now = date('Y-m-d H:i:s');
    $rowId = Capsule::table(EPS_TABLE)->insertGetId([
        'invoice_id' => $invoiceId, 'merchant_tx' => $tx, 'eps_tx' => null,
        'invoice_amount' => $balance, 'eps_amount' => $epsAmount,
        'redirect_url' => null, 'status' => 'initiating',
        'created_at' => $now, 'updated_at' => $now,
    ]);

    try {
        $res = $client->initialize($tx, $payload);
    } catch (EPSException $e) {
        // A definite rejection or auth error means no transaction exists. A timeout is ambiguous: keep the row.
        if (!$e->transient && $e->httpCode !== 0) {
            eps_set_status($rowId, 'failed', ['note' => mb_substr($e->getMessage(), 0, 250)]);
        } else {
            eps_set_status($rowId, 'initiating', ['note' => mb_substr($e->getMessage(), 0, 250)]);
        }
        throw $e;
    }
    eps_set_status($rowId, 'pending', ['redirect_url' => $res->RedirectURL]);
    if ((microtime(true) - $startedAt) > 5) {
        logTransaction($gateway['name'], ['invoice' => $invoiceId, 'timings' => $client->timings], 'Slow Init');
    }

    header('Location: ' . $res->RedirectURL);
    exit;
} catch (Exception $e) {
    logTransaction($gateway['name'], ['invoice' => $invoiceId, 'error' => $e->getMessage()], 'Init Error');
    logActivity('EPS init failed for invoice #' . $invoiceId . ': ' . $e->getMessage());
    eps_back($systemurl, $invoiceId, 'init');
}
