<?php
/**
 * EPS gateway shared library: API client, transaction store, settlement.
 * Used by eps.php, callback/eps_pay.php, callback/eps_success.php and the cron hook.
 */
if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

const EPS_TABLE = 'mod_eps_transactions';
const EPS_TRANSACTION_TYPE_ID = 5; // value used by the working production copy; API guide lists 1 = Web
const EPS_STALE_SECONDS = 259200;  // pending tx older than 3 days are expired

class EPSClient
{
    private $base, $user, $pass, $key, $merchant, $store;

    public function __construct(array $p)
    {
        $this->base = ($p['testmode'] ?? '') === 'on' ? 'https://sandboxpgapi.eps.com.bd' : 'https://pgapi.eps.com.bd';
        $this->user = trim($p['user_name'] ?? '');
        $this->pass = trim($p['user_password'] ?? '');
        $this->key = trim($p['hashkey'] ?? '');
        $this->merchant = trim($p['merchent_id'] ?? '');
        $this->store = trim($p['store_id'] ?? '');
        if (!$this->user || !$this->pass || !$this->key || !$this->merchant || !$this->store) {
            throw new RuntimeException('EPS gateway is not fully configured');
        }
    }

    public static function hash($data, $key)
    {
        return base64_encode(hash_hmac('sha512', (string)$data, (string)$key, true));
    }

    private function call($method, $path, array $headers, $body = null)
    {
        $ch = curl_init($this->base . $path);
        $opt = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers),
        ];
        if ($body !== null) {
            $opt[CURLOPT_POSTFIELDS] = json_encode($body, JSON_INVALID_UTF8_SUBSTITUTE);
        }
        curl_setopt_array($ch, $opt);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException("EPS connection error: $err");
        }
        if ($code >= 400) {
            throw new RuntimeException("EPS HTTP $code: " . substr($raw, 0, 200));
        }
        $json = json_decode($raw);
        if (!is_object($json)) {
            throw new RuntimeException('EPS returned an invalid response');
        }
        return $json;
    }

    private static $tokens = [];

    private function token()
    {
        $k = $this->base . $this->user;
        if (isset(self::$tokens[$k]) && self::$tokens[$k][1] > time()) {
            return self::$tokens[$k][0];
        }
        $r = $this->call('POST', '/v1/Auth/GetToken',
            ['x-hash: ' . self::hash($this->user, $this->key)],
            ['userName' => $this->user, 'password' => $this->pass]);
        if (empty($r->token)) {
            throw new RuntimeException('EPS auth failed: ' . ($r->errorMessage ?? 'no token'));
        }
        self::$tokens[$k] = [$r->token, time() + 240];
        return $r->token;
    }

    /** @return object with TransactionId + RedirectURL (validated) */
    public function initialize($merchantTxId, array $payload)
    {
        $body = array_merge([
            'deviceTypeId' => 4,
            'merchantId' => $this->merchant,
            'storeId' => $this->store,
            'transactionTypeId' => EPS_TRANSACTION_TYPE_ID,
            'financialEntityId' => 0,
            'version' => '1',
            'transactionDate' => date('c'),
            'transitionStatusId' => 0,
            'valueD' => '',
            'merchantTransactionId' => (string)$merchantTxId,
        ], $payload);

        $r = $this->call('POST', '/v1/EPSEngine/InitializeEPS', [
            'x-hash: ' . self::hash($merchantTxId, $this->key),
            'Authorization: Bearer ' . $this->token(),
        ], $body);

        $host = empty($r->RedirectURL) ? '' : strtolower((string)parse_url($r->RedirectURL, PHP_URL_HOST));
        $scheme = empty($r->RedirectURL) ? '' : parse_url($r->RedirectURL, PHP_URL_SCHEME);
        if ($scheme !== 'https' || !preg_match('/(^|\.)eps\.com\.bd$/', $host)) {
            throw new RuntimeException('EPS init failed: ' . ($r->ErrorMessage ?? 'no/invalid RedirectURL'));
        }
        return $r;
    }

    public function status($merchantTxId)
    {
        return $this->call('GET',
            '/v1/EPSEngine/CheckMerchantTransactionStatus?merchantTransactionId=' . rawurlencode($merchantTxId), [
                'x-hash: ' . self::hash($merchantTxId, $this->key),
                'Authorization: Bearer ' . $this->token(),
            ]);
    }
}

/** Signature that lets eps_pay.php trust the invoice id without a session (works for emailed pay links too). */
function eps_pay_sig($invoiceId, $hashkey)
{
    return hash_hmac('sha256', 'eps-pay|' . (int)$invoiceId, (string)$hashkey);
}

/** Map an EPS status string to success|cancelled|failed|pending. */
function eps_classify($status)
{
    $s = strtolower(trim((string)$status));
    if ($s === 'success') return 'success';
    if (strpos($s, 'cancel') === 0) return 'cancelled';
    if (strpos($s, 'fail') === 0 || $s === 'declined' || $s === 'rejected') return 'failed';
    return 'pending';
}

function eps_amount_ok($a, $b)
{
    return abs((float)$a - (float)$b) < 0.01;
}

function eps_ensure_table()
{
    $s = Capsule::schema();
    if ($s->hasTable(EPS_TABLE)) {
        return;
    }
    $s->create(EPS_TABLE, function ($t) {
        $t->increments('id');
        $t->integer('invoice_id')->index();
        $t->string('merchant_tx', 64)->unique();
        $t->string('eps_tx', 64)->nullable();
        $t->decimal('invoice_amount', 14, 2);
        $t->decimal('eps_amount', 14, 2);
        $t->text('redirect_url')->nullable();
        $t->string('status', 20)->default('pending')->index();
        $t->dateTime('created_at');
        $t->dateTime('updated_at');
    });
}

function eps_set_status($id, $status, array $extra = [], array $onlyFrom = [])
{
    $q = Capsule::table(EPS_TABLE)->where('id', $id);
    if ($onlyFrom) {
        $q->whereIn('status', $onlyFrom);
    }
    return $q->update(array_merge(['status' => $status, 'updated_at' => date('Y-m-d H:i:s')], $extra));
}

/**
 * Verify a stored transaction with EPS and apply it to the invoice.
 * Idempotent and safe to call concurrently (callback + cron).
 *
 * @return string paid|already|failed|cancelled|pending|mismatch|error
 */
function eps_settle(array $gateway, $row, $hint = '')
{
    if ($row->status === 'paid') {
        return 'already';
    }
    if ($row->status === 'processing' && strtotime($row->updated_at) > time() - 600) {
        return 'already'; // another request is applying it right now
    }
    $name = $gateway['name'] ?? 'EPS';

    try {
        $r = (new EPSClient($gateway))->status($row->merchant_tx);
    } catch (Exception $e) {
        logTransaction($name, ['merchant_tx' => $row->merchant_tx, 'error' => $e->getMessage()], 'Verify Error');
        return 'error';
    }

    $result = eps_classify($r->Status ?? '');
    $log = json_decode(json_encode($r), true) ?: [];

    if ($result !== 'success') {
        if ($result === 'pending') {
            // Hint comes from the URL (spoofable): used for the message only, never stored.
            return eps_classify($hint) === 'cancelled' ? 'cancelled' : 'pending';
        }
        eps_set_status($row->id, $result, [], ['pending']);
        logTransaction($name, $log, $result === 'cancelled' ? 'Cancelled' : 'Unsuccessful');
        return $result;
    }

    if (strcasecmp((string)($r->MerchantTransactionId ?? ''), $row->merchant_tx) !== 0
        || !eps_amount_ok($r->TotalAmount ?? -1, $row->eps_amount)) {
        eps_set_status($row->id, 'mismatch');
        logTransaction($name, $log + ['expected_amount' => $row->eps_amount], 'Amount/Transaction Mismatch');
        return 'mismatch';
    }

    // Atomic claim: only one caller flips the row to paid.
    $epsTx = (string)($r->EpsTransactionId ?? '') ?: $row->merchant_tx;
    $claimed = Capsule::table(EPS_TABLE)->where('id', $row->id)->where('status', '!=', 'paid')
        ->where('updated_at', $row->updated_at)
        ->update(['status' => 'processing', 'updated_at' => date('Y-m-d H:i:s'), 'eps_tx' => $epsTx]);
    if (!$claimed) {
        return 'already';
    }

    if (Capsule::table('tblaccounts')->where('gateway', 'eps')->where('transid', $epsTx)->exists()) {
        eps_set_status($row->id, 'paid'); // applied earlier, only the status write was lost
        return 'already';
    }

    try {
        addInvoicePayment($row->invoice_id, $epsTx, $row->invoice_amount, 0, 'eps');
    } catch (Exception $e) {
        eps_set_status($row->id, 'pending'); // let the cron retry
        logTransaction($name, $log + ['error' => $e->getMessage()], 'Payment Apply Error');
        return 'error';
    }
    eps_set_status($row->id, 'paid');
    logTransaction($name, $log, 'Successful');
    return 'paid';
}
