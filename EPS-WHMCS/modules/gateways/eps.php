<?php

/**
 * WHMCS EPS Payment Gateway Module
 * @see https://eps.com.bd/
 * @author: EPS Integration Team
 * @copyright Copyright (c) EPS 2020-2024
 * @license http://www.whmcs.com/license/ WHMCS Eula
**/

    if (!defined("WHMCS")) {
        die("This file cannot be accessed directly");
    }

    /**
     * Define module related meta data.
     * @return array
     */

    function eps_MetaData()
    {
        return array(
            'DisplayName' => 'EPS Payment Gateway',
            'APIVersion' => '1.0'
        );
    }


    function eps_config() {
        $configarray = array(
            "FriendlyName" => array("Type" => "System", "Value"=>"EPS Payment Gateway"),
            "button_text" => array("FriendlyName" => "Payment Button Label", "Type" => "text", "Size" => "50", 'Default' => 'Proceed to Payment',),
            "user_name" => array("FriendlyName" => "Username", "Type" => "text", "Size" => "100", ),
            
            "user_password" => array("FriendlyName" => "Password", "Type" => "password", "Size" => "100", ),
            "hashkey" => array("FriendlyName" => "Hash key", "Type" => "text", "Size" => "100", ),
            
             "merchent_id" => array("FriendlyName" => "Merchent ID", "Type" => "text", "Size" => "100", ),
            "store_id" => array("FriendlyName" => "Store ID", "Type" => "text", "Size" => "100", ),
            "testmode" => array("FriendlyName" => "Enable Sandbox / Testmode?", "Type" => "yesno", "Description" => "Choose 'NO' in live environment.", ),
            "gateway_type" => array("FriendlyName" => "easyCheckout", "Type" => "yesno", "Description" => "Use easycheckout?", )
        );
    	return $configarray;
    }
     


    function eps_link($params) {
    	# Gateway Specific Variables
    	$gatewaystore_id        = trim($params['store_id']);
    	$gatewaystore_password  = trim($params['store_password']);
    	$gatewaybutton_text     = trim($params['button_text']);
    	$gatewaytestmode        = $params['testmode'];
    	$gatewaytype            = $params['gateway_type'];
    	
    	if ($gatewaytestmode == "on") {
            $api_endpoint ='https://sandboxpgapi.eps.com.bd';
            
        }
        else 
        {
            $api_endpoint ='https://pgapi.eps.com.bd';
        }

    	# Invoice Variables
    	$invoiceid              = $params['invoiceid'];
    	$description            = $params["description"];
        $amount                 = $params['amount']; # Format: ##.##
        $currency               = $params['currency']; # Currency Code
        $product                = $params['type'];

    	# Client Variables
    	$firstname              = $params['clientdetails']['firstname'];
    	$lastname               = $params['clientdetails']['lastname'];
    	$email                  = $params['clientdetails']['email'];
    	$address1               = $params['clientdetails']['address1'];
    	$address2               = $params['clientdetails']['address2'];
    	$city                   = $params['clientdetails']['city'];
    	$state                  = $params['clientdetails']['state'];
    	$postcode               = $params['clientdetails']['postcode'];
    	$country                = $params['clientdetails']['country'];
    	$phone                  = $params['clientdetails']['phonenumber'];
    	$uuid                   = $params['clientdetails']['uuid'];

    	# System Variables
    	$companyname            = $params['companyname'];
    	$systemurl              = $params['systemurl'];
    	$currency               = $params['currency'];
    	$returnurl              = $params['returnurl'];
        $success_url = $fail_url = $cancel_url = rtrim($systemurl, '/').'/modules/gateways/callback/eps_success.php?invoiceid='.$invoiceid.'&amount='.$amount; 
        




				try{
			  
					$product_arr= array(
					array("ProductName" => $product, "NoOfItem" => "1", "ProductProfile" => "general", "ProductCategory" => "Domain-Hosting","ProductPrice" => $amount),
					
					);
					
					
			$EpsPayment = new EPSPayment($params); 
			$payload = [
				"CustomerOrderId"=>(string)'order-'.$invoiceid,
				"totalAmount" => (string)$amount,
				"ipAddress" => (string)$_SERVER['REMOTE_HOST'],
				"successUrl" => (string)$success_url,
				"failUrl" => (string)$success_url,
				"cancelUrl" => (string)$success_url,
				"customerName" => (string)$firstname.' '.$lastname,
				"customerEmail" => (string)$email,
				"customerAddress" => (string)$address1,
				"customerAddress2" => "Looking up an address",
				"customerCity" => (string)$city,
				"customerState" => (string)$state,
				"customerPostcode" => (string)$postcode,
				"customerCountry" => (string)$country,
				"customerPhone"=> (string)$phone,
				"shipmentName"=> "shipmentName",
				"shipmentAddress"=> "Looking up an address",
				"shipmentAddress2"=> "Looking up an address",
				"shipmentCity"=> "Dhaka",
				"shipmentState"=> "Dhaka",
				"shipmentPostcode"=> "1000",
				"shipmentCountry"=> "Bangladesh",
				"valueA"=> (string)$description,
				"valueB"=> (string)$returnurl,
				"valueC"=>(string) $invoiceid,


				"shippingMethod"=> "NO",
				"noOfItem"=> "1",
				"productName"=> (string)$product,
				"productProfile"=> "general",
				"productCategory"=> "Domain-Hosting",
				"ProductList"=>$product_arr,
			];

			$payment_initiate_response = $EpsPayment->CreatePayment($payload);
			//print_r($payment_initiate_response);

			if($payment_initiate_response->RedirectURL){
                return '<a class="btn btn-success" id="sslczPayBtn"
            href="'.$payment_initiate_response->RedirectURL.'">'.$gatewaybutton_text.'</a>';

				
			}

		   }
			catch (exception $e) {
				header("Location: " . $systemurl . "/clientarea.php?action=services");
				//print_r($e);
			}

        
        

    }


?>
<?php 
class EPSPayment {

    
    private $baseUrl = 'https://sandboxpgapi.eps.com.bd';
    private $userName = 'Epsdemo@gmail.com';
    private $password = 'Epsdemo258@';
    private $deviceTypeId = 4;
    private $hashkey = 'FHZxyzeps56789gfhg678ygu876o=';
    private $merchent_id = '29e86e70-0ac6-45eb-ba04-9fcb0aaed12a';
    private $store_id = 'd44e705f-9e3a-41de-98b1-1674631637da';
    function __construct($params) {
        
       	if ($params['testmode'] == "on") {
            $this->baseUrl ='https://sandboxpgapi.eps.com.bd';
        }
        else 
        {
            $this->baseUrl ='https://pgapi.eps.com.bd';
        }    
    
        $this->userName = $params['user_name'];
        $this->password = $params['user_password'];
     
        $this->hashkey = $params['hashkey'];
        $this->merchent_id = $params['merchent_id'];
        $this->store_id = $params['store_id']; 
   }
    function GenerateHash($payload,$hashkey){
        $utf8_key = utf8_encode($hashkey);
        $utf8_payload = utf8_encode($payload);
        $data = hash_hmac('sha512', $utf8_payload, $utf8_key,true);
        $hmac = base64_encode($data);
        return $hmac;
    }

    function GetToken() {

        $curl = curl_init();
        $req_body = array( 
            "userName"=>$this->userName, 
            "password"=>$this->password
        );
        $x_hash = $this->GenerateHash($this->userName,$this->hashkey);

        curl_setopt_array($curl, array(
        CURLOPT_URL => $this->baseUrl.'/v1/Auth/GetToken',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS =>json_encode($req_body),
        CURLOPT_HTTPHEADER => array(
            "x-hash: $x_hash",
            "Content-Type: application/json"
          ),
        ));

        $response = curl_exec($curl);

        if ($response === false) {
            $error = curl_error($curl);
            $info = curl_getinfo($curl);
            die("cURL request failed, error = {$error}; info = " . print_r($info, true));
        }

        $responseCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        /*
        * 4xx status codes are client errors
        * 5xx status codes are server errors
        */
        if ($responseCode >= 400) {
            die("HTTP Error in gettoken: ". $responseCode);
        }
        curl_close($curl);
        return json_decode($response);
    }

    function CreatePayment($payload = array()) {

        $getToken_response = $this->GetToken();
          if(!isset($getToken_response->token)){
  
              die("Access Denied!");
          }
  
          $curl = curl_init();
          $invoice_id = (string)time() ;
          $req_body = array(
 
                "deviceTypeId"=> $this->deviceTypeId,
				"merchantId" => $this->merchent_id,
                "storeId" => $this->store_id,
                "transactionTypeId" => 1,
                "financialEntityId" => 0,
                "version"=> "1",
                "transactionDate" => date('c'),
                "transitionStatusId" => 0,
                "valueD"=> "",
				"merchantTransactionId" => $invoice_id,
          );

          $req_body = array_merge($req_body, $payload);

          $x_hash = $this->GenerateHash($invoice_id,$this->hashkey);
          $token = $getToken_response->token;
  
          curl_setopt_array($curl, array(
          CURLOPT_URL => $this->baseUrl.'/v1/EPSEngine/InitializeEPS',
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_ENCODING => '',
          CURLOPT_MAXREDIRS => 10,
          CURLOPT_TIMEOUT => 0,
          CURLOPT_FOLLOWLOCATION => true,
          CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
          CURLOPT_CUSTOMREQUEST => 'POST',
          CURLOPT_POSTFIELDS =>json_encode($req_body),
          CURLOPT_HTTPHEADER => array(
              "x-hash: $x_hash",
              "Authorization: Bearer $token",
              "Content-Type: application/json"
            ),
          ));
  
          $response = curl_exec($curl);
  
          if ($response === false) {
              $error = curl_error($curl);
              $info = curl_getinfo($curl);
              die("cURL request failed, error = {$error}; info = " . print_r($info, true));
          }
  
          $responseCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
          /*
          * 4xx status codes are client errors
          * 5xx status codes are server errors
          */
          if ($responseCode >= 400) {
              die("HTTP Error in gettoken: ". $responseCode);
          }
          curl_close($curl);
          return json_decode($response);
    }

    //check payment status...
    function CheckPaymentStatus($invoice_id) {

        $getToken_response = $this->GetToken();
          if(!isset($getToken_response->token)){
  
              die("Access Denied!");
          }
  
          $curl = curl_init();

          $x_hash = $this->GenerateHash($invoice_id,$this->hashkey);
          $token = $getToken_response->token;
  
          curl_setopt_array($curl, array(
          CURLOPT_URL => $this->baseUrl.'/v1/EPSEngine/CheckMerchantTransactionStatus?merchantTransactionId='.$invoice_id,
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_ENCODING => '',
          CURLOPT_MAXREDIRS => 10,
          CURLOPT_TIMEOUT => 0,
          CURLOPT_FOLLOWLOCATION => true,
          CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
          CURLOPT_CUSTOMREQUEST => 'GET',
          CURLOPT_POSTFIELDS =>json_encode($req_body),
          CURLOPT_HTTPHEADER => array(
              "x-hash: $x_hash",
              "Authorization: Bearer $token",
              "Content-Type: application/json"
            ),
          ));
  
          $response = curl_exec($curl);
  
          if ($response === false) {
              $error = curl_error($curl);
              $info = curl_getinfo($curl);
              die("cURL request failed, error = {$error}; info = " . print_r($info, true));
          }
  
          $responseCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
          /*
          * 4xx status codes are client errors
          * 5xx status codes are server errors
          */
          if ($responseCode >= 400) {
              die("HTTP Error in gettoken: ". $responseCode);
          }
          curl_close($curl);
          return json_decode($response);
    }
  }

  
?>