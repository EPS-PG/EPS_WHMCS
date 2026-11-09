EPS - WHMCS

Prerequisites
--------------
WHMCS 8.0 or later, PHP 7.4+, cURL extension, MySQL (uses GET_LOCK).
WHMCS cron must be running (used to confirm payments when a customer pays and closes the tab).

Install
--------
1. Copy these into your WHMCS root, keeping the folders:
     modules/gateways/eps.php
     modules/gateways/eps/lib.php
     modules/gateways/callback/eps_pay.php
     modules/gateways/callback/eps_success.php
     includes/hooks/eps_hooks.php
2. WHMCS Admin > System Settings > Apps & Integrations (Payment Gateways) > activate "EPS Payment Gateway".
3. Fill in: Button Label, Username, Password, Hash key, Merchant ID, Store ID, Sandbox mode, then Save.
4. Make sure a BDT currency exists in WHMCS if your invoices are in another currency (amounts are converted to BDT for EPS).

How it works
-------------
- The invoice page only shows a Pay button. EPS is contacted when the customer clicks it.
- After payment, the return URL re-verifies the transaction with EPS server-side before the invoice is marked paid.
- The cron hook re-checks pending transactions every cron run, so no IPN is needed.
  If EPS gives you an IPN spec, it can be added later.
- Every attempt is stored in table mod_eps_transactions (created automatically).
- Gateway Log (Billing > Gateway Log) shows every verification result and error.

Self-check: php tests/eps_selfcheck.php
