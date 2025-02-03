<?php
defined('_JEXEC') or die('Restricted access');
//include('encdec_paytm.php'); 
require (JPATH_VM_PLUGINS . DS . 'plugins' . DS . 'vmpayment' . DS . 'paytm' . DS . 'includes' . DS .  'PaytmChecksum.php');
require (JPATH_VM_PLUGINS . DS . 'plugins' . DS . 'vmpayment' . DS . 'paytm' . DS . 'includes' . DS .  'PaytmConstants.php');
require (JPATH_VM_PLUGINS . DS . 'plugins' . DS . 'vmpayment' . DS . 'paytm' . DS . 'includes' . DS .  'PaytmHelper.php');
if (!class_exists('vmPSPlugin'))
    require(JPATH_VM_PLUGINS . DS . 'vmpsplugin.php');

class plgVmPaymentPaytm extends vmPSPlugin {

    // instance of class
    public static $_this = false;

    function __construct(& $subject, $config) {
		//if (self::$_this)
		//   return self::$_this;
		parent::__construct($subject, $config);
	
		$this->_loggable = true;
		$this->tableFields = array_keys($this->getTableSQLFields());
		$this->_tablepkey = 'id'; //virtuemart_id';
		$this->_tableId = 'id'; //'virtuemart_id';
		$varsToPush = array(
			'merchant_id' => array('','char'),
			'secret_key' => array('','char'),
			'industry_type'=> array('','char'),
			'website_name'=> array('','char'),
			'channel_id'=> array('','char'),
		    // 'mode' => array('','int'),
		    'transaction_url' => array('','text'),
		    'transaction_status_url' => array('','text'),
		    'callbackflag' => array('','int'),
			'log' => array('','char'),
			'description' => array('','text'),
		    'payment_logos' => array('', 'char'),
			'payment_currency' => array('', 'int'),
		    'status_pending' => array('', 'char'),
		    'status_success' => array('', 'char'),
		    'status_canceled' => array('', 'char'),
		    'countries' => array('', 'char'),
		    'min_amount' => array('', 'int'),
		    'max_amount' => array('', 'int'),
		    'secure_post' => array('', 'int'),
		    'ipn_test' => array('', 'int'),
		   
		);
	
		$this->setConfigParameterable($this->_configTableFieldName, $varsToPush);
	
		//self::$_this = $this;
    }
    
 	public function getVmPluginCreateTableSQL() {
		return $this->createTableSQL('Payment Paytm Table');
    }
    
	function getTableSQLFields() {
		$SQLfields = array(
		    'id' => 'int(11) UNSIGNED NOT NULL AUTO_INCREMENT',
		    'virtuemart_order_id' => 'int(10) UNSIGNED',
		    'order_number' => ' char(64)',
		    'virtuemart_paymentmethod_id' => 'mediumint(1) UNSIGNED',
		    'payment_name' => 'varchar(5000)',
			'paytm_custom' => ' varchar(255)',
		    'amount' => 'decimal(15,5) NOT NULL DEFAULT \'0.00000\'',
		    'billing_currency' => 'char(3) ',
			'response_code' => 'int(11)',
			'response_description' => 'varchar(225)',
			// 'mode'=> 'int(2)',
			'transaction_url'=> 'text',
			'transaction_status_url'=> 'text',
			'payment_id' => 'char(100)',
			'description' => 'text',
			
		);
		return $SQLfields;
	}
	
	function plgVmConfirmedOrder($cart, $order) {		
		if (!($method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id))) {
		    return null; // Another method was selected, do nothing
		}
		if (!$this->selectedThisElement($method->payment_element)) {
		    return false;
		}
		$session = JFactory::getSession();
		$return_context = $session->getId();
		$this->logInfo('plgVmConfirmedOrder order number: ' . $order['details']['BT']->order_number, 'message');

		if (!class_exists('VirtueMartModelOrders'))
		    require( JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php' );
		if (!class_exists('VirtueMartModelCurrency'))
		    require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'currency.php');		
		    
		
		//$usr = JFactory::getUser();
		$new_status = '';	
		$usrBT = $order['details']['BT'];
		$address = ((isset($order['details']['ST'])) ? $order['details']['ST'] : $order['details']['BT']);

		if (!class_exists('TableVendors'))
		    require(JPATH_VM_ADMINISTRATOR . DS . 'table' . DS . 'vendors.php');
		$this->getPaymentCurrency($method);
		$q = 'SELECT `currency_code_3` FROM `#__virtuemart_currencies` WHERE `virtuemart_currency_id`="' . $method->payment_currency . '" ';
		$db = JFactory::getDBO();
		$db->setQuery($q);
		$currency_code_3 = $db->loadResult();
	
		$paymentCurrency = CurrencyDisplay::getInstance($method->payment_currency);
		$totalInPaymentCurrency = $paymentCurrency->convertCurrencyTo($method->payment_currency, $order['details']['BT']->order_total, false);
		$cd = CurrencyDisplay::getInstance($cart->pricesCurrency);
		if ($totalInPaymentCurrency <= 0) {
		     vmInfo(JText::_('VMPAYMENT_PAYTM_PAYMENT_AMOUNT_INCORRECT'));
			    return false;
		}
		$merchant_id = $method->merchant_id;
		if (empty($merchant_id)) {
		    vmInfo(JText::_('VMPAYMENT_PAYTM_MERCHANT_ID_NOT_SET'));
		    return false;
		}
		$secret_key = $method->secret_key;
		if (empty($secret_key)) {
		    vmInfo(JText::_('VMPAYMENT_PAYTM_SECRET_KEY_NOT_SET'));
		    return false;
		}
		$channel_id = $method->channel_id;
		if (empty($channel_id)) {
		    vmInfo(JText::_('VMPAYMENT_PAYTM_CHANNEL_ID_NOT_SET'));
		    return false;
		}
		$industry_type = $method->industry_type;
		if (empty($industry_type)) {
		    vmInfo(JText::_('VMPAYMENT_PAYTM_INDUSTRY_TYPE_NOT_SET'));
		    return false;
		}
		$website_name = $method->website_name;
		if (empty($website_name)) {
		    vmInfo(JText::_('VMPAYMENT_PAYTM_WEBSITE_NAME_NOT_SET'));
		    return false;
		}
		// $mode = $method->mode;
		$transaction_url = $method->transaction_url;
		$transaction_status_url = $method->transaction_status_url;
		$callbackflag = $method->callbackflag;
		$log = $method->log;
		// $return_url = JROUTE::_(JURI::root() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived&on=' . $order['details']['BT']->order_number . '&pm=' . $order['details']['BT']->virtuemart_paymentmethod_id. '&orderId=' .JRequest::getVar('orderId'). '&responseCode=' .JRequest::getVar('responseCode'). '&responseDescription=' .JRequest::getVar('responseDescription'). '&checksum=' .JRequest::getVar('checksum'));
		$product = $cart->products;
		$description = $method->description;
		$order_id = $order['details']['BT']->order_number;
		$email = $order['details']['BT']->email;
		$firstname = $order['details']['BT']->first_name;
		$lastname = $order['details']['BT']->last_name;
		$address =  $order['details']['BT']->address_1." ".$order['details']['BT']->address_2;
		$city = $order['details']['BT']->city;
		
		$state = isset($order['details']['BT']->virtuemart_state_id) ? ShopFunctions::getStateByID($order['details']['BT']->virtuemart_state_id) : '';
		$country = ShopFunctions::getCountryByID($order['details']['BT']->virtuemart_country_id, 'country_2_code');
		$zip = $order['details']['BT']->zip;
		$phone = $order['details']['BT']->phone_1;
		$amount = intval($totalInPaymentCurrency);		//should be in paisa
		$ship_address = $address->address_1;
		if(isset($address->address_2)){
	    	$ship_address .=  " ".$address->address_2;
		}

		$post_variables = Array(
			"MID" => $merchant_id,
			"ORDER_ID" => $order_id,
			"CUST_ID" =>$email,
			"TXN_AMOUNT" => $amount,
			"CHANNEL_ID" => $channel_id,
			"INDUSTRY_TYPE_ID" => $industry_type,
			"WEBSITE" => $website_name
		);

		$post_variables["CALLBACK_URL"] = JURI::base() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived&pm=paytm';
		if($customCallbackUrl != '') {
			if (filter_var($customCallbackUrl, FILTER_VALIDATE_URL) === FALSE) {
			    // die('Not a valid URL');
			}else{
				$post_variables["CALLBACK_URL"] = $customCallbackUrl;
			}
		}			
		function sanitizedURL($param) {
			$pattern[0] = "%,%";
			$pattern[1] = "%\(%";
			$pattern[2] = "%\)%";
			$pattern[3] = "%\{%";
			$pattern[4] = "%\}%";
			$pattern[5] = "%<%";
			$pattern[6] = "%>%";
			$pattern[7] = "%`%";
			$pattern[8] = "%!%";
			$pattern[9] = "%\\$%";
			$pattern[10] = "%\%%";
			$pattern[11] = "%\^%";
			$pattern[12] = "%\+%";
			$pattern[13] = "%\|%";
			$pattern[14] = "%\\\%";
			$pattern[15] = "%'%";
			$pattern[16] = "%\"%";
			$pattern[17] = "%;%";
			$pattern[18] = "%~%";
			$pattern[19] = "%\[%";
			$pattern[20] = "%\]%";
			$pattern[21] = "%\*%";
			$sanitizedParam = preg_replace($pattern, "", $param);
			return $sanitizedParam;
		}
		function sanitizedParam($param) {
			$pattern[0] = "%,%";
			$pattern[1] = "%#%";
			$pattern[2] = "%\(%";
			$pattern[3] = "%\)%";
			$pattern[4] = "%\{%";
			$pattern[5] = "%\}%";
			$pattern[6] = "%<%";
			$pattern[7] = "%>%";
			$pattern[8] = "%`%";
			$pattern[9] = "%!%";
			$pattern[10] = "%\\$%";
			$pattern[11] = "%\%%";
			$pattern[12] = "%\^%";
			$pattern[13] = "%=%";
			$pattern[14] = "%\+%";
			$pattern[15] = "%\|%";
			$pattern[16] = "%\\\%";
			$pattern[17] = "%:%";
			$pattern[18] = "%'%";
			$pattern[19] = "%\"%";
			$pattern[20] = "%;%";
			$pattern[21] = "%~%";
			$pattern[22] = "%\[%";
			$pattern[23] = "%\]%";
			$pattern[24] = "%\*%";
			$pattern[25] = "%&%";
			$sanitizedParam = preg_replace($pattern, "", $param);
			return $sanitizedParam;
		}
		$all = '';
		foreach($post_variables as $name => $value)	{
			if($name != 'checksum') {
				$all .= "'";
				if ($name == 'returnUrl') {
					$all .= sanitizedURL($value);
				} else {				
					
					$all .= sanitizedParam($value);
				}
				$all .= "'";
			}
		}
	
		if($log == "on") {
			error_log("All Params : ".$all);
			error_log("Paytm Secret Key : ".$secret_key);
		}

		$checksum = getChecksumFromArray($post_variables, $secret_key);	
		
		$post_variables['CHECKSUMHASH'] =$checksum;
		$dbValues['order_number'] = $order['details']['BT']->order_number;
		$dbValues['payment_name'] = $this->renderPluginName($method, $order);
		$dbValues['virtuemart_paymentmethod_id'] = $cart->virtuemart_paymentmethod_id;
		$dbValues['description'] = $description;
		$dbValues['paytm_custom'] = $return_context;
		$dbValues['billing_currency'] = $method->payment_currency;
		$promocode_status = $method->promocode_status;
		$local_validation = $method->local_validation;
		$promocode_value = $method->promocode_value;
		$dbValues['amount'] = $amount;
		$this->storePSPluginInternalData($dbValues);
		$url = $transaction_url;

		$extraConfigKey=$order['details']['BT']->virtuemart_paymentmethod_id;
		// add spin image
		$html = '<html><head><title>Redirection</title></head><body><div style="margin: auto; text-align: center;">';
		$autoSubmit=true;
		$titName=JText::_('VMPAYMENT_PAYTM_REDIRECT_MESSAGE1');
		if($autoSubmit){
			$titName=JText::_('VMPAYMENT_PAYTM_REDIRECT_MESSAGE');
		}
		
		if($autoSubmit){
			/* body parameters */
			$paytmParams["body"] = array(
				"requestType" => "Payment",
				"mid" => $merchant_id,
				"websiteName" => $website_name,
				"orderId" => $order_id,
				"callbackUrl" => JURI::base() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived&pm=paytm',
				"txnAmount" => array(
					"value" => $amount,
					"currency" => "INR",
				),
				"userInfo" => array(
					"custId" => $email,
				),
			);
			
			$checksum = PaytmChecksum::generateSignature(json_encode($paytmParams["body"], JSON_UNESCAPED_SLASHES),$secret_key); 
			
			$paytmParams["head"] = array(
				"signature"	=> $checksum
			);
			
			/* prepare JSON string for request */
			$post_data = json_encode($paytmParams, JSON_UNESCAPED_SLASHES);

			$url = PaytmHelper::getPaytmURL(PaytmConstants::INITIATE_TRANSACTION_URL,$method->environment) . '?mid='.$merchant_id.'&orderId='.$order_id;

			$res= PaytmHelper::executecUrl($url, $post_data);
			if(!empty($res['body']['resultInfo']['resultStatus']) && $res['body']['resultInfo']['resultStatus'] == 'S'){
				$data['txnToken']= $res['body']['txnToken'];
			}
			else
			{
				$data['txnToken']="";
			}


			$checkout_url = str_replace('MID',$merchant_id, PaytmHelper::getPaytmURL(PaytmConstants::CHECKOUT_JS_URL,$method->environment));
			$html='<style type="text/css">
					#paytm-pg-spinner {margin: 20% auto 0;width: 70px;text-align: center;z-index: 999999;position: relative;}

					#paytm-pg-spinner > div {width: 10px;height: 10px;background-color: #012b71;border-radius: 100%;display: inline-block;-webkit-animation: sk-bouncedelay 1.4s infinite ease-in-out both;animation: sk-bouncedelay 1.4s infinite ease-in-out both;}

					#paytm-pg-spinner .bounce1 {-webkit-animation-delay: -0.64s;animation-delay: -0.64s;}

					#paytm-pg-spinner .bounce2 {-webkit-animation-delay: -0.48s;animation-delay: -0.48s;}
					#paytm-pg-spinner .bounce3 {-webkit-animation-delay: -0.32s;animation-delay: -0.32s;}

					#paytm-pg-spinner .bounce4 {-webkit-animation-delay: -0.16s;animation-delay: -0.16s;}
					#paytm-pg-spinner .bounce4, #paytm-pg-spinner .bounce5{background-color: #48baf5;} 
					.notice{display:none;}
					.message{display:none;}
					@-webkit-keyframes sk-bouncedelay {0%, 80%, 100% { -webkit-transform: scale(0) }40% { -webkit-transform: scale(1.0) }}

					@keyframes sk-bouncedelay { 0%, 80%, 100% { -webkit-transform: scale(0);transform: scale(0); } 40% { 
					    -webkit-transform: scale(1.0); transform: scale(1.0);}}
					.paytm-overlay{width: 100%;top: 0px;opacity: .4;height: 100%;background: #000;}

					</style><script type="application/javascript" crossorigin="anonymous" src="'.$checkout_url.'"></script><div id="paytm-pg-spinner" class="paytm-woopg-loader"><div class="bounce1"></div><div class="bounce2"></div><div class="bounce3"></div><div class="bounce4"></div><div class="bounce5"></div><p class="loading-paytm">Loading Paytm...</p></div><div class="paytm-overlay paytm-woopg-loader"></div>';
			$html .=  '<script type="text/javascript">
				function invokeBlinkCheckoutPopup(){
				window.Paytm.CheckoutJS.init({
					"root": "",
					"flow": "DEFAULT",
					"data": {
						"orderId": "'.$order_id.'",
						"token": "'.$data['txnToken'].'",
						"tokenType": "TXN_TOKEN",
						"amount": "'.$amount.'",
					},
					"integration": {
						"platform": "VirtueMart",
						"version": "2.0|'.PaytmConstants::PLUGIN_VERSION.'"
					},
					handler:{
							transactionStatus:function(data){
						} , 
						notifyMerchant:function notifyMerchant(eventName,data){
							if(eventName=="APP_CLOSED")
							{
								jQuery(".paytm-overlay").hide();
								jQuery(".paytm-pg-loader").hide();
							}
							console.log("notify merchant about the payment state");
						} 
						}
				}).then(function(){
					window.Paytm.CheckoutJS.invoke();
				});
				}
				jQuery(function(){
					setTimeout(function(){invokeBlinkCheckoutPopup()},2000);
				});
				</script>
				';
				//exit();
		
		}
		$html.= ' </body></html>';
		// 	2 = don't delete the cart, don't send email and don't redirect
		//$cart->_confirmDone = false;
		//$cart->_dataValidated = false;
		//$cart->setCartIntoSession();
		//JRequest::setVar('html', $html);
		$mainframe = JFactory::getApplication ();
		$mainframe->enqueueMessage ($html);
		$mainframe->redirect (JRoute::_ ('index.php?option=com_virtuemart&view=cart',TRUE));

    }
    
	function plgVmgetPaymentCurrency($virtuemart_paymentmethod_id, &$paymentCurrencyId) {
		if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
		    return null; // Another method was selected, do nothing
		}
		if (!$this->selectedThisElement($method->payment_element)) {
		    return false;
		}
		$this->getPaymentCurrency($method);
		$paymentCurrencyId = $method->payment_currency;
    }

    function plgVmOnPaymentResponseReceived(&$html) {
		if(isset($_GET['extra']) && isset($_GET['extraConfigKey'])){
	    	$method = $this->getVmPluginMethod($_GET['extraConfigKey']);
			$json=array();
			if($_GET['extra']=='ajaxCall'){
				$codeApply='wrong';
				unset($_POST['CHECKSUMHASH']);
				if(isset($_POST['PROMO_CAMP_ID'])){
					unset($_POST['PROMO_CAMP_ID']);
				}
				if(isset($_POST['promoCode'])){
					$promoCode=$_POST['promoCode'];
					unset($_POST['promoCode']);
					if(trim($promoCode)!=''){
						$promocode_value = $method->promocode_value;
						$promocode_local_validation = $method->local_validation;
						if($promocode_local_validation=='1'){
							$promocodeValueArr=explode(',',$promocode_value);
							if(trim($promocodeValueArr[0])!=''){
								foreach ($promocodeValueArr as $key => $value) {
									if(trim($value)==trim($promoCode)){
										$_POST['PROMO_CAMP_ID']=trim($value);
										$codeApply='success';
									}
								}
							}
						}else{
							$codeApply='success';
							$_POST['PROMO_CAMP_ID']=trim($promoCode);
						}
					}else{
						$codeApply='remove';
					}
				}
				$checkSum = getChecksumFromArray($_POST, $method->secret_key);
				$_POST['CHECKSUMHASH']=$checkSum;
				// echo "<pre>";print_r($_POST);
				$str='';
				foreach ($_POST as $key => $value) {
					$str.='<input name="'.$key.'"    type="hidden"  value="'.$value.'"   >';
				}
				$json['message']=$codeApply;
				$json['hiddenFields']=$str;
			}else{
				$debug = array();
				if(!function_exists("curl_init")){
					$debug[0]["info"][] = "cURL extension is either not available or disabled. Check phpinfo for more info.";

				}else{ 
					// this site homepage URL
					$testing_urls = array(
						JURI::base()."index.php",
						"https://www.gstatic.com/generate_204",
						"https://secure.paytmpayments.com/merchant-status/getTxnStatus"
					);
					// loop over all URLs, maintain debug log for each response received
					foreach($testing_urls as $key=>$url){
						// echo $url."<br>";
						$debug[$key]["info"][] = "Connecting to <b>" . $url . "</b> using cURL";

						$ch = curl_init($url);
						curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
						$res = curl_exec($ch);

						if (!curl_errno($ch)) {
							$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
							$debug[$key]["info"][] = "cURL executed succcessfully.";
							$debug[$key]["info"][] = "HTTP Response Code: <b>". $http_code . "</b>";

							// $debug[$key]["content"] = $res;

						} else {
							$debug[$key]["info"][] = "Connection Failed !!";
							$debug[$key]["info"][] = "Error Code: <b>" . curl_errno($ch) . "</b>";
							$debug[$key]["info"][] = "Error: <b>" . curl_error($ch) . "</b>";
							break;
						}
						curl_close($ch);
					}
				}
				foreach($debug as $k=>$v){
					echo "<ul>";
					foreach($v["info"] as $info){
						echo "<li>".$info."</li>";
					}
					echo "</ul>";

					// echo "<div style='display:none;'>" . $v["content"] . "</div>";
					echo "<hr/>";
				}
				die;
			}
			echo json_encode($json);die;
		}else if($_SERVER['REQUEST_METHOD'] == 'POST'){
			if (!class_exists('VirtueMartCart'))
		    	require(JPATH_VM_SITE . DS . 'helpers' . DS . 'cart.php');
			if (!class_exists('shopFunctionsF'))
			    require(JPATH_VM_SITE . DS . 'helpers' . DS . 'shopfunctionsf.php');
			if (!class_exists('VirtueMartModelOrders'))
			    require( JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php' );
			$paytm_data = JRequest::get('post');
			// the payment itself should send the parameter needed.
			$virtuemart_paymentmethod_id = JRequest::getInt('pm', 0);
			$order_number = JRequest::getString('on', 0);
			
			
			$vendorId = 0;
			if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
			    return null; // Another method was selected, do nothing
			}
			if (!$this->selectedThisElement($method->payment_element)) {
			    return null;
			}	
			if (!($virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber($order_number))) {
			    return null;
			}
			if (!($paymentTable = $this->getDataByOrderId($virtuemart_order_id) )) {
			    return '';
			}
			
			$payment_name = $this->renderPluginName($method);
			
			function sanitizedParam($param) {
				$pattern[0] = "%,%";
				$pattern[1] = "%#%";
				$pattern[2] = "%\(%";
				$pattern[3] = "%\)%";
				$pattern[4] = "%\{%";
				$pattern[5] = "%\}%";
				$pattern[6] = "%<%";
				$pattern[7] = "%>%";
				$pattern[8] = "%`%";
				$pattern[9] = "%!%";
				$pattern[10] = "%\\$%";
				$pattern[11] = "%\%%";
				$pattern[12] = "%\^%";
				$pattern[13] = "%=%";
				$pattern[14] = "%\+%";
				$pattern[15] = "%\|%";
				$pattern[16] = "%\\\%";
				$pattern[17] = "%:%";
				$pattern[18] = "%'%";
				$pattern[19] = "%\"%";
				$pattern[20] = "%;%";
				$pattern[21] = "%~%";
				$pattern[22] = "%\[%";
				$pattern[23] = "%\]%";
				$pattern[24] = "%\*%";
				$pattern[25] = "%&%";
				$sanitizedParam = preg_replace($pattern, "", $param);
				return $sanitizedParam;
			}
		
			function verifyChecksum($checksum, $all, $secret) {
				$hash = hash_hmac('sha256', $all , $secret);
				$cal_checksum = $hash;
				$bool = 0;
				if($checksum == $cal_checksum)	{
					$bool = 1;
				}
				return $bool;
			}
			
			$order_id = JRequest::getString('ORDERID', 0);
			$res_code = JRequest::getString('RESPCODE',0);
			$res_desc = JRequest::getString('RESPMSG',0);
			$checksum_recv = JRequest::getString('CHECKSUMHASH',0);
			//$input = JFactory::getApplication->input;
			$paramList = JRequest::get( 'post' );
			$amount = JRequest::getString('TXNAMOUNT',0);	
			$all = ("'". $order_id ."''". $res_code ."''". $res_desc." " ."'");
			
			$bool = 0;

			if(!empty($_POST['CHECKSUMHASH'])){
				$post_checksum = $_POST['CHECKSUMHASH'];
				unset($_POST['CHECKSUMHASH']);	
			}else{
				$post_checksum = "";
			}
			$isValidChecksum = PaytmChecksum::verifySignature($_POST, $method->secret_key, $post_checksum);
			//$bool = verifyChecksum($checksum_recv, $all, $method->secret_key);
			//$bool = verifychecksum_e($paramList, $method->secret_key, $checksum_recv);
		
			//if($bool == 1){
			if($isValidChecksum === true){		
					
				if($res_code=="01") {
					// Create an array having all required parameters for status query.
					$requestParamList = array("MID" => $method->merchant_id , "ORDERID" => $order_id);
					
					//$StatusCheckSum = getChecksumFromArray($requestParamList, $method->secret_key);
					$requestParamList['CHECKSUMHASH'] = PaytmChecksum::generateSignature($requestParamList, $method->secret_key);				
					//$requestParamList['CHECKSUMHASH'] = $StatusCheckSum;
					
					$check_status_url = $method->transaction_status_url;

					if($_REQUEST['STATUS'] == 'TXN_SUCCESS' || $_REQUEST['RESPMSG'] == 'PENDING'){
						/* number of retries untill cURL gets success */
						$retry = 1;
						do{
							$postData = 'JsonData='.urlencode(json_encode($requestParamList));
							$responseParamList = PaytmHelper::executecUrl(PaytmHelper::getPaytmURL(PaytmConstants::ORDER_STATUS_URL, $method->environment), $postData);
							$retry++;
						} while(!$responseParamList['STATUS'] && $retry < PaytmConstants::MAX_RETRY_COUNT);
						/* number of retries untill cURL gets success */
					}

					//$responseParamList = callNewAPI($check_status_url, $requestParamList);
					if($responseParamList['STATUS']=='TXN_SUCCESS' && $responseParamList['TXNAMOUNT']==$amount)
					{			
						echo '<br><tr><td width="50%" align="center" valign="middle">Thank you for shopping with us. Your account has been charged and your transaction is successful. We will be shipping your order to you soon.</td></tr><br>';
						$new_status = $method->status_success;
					}
					else{
						echo '<tr><td width="50%" align="center" valign="middle">It seems some issue in server to server communication. Kindly connect with administrator.</td> </tr>';
						$new_status = $method->status_canceled;
					}
								
				} else {
					echo '<tr><td width="50%" align="center" valign="middle">Thank you for shopping with us. The response is compromised</td></tr><br>'; 
						$new_status = $method->status_pending;		
				}
			} else {
				echo '<tr><td width="50%" align="center" valign="middle">Security Error. Response compromised.</td> </tr>';
				$new_status = $method->status_canceled;
							
			}
			function vmModel($model=null) {
				if(!class_exists('VmModel'))
					require(JPATH_VM_ADMINISTRATOR.DS.'helpers'.DS.'vmmodel.php');
				return vmModel::getModel($model);
			}
			
			$modelOrder = vmModel('orders');
			$order['order_status'] = $new_status;
			$order['customer_notified'] = 0;
			$order['comments'] = '';
			$modelOrder->updateStatusForOneOrder($virtuemart_order_id, $order, true);
			
			$this->_storePaytmInternalData($method, $order_id, $res_code, $res_desc, $virtuemart_order_id, $paymentTable->paytm_custom);
			if($res_code==100){		
				$html = $this->_getPaymentResponseHtml($paymentTable, $payment_name, $res_code, $res_desc);
			} else{
				$cancel_return = JROUTE::_(JURI::root() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginUserPaymentCancel&on=' .$order_number.'&pm='.$virtuemart_paymentmethod_id);
				$html= ' <script type="text/javascript">';
				$html.= 'window.location = "'.$cancel_return.'"';
				$html.= ' </script>';
				JRequest::setVar('html', $html);
			}
		
			//We delete the old stuff
			// get the correct cart / session
			$cart = VirtueMartCart::getCart();
			$cart->emptyCart();
			return true;
		}else{
			$protocol='http://';
			$host='';
			if (isset($_SERVER['HTTPS']) && (($_SERVER['HTTPS'] == 'on') || ($_SERVER['HTTPS'] == '1'))) {
        		$protocol='https://';
	    	}
    
			if (isset($_SERVER["HTTP_HOST"]) && ! empty($_SERVER["HTTP_HOST"])) {
				$host=$_SERVER["HTTP_HOST"];
			}
			header("Location: {$protocol}{$host}");
			return false;
		}
    }
    
	function _getPaymentResponseHtml($paymentTable, $payment_name, $res_code, $res_desc) {
		$html = '<table>' . "\n";
		$html .= $this->getHtmlRow('PAYTM_PAYMENT_NAME', $payment_name);		
		if (!empty($paymentTable)) {
		    $html .= $this->getHtmlRow('PAYTM_ORDER_NUMBER', $paymentTable->order_number);
		}
		
		$q = 'SELECT `currency_code_3` FROM `#__virtuemart_currencies` WHERE `virtuemart_currency_id`="' . $paymentTable->billing_currency . '" ';
		$db = JFactory::getDBO();
		$db->setQuery($q);
		$currency_code_3 = $db->loadResult();
		
		
		$html .= $this->getHtmlRow('PAYTM_RESPONSE_CODE', $res_code);
		$html .= $this->getHtmlRow('PAYTM_RESPONSE_CODE', $res_desc);
		
		$html .= '</table>' . "\n";
	
		return $html;
    }
    
	function _storePaytmInternalData($method, $order_id, $res_code, $res_desc, $virtuemart_order_id, $custom) {
		$virtuemart_paymentmethod_id = JRequest::getInt('pm', 0);
		$response_fields['payment_name'] = $this->renderPluginName($method);	
		$response_fields['virtuemart_order_id'] = $virtuemart_order_id;
		$response_fields['virtuemart_paymentmethod_id'] = $virtuemart_paymentmethod_id;
		$response_fields['order_number'] = $order_id;
		$response_fields['paytm_custom'] = $custom;
		$response_fields['billing_currency'] = $method->payment_currency;
		$response_fields['response_code'] = $res_code;
		$response_fields['response_description'] = $res_desc;
		
		return $response_fields;		
		$this->storePSPluginInternalData($response_fields, 'virtuemart_order_id', true);
    }
    
 	function plgVmOnUserPaymentCancel() {
		if (!class_exists('VirtueMartModelOrders'))
		    require( JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php' );
	
		$order_number = JRequest::getString('orderId', '');
		$virtuemart_paymentmethod_id = JRequest::getInt('pm', '');
		if (empty($order_number) or empty($virtuemart_paymentmethod_id) or !$this->selectedThisByMethodId($virtuemart_paymentmethod_id)) {
		    return null;
		}
		if (!($virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber($order_number))) {
			return null;
		}
		if (!($paymentTable = $this->getDataByOrderId($virtuemart_order_id))) {
		    return null;
		}
	
		VmInfo(Jtext::_('VMPAYMENT_PAYTM_PAYMENT_CANCELLED'));
		$session = JFactory::getSession();
		$return_context = $session->getId();
		if (strcmp($paymentTable->paytm_custom, $return_context) === 0) {
		    $this->handlePaymentUserCancel($virtuemart_order_id);
		}
		return true;
    }
    
	function plgVmOnShowOrderBEPayment($virtuemart_order_id, $payment_method_id) {
		if (!$this->selectedThisByMethodId($payment_method_id)) {
		    return null; // Another method was selected, do nothing
		}
		if (!($paymentTable = $this->_getPaytmInternalData($virtuemart_order_id) )) {
		    // JError::raiseWarning(500, $db->getErrorMsg());
		    return '';
		}
		$q = 'SELECT `currency_code_3` FROM `#__virtuemart_currencies` WHERE `virtuemart_currency_id`="' . $paymentTable->billing_currency . '" ';
		$db = JFactory::getDBO();
		$db->setQuery($q);
		$currency_code_3 = $db->loadResult();
		$html = '<table class="adminlist">' . "\n";
		$html .=$this->getHtmlHeaderBE();
		$html .= $this->getHtmlRowBE('PAYTM_PAYMENT_NAME', $paymentTable->payment_name);		
		//echo "<pre>";print_r($paymentTable);echo "</pre>";
		$html .= $this->getHtmlRowBE('PAYTM_VIRTUEMART_ORDER_ID', $paymentTable->virtuemart_order_id);
		$html .= $this->getHtmlRowBE('PAYTM_RESPONSE_CODE', $paymentTable->response_code);
		$html .= $this->getHtmlRowBE('PAYTM_RESPONSE_DESCRIPTION', $paymentTable->response_description);
		$html .= $this->getHtmlRowBE('PAYTM_PAYMENT_ID', $paymentTable->payment_id);
		$html .= $this->getHtmlRowBE('PAYTM_AMOUNT', $paymentTable->amount.' INR');
		// $html .= $this->getHtmlRowBE('PAYTM_MODE', $paymentTable->mode);
		$html .= $this->getHtmlRowBE('PAYTM_TRANSACTION_URL', $paymentTable->transaction_url);
		$html .= $this->getHtmlRowBE('PAYTM_TRANSACTION_STATUS_URL', $paymentTable->transaction_status_url);
		$html .= $this->getHtmlRowBE('PAYTM_PAYMENT_DATE', $paymentTable->modified_on);
		$html .= '</table>' . "\n";
		return $html;
    }

    function _getPaytmInternalData($virtuemart_order_id, $order_number = '') {
		$db = JFactory::getDBO();
		$q = 'SELECT * FROM `' . $this->_tablename . '` WHERE ';
		if ($order_number) {
		    $q .= " `order_number` = '" . $order_number . "'";
		} else {
		    $q .= ' `virtuemart_order_id` = ' . $virtuemart_order_id;
		}
		$db->setQuery($q);
		if (!($paymentTable = $db->loadObject())) {
		    return '';
		}
		return $paymentTable;
    }
    
	function getCosts(VirtueMartCart $cart, $method, $cart_prices) {
		
		return ($cart_prices['salesPrice'] );
    }
    
	protected function checkConditions($cart, $method, $cart_prices) {
		$this->convert($method);
		$address = (($cart->ST == 0) ? $cart->BT : $cart->ST);
		$amount = $cart_prices['salesPrice'];
		$amount_cond = ($amount >= $method->min_amount AND $amount <= $method->max_amount
			OR
			($method->min_amount <= $amount AND ($method->max_amount == 0) ));
		$countries = array();
		if (!empty($method->countries)) {
		    if (!is_array($method->countries)) {
			$countries[0] = $method->countries;
		    } else {
			$countries = $method->countries;
		    }
		}
		// probably did not gave his BT:ST address
		if (!is_array($address)) {
		    $address = array();
		    $address['virtuemart_country_id'] = 0;
		}
		if (!isset($address['virtuemart_country_id']))
		    $address['virtuemart_country_id'] = 0;
		if (in_array($address['virtuemart_country_id'], $countries) || count($countries) == 0) {
		    if ($amount_cond) {
			return true;
		    }
		}
		return false;
    }
    
 	function convert($method) {
		$method->min_amount = (float) $method->min_amount;
		$method->max_amount = (float) $method->max_amount;
    }
    
	function plgVmOnStoreInstallPaymentPluginTable($jplugin_id) {
		return $this->onStoreInstallPluginTable($jplugin_id);
    }
    
    public function plgVmOnSelectCheckPayment(VirtueMartCart $cart) {
		return $this->OnSelectCheck($cart);
    }
    
	public function plgVmDisplayListFEPayment(VirtueMartCart $cart, $selected = 0, &$htmlIn) {
		return $this->displayListFE($cart, $selected, $htmlIn);
    }
    
	public function plgVmonSelectedCalculatePricePayment(VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name) {
		return $this->onSelectedCalculatePrice($cart, $cart_prices, $cart_prices_name);
    }
    
    function plgVmOnCheckAutomaticSelectedPayment(VirtueMartCart $cart, array $cart_prices = array(),   &$paymentCounter) {
		return $this->onCheckAutomaticSelected($cart, $cart_prices,  $paymentCounter);
    }
    
 	public function plgVmOnShowOrderFEPayment($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name) {
		$this->onShowOrderFE($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name);
    }
    
 	function plgVmonShowOrderPrintPayment($order_number, $method_id) {
		return $this->onShowOrderPrint($order_number, $method_id);
    }
    
    function plgVmDeclarePluginParamsPayment($name, $id, &$data) {
		return $this->declarePluginParams('payment', $name, $id, $data);
    }

    function plgVmSetOnTablePluginParamsPayment($name, $id, &$table) {
		return $this->setOnTablePluginParams($name, $id, $table);
    }

}

// No closing tag
