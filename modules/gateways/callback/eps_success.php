<?php

require_once '../../../init.php';
require_once '../../../includes/gatewayfunctions.php';
require_once '../../../includes/invoicefunctions.php';

$gatewaymodule = "eps"; # Enter your gateway module name here replacing template


$GATEWAY = getGatewayVariables($gatewaymodule);

if (!$GATEWAY["type"]){
	die("Module Not Activated"); # Checks gateway module is active before accepting callback
}

// Process callback data

$invoiceid = $_GET['invoiceid'];
$tran_id = $_GET["MerchantTransactionId"];


$store_id = $GATEWAY["store_id"];
$store_passwd = $GATEWAY["store_password"];
$systemurl = rtrim($GATEWAY['systemurl'], '/');
$status = $_GET['Status'];
$base_amount=$_GET['amount'];

if ($_GET['Status'] == 'Success') {
	
	checkCbInvoiceID($invoiceid, $GATEWAY["name"]); # Checks invoice ID is a valid invoice number or ends processing


	if ($status == "Success") {
		$fee = 0;
		addInvoicePayment($invoiceid, $tran_id, $base_amount, $fee, $gatewaymodule);
		logTransaction($GATEWAY["name"], $_GET, "Successful"); # Save to Gateway Log: name, data array, status
		header("Location: " . $systemurl . "/clientarea.php?action=services"); /* Redirect browser */
		exit();

	} else {
		logTransaction($GATEWAY["name"], $_GET, "Unsuccessful"); # Save to Gateway Log: name, data array, status
		header("Location: " . $systemurl . "/clientarea.php?action=services"); /* Redirect browser */
		exit();
	}

} else {
	logTransaction($GATEWAY["name"], $_GET, "Unsuccessful"); # Save to Gateway Log: name, data array, status
	header("Location: " . $systemurl . "/clientarea.php?action=services"); /* Redirect browser */
	exit();
}