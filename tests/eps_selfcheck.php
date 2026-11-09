<?php
function t($ok) { if (!$ok) { fwrite(STDERR, "FAIL line " . debug_backtrace()[0]["line"] . "
"); exit(1); } }
// Run: php tests/eps_selfcheck.php   (pure-logic checks, no WHMCS or network needed)
define('WHMCS', true);
require __DIR__ . '/../modules/gateways/eps/lib.php';

// HMAC-SHA512 base64 = 88 chars, deterministic, key-dependent.
$h = EPSClient::hash('user@example.com', 'key');
t(strlen($h) === 88 && $h === EPSClient::hash('user@example.com', 'key') && $h !== EPSClient::hash('user@example.com', 'key2'));
t($h === base64_encode(hash_hmac('sha512', 'user@example.com', 'key', true)));

t(eps_classify('Success') === 'success');
t(eps_classify('Cancel') === 'cancelled' && eps_classify('Cancelled') === 'cancelled');
t(eps_classify('Failed') === 'failed' && eps_classify('Declined') === 'failed');
t(eps_classify('Initialize') === 'pending' && eps_classify('') === 'pending' && eps_classify(null) === 'pending');

t(eps_amount_ok('1800.00', 1800) && eps_amount_ok(1800.004, '1800.00'));
t(!eps_amount_ok('1.00', 1800) && !eps_amount_ok(-1, 0));

$s = eps_pay_sig(513, 'k');
t($s === eps_pay_sig(513, 'k') && $s !== eps_pay_sig(514, 'k') && $s !== eps_pay_sig(513, 'x'));

echo "ok\n";
