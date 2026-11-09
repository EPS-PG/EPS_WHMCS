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
const EPS_TOKEN_TABLE = 'mod_eps_token';

class EPSException extends RuntimeException
{
    public $httpCode;
    public $transient;

    public function __construct($message, $httpCode = 0, $transient = false)
    {
        parent::__construct($message);
        $this->httpCode = $httpCode;
        $this->transient = $transient;
    }
}

class EPSClient
{
    private $base, $user, $pass, $key, $merchant, $store;

    /** Per-request timings (for the Gateway Log when something is slow). */
    public $timings = [];

    public function __construct(array $p)
    {
        $this->base = ($p['testmode'] ?? '') === 'on' ? 'https://sandboxpgapi.eps.com.bd' : 'https://pgapi.eps.com.bd';
        $this->user = trim($p['user_name'] ?? '');
        $this->pass = trim($p['user_password'] ?? '');
        $this->key = trim($p['hashkey'] ?? '');
        $this->merchant = trim($p['merchent_id'] ?? '');
        $this->store = trim($p['store_id'] ?? '');
        foreach (['Username' => $this->user, 'Password' => $this->pass, 'Hash key' => $this->key,
                  'Merchant ID' => $this->merchant, 'Store ID' => $this->store] as $label => $v) {
            if ($v === '') {
                throw new RuntimeException("EPS setting '$label' is empty - re-enter it in the gateway settings");
            }
            if (!preg_match('/^[\x20-\x7e]+$/', $v) || preg_match('/^\*{3,}$/', $v)) {
                throw new RuntimeException("EPS setting '$label' is unreadable or masked - re-enter it in the gateway settings");
            }
        }
    }

    public static function hash($data, $key)
    {
        return base64_encode(hash_hmac('sha512', (string)$data, (string)$key, true));
    }

    private function call($method, $path, array $headers, $body = null, $timeout = 20)
    {
        $ch = curl_init($this->base . $path);
        $opt = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1, // what the earlier working copy used
            CURLOPT_CUSTOMREQUEST => $method,
            // empty Expect: stops curl waiting on "100-continue" for bodies over 1 KB
            CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json', 'Expect:'], $headers),
        ];
        if ($body !== null) {
            $opt[CURLOPT_POSTFIELDS] = json_encode($body, JSON_INVALID_UTF8_SUBSTITUTE);
        }
        curl_setopt_array($ch, $opt);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $redir = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        $where = strtok($path, '?');
        $this->timings[] = sprintf('%s: total %.1fs (dns %.1f, connect %.1f, tls %.1f, EPS wait %.1f)', $where,
            curl_getinfo($ch, CURLINFO_TOTAL_TIME), curl_getinfo($ch, CURLINFO_NAMELOOKUP_TIME),
            curl_getinfo($ch, CURLINFO_CONNECT_TIME), curl_getinfo($ch, CURLINFO_APPCONNECT_TIME),
            curl_getinfo($ch, CURLINFO_STARTTRANSFER_TIME) - curl_getinfo($ch, CURLINFO_PRETRANSFER_TIME));
        curl_close($ch);

        if ($raw === false) {
            throw new EPSException("EPS connection error on $where: $err", 0, true);
        }
        if ($code >= 400) {
            throw new EPSException("EPS HTTP $code on $where: " . substr($raw, 0, 200), $code, $code >= 500 || $code === 429);
        }
        $json = json_decode($raw);
        if (!is_object($json)) {
            throw new EPSException("EPS returned an invalid response on $where (HTTP $code, redirect: $redir, body: "
                . substr(trim(strip_tags((string)$raw)), 0, 200) . ')', $code, true);
        }
        return $json;
    }

    /** Run $fn; retry once after 1s only for transient failures (timeout, 5xx, 429). Never for auth errors. */
    private function withRetry(callable $fn)
    {
        try {
            return $fn();
        } catch (EPSException $e) {
            if (!$e->transient) {
                throw $e;
            }
            sleep(1);
            return $fn();
        }
    }

    private function cacheKey()
    {
        return sha1($this->base . '|' . $this->user . '|' . $this->key);
    }

    /** Token cached in the DB until just before it expires; shared by Pay clicks and the cron. */
    private function token($fresh = false)
    {
        $k = $this->cacheKey();
        if (!$fresh) {
            try {
                $row = Capsule::table(EPS_TOKEN_TABLE)->where('k', $k)->first();
                if ($row && strtotime($row->expires_at) > time() + 60) {
                    return $row->token;
                }
            } catch (Exception $e) {
                // cache is an optimisation only
            }
        }
        $r = $this->withRetry(function () {
            return $this->call('POST', '/v1/Auth/GetToken',
                ['x-hash: ' . self::hash($this->user, $this->key)],
                ['userName' => $this->user, 'password' => $this->pass]);
        });
        if (empty($r->token)) {
            // EPS answers HTTP 200 + errorMessage for a wrong user/password/hash key.
            throw new EPSException('EPS auth failed (check Username, Password and Hash key in gateway settings): '
                . ($r->errorMessage ?? 'no token'), 200, false);
        }
        $exp = !empty($r->expireDate) ? strtotime($r->expireDate . ' UTC') : 0;
        if ($exp < time() + 120) {
            $exp = time() + 600; // unknown/odd expiry: keep it short
        }
        try {
            Capsule::table(EPS_TOKEN_TABLE)->updateOrInsert(['k' => $k],
                ['token' => $r->token, 'expires_at' => date('Y-m-d H:i:s', $exp - 60)]);
        } catch (Exception $e) {
        }
        return $r->token;
    }

    /** Authenticated call; on HTTP 401 the cached token was stale: refresh once. */
    private function authed($method, $path, $hashData, $body, $timeout)
    {
        $try = function ($fresh) use ($method, $path, $hashData, $body, $timeout) {
            return $this->call($method, $path, [
                'x-hash: ' . self::hash($hashData, $this->key),
                'Authorization: Bearer ' . $this->token($fresh),
            ], $body, $timeout);
        };
        try {
            return $try(false);
        } catch (EPSException $e) {
            if ($e->httpCode !== 401) {
                throw $e;
            }
            return $try(true);
        }
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

        // Not retried: InitializeEPS is not idempotent. A new attempt gets a new transaction id.
        $r = $this->authed('POST', '/v1/EPSEngine/InitializeEPS', $merchantTxId, $body, 45);

        $host = empty($r->RedirectURL) ? '' : strtolower((string)parse_url($r->RedirectURL, PHP_URL_HOST));
        $scheme = empty($r->RedirectURL) ? '' : parse_url($r->RedirectURL, PHP_URL_SCHEME);
        if ($scheme !== 'https' || !preg_match('/(^|\.)eps\.com\.bd$/', $host)) {
            throw new EPSException('EPS rejected the request: ' . ($r->ErrorMessage ?? 'no/invalid RedirectURL'), 200, false);
        }
        return $r;
    }

    public function status($merchantTxId)
    {
        return $this->withRetry(function () use ($merchantTxId) {
            return $this->authed('GET',
                '/v1/EPSEngine/CheckMerchantTransactionStatus?merchantTransactionId=' . rawurlencode($merchantTxId),
                $merchantTxId, null, 20);
        });
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
    if (!$s->hasTable(EPS_TOKEN_TABLE)) {
        $s->create(EPS_TOKEN_TABLE, function ($t) {
            $t->string('k', 40)->primary();
            $t->text('token');
            $t->dateTime('expires_at');
        });
    }
    if ($s->hasTable(EPS_TABLE)) {
        // upgrade path: tables created by older versions lack `note`
        if (!$s->hasColumn(EPS_TABLE, 'note')) {
            $s->table(EPS_TABLE, function ($t) {
                $t->string('note', 255)->nullable();
            });
        }
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
        $t->string('note', 255)->nullable();
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
        eps_set_status($row->id, $result, [], ['initiating', 'pending']);
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
