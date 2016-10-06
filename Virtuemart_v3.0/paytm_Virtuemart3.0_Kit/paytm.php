<?php
defined('_JEXEC') or die('Restricted access');
 
if (!class_exists('vmPSPlugin')){
  require(JPATH_VM_PLUGINS . DS . 'vmpsplugin.php');
}		

require (VMPATH_ROOT . DS . 'plugins' . DS . 'vmpayment' . DS . 'paytm' . DS .  'encdec_paytm.php');

class plgVmPaymentPaytm extends vmPSPlugin {

  public static $_this = false;	
	function __construct (& $subject, $config) {
		parent::__construct ($subject, $config);		
		$this->_loggable = TRUE;
		$this->tableFields = array_keys ($this->getTableSQLFields ());
		$this->_tablepkey = 'id';
		$this->_tableId = 'id';
		$varsToPush = $this->getVarsToPush ();
		$this->setConfigParameterable ($this->_configTableFieldName, $varsToPush);
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
				'mode'=> 'int(2)',
				'payment_id' => 'char(100)',
				'description' => 'text',
			);
		return $SQLfields;
	}
	
	function plgVmOnConfirmedOrderStorePaymentData ($virtuemart_order_id, $orderData, $priceData) {
		if (!$this->selectedThisPayment ($this->_pelement, $orderData->virtuemart_paymentmethod_id)) {
			return NULL; // Another method was selected, do nothing
		}
		return FALSE;
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

		if (!class_exists('VirtueMartModelOrders')){
		  require( JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php' );
		}	
		if (!class_exists('VirtueMartModelCurrency')){
		  require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'currency.php');		
		}
				
		$new_status = '';	
		$usrBT = $order['details']['BT'];
		$address = ((isset($order['details']['ST'])) ? $order['details']['ST'] : $order['details']['BT']);

		if (!class_exists('TableVendors')){
		  require(JPATH_VM_ADMINISTRATOR . DS . 'table' . DS . 'vendors.php');
		}
		
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
		
		$mode = $method->mode;
		$callbackflag = $method->callbackflag; 
		$log = $method->log;
		
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
		$amount = round((float)$totalInPaymentCurrency,2);		//should be in paisa
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
      "WEBSITE" => $website_name,
    );	
	
	if($callbackflag == '1')
		{
			$post_variables["CALLBACK_URL"] = JURI::base() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived&pm=paytm';
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
		
		if($log == "on")
		{
			error_log("All Params : ".$all);
			error_log("Paytm Secret Key : ".$secret_key);
		}

		
		$checksum = getChecksumFromArray($post_variables, $secret_key);	
	
		$post_variables = Array(
            "MID" => $merchant_id,
            "ORDER_ID" => $order_id,
			"WEBSITE" => $website_name, 
			"INDUSTRY_TYPE_ID" => $industry_type,	
		    "CHANNEL_ID" => $channel_id,
		    "TXN_AMOUNT" => $amount,    
		    "CUST_ID" =>$email,
            "txnDate" =>date('Y-m-d H:i:s'),
			"CHECKSUMHASH" =>$checksum,			
            );	
		if($callbackflag == '1')
		{
			$post_variables["CALLBACK_URL"] = JURI::base() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived&pm=paytm';
		}
		$dbValues['order_number'] = $order['details']['BT']->order_number;
		$dbValues['payment_name'] = $this->renderPluginName($method, $order);
		$dbValues['virtuemart_paymentmethod_id'] = $cart->virtuemart_paymentmethod_id;
		$dbValues['description'] = $description;
		$dbValues['paytm_custom'] = $return_context;
		$dbValues['billing_currency'] = $method->payment_currency;
		$dbValues['amount'] = $amount;
		$this->storePSPluginInternalData($dbValues);
		if ($mode==0) {
			$url = "pguat.paytm.com/oltp-web/processTransaction"; } 
		else {
			$url = "secure.paytm.in/oltp-web/processTransaction";
		} 
		// add spin image
		$html = '<html><head><title>Redirection</title></head><body><div style="margin: auto; text-align: center;">';
		$html .= '<form action="' . "https://" . $url . '" method="post" name="vm_paytm_form" >';
		$html.= '<input type="submit"  value="' . JText::_('VMPAYMENT_PAYTM_REDIRECT_MESSAGE') . '" />';
		foreach ($post_variables as $name => $value) {
		    $html.= '<input type="hidden" style="" name="' . $name . '" value="' . $value . '" />';
		}

		$html.= '</form></div>';
		$html.= ' <script type="text/javascript">';
		$html.= ' document.vm_paytm_form.submit();';
		$html.= ' </script></body></html>';
	
		// 	2 = don't delete the cart, don't send email and don't redirect
		$cart->_confirmDone = false;
		$cart->_dataValidated = false;
		$cart->setCartIntoSession();
	//	JRequest::setVar('html', $html);
			return $this->processConfirmedOrderPaymentResponse ('', $cart, $order, $html, '', '');
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
			if($_SERVER['REQUEST_METHOD'] == 'POST'){
				if (!class_exists ('VirtueMartCart')) {
					require(VMPATH_SITE . DS . 'helpers' . DS . 'cart.php');
				}
				if (!class_exists ('shopFunctionsF')) {
					require(VMPATH_SITE . DS . 'helpers' . DS . 'shopfunctionsf.php');
				}
				if (!class_exists ('VirtueMartModelOrders')) {
					require(VMPATH_ADMIN . DS . 'models' . DS . 'orders.php');
				}
				
				if(! isset($_POST)){
					
				}
				$paytm_data = JRequest::get('post'); 
			// the payment itself should send the parameter needed.
				
			  $virtuemart_paymentmethod_id =$this->_getPaytmPluginCode()->virtuemart_paymentmethod_id;
			  
			  $order_number =$_POST['ORDERID'];
			  
			  $vendorId = 0;
			  
			  if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) { 
			  		return null; // Another method was selected, do nothing
			  }
			  if (!$this->selectedThisElement($method->payment_element)) {
			  		return false;
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
		
			
			  $order_id = JRequest::getString('ORDERID', 0);
			  $res_code = JRequest::getString('RESPCODE',0);
			  $res_desc = JRequest::getString('RESPMSG',0);
			  $checksum_recv = JRequest::getString('CHECKSUMHASH',0);
			  $paramList = JRequest::get( 'post' );
			  $amount = JRequest::getString('TXNAMOUNT',0);	
			  $mode = JRequest::getString('PAYMENTMODE',0);
			  $payment_id = JRequest::getString('TXNID',0);
			  $all = ("'". $order_id ."''". $res_code ."''". $res_desc." " ."'");
				
			  if(verifychecksum_e($paramList, $method->secret_key, $checksum_recv)){
			  		
			  	if($res_code=="01")
			  	{			
			  		echo '<br><tr><td width="50%" align="center" valign="middle">Thank you for shopping with us. Your account has been charged and your transaction is successful. We will be shipping your order to you soon.</td></tr><br>';
			  		$new_status = $method->status_success;
			  	}
			  	else
			  	{			
			  		echo '<br><tr><td width="50%" align="center" valign="middle"><b>Transaction Failed. </b>'.$res_desc.'</td></tr><br>';
			  		$cancel_return = JROUTE::_(JURI::root() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginUserPaymentCancel&on=' .$order_number.'&pm='.$virtuemart_paymentmethod_id);
			  		echo "</br><a href='".$cancel_return."'><b>Go Back To Cart</a>";
			  		$new_status = $method->status_pending;		
			  	}
			  }
			  
			  else
			  {
			  	echo '<tr><td width="50%" align="center" valign="middle">Security Error. Response compromised.</td></tr>';
			  	$cancel_return = JROUTE::_(JURI::root() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginUserPaymentCancel&on=' .$order_number.'&pm='.$virtuemart_paymentmethod_id);
			  	echo "</br><a href='".$cancel_return."'><b>Go Back To Cart</a>";
			  	$new_status = $method->status_canceled;
			  				
			  }
			  function vmModel($model=null)
			  {
			  	if(!class_exists('VmModel'))
			  	require(JPATH_VM_ADMINISTRATOR.DS.'helpers'.DS.'vmmodel.php');
			  	return vmModel::getModel($model);
			  }
			  
			  $modelOrder = vmModel('orders');
			  $order['order_status'] = $new_status;
			  $order['customer_notified'] = 0;
			  $order['comments'] = '';
			  $modelOrder->updateStatusForOneOrder($virtuemart_order_id, $order, true);
			  $cart = VirtueMartCart::getCart();
			  
			  $this->_storePaytmInternalData($method, $order_id, $res_code, $res_desc, $virtuemart_order_id, $paymentTable->paytm_custom, $amount, $mode, $payment_id);
			  if($res_code=="01"){		
			  	$cart->emptyCart();
			  	$html = $this->_getPaymentResponseHtml($paymentTable, $payment_name, $res_code, $res_desc);
			  }
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
	
		//return $html;
    }
    
	function _storePaytmInternalData($method, $order_id, $res_code, $res_desc, $virtuemart_order_id, $custom, $amount, $mode, $payment_id) {
		$virtuemart_paymentmethod_id = $this->_getPaytmPluginCode()->virtuemart_paymentmethod_id;
		$response_fields['payment_name'] = $this->renderPluginName($method);	
		$response_fields['virtuemart_order_id'] = $virtuemart_order_id;
		$response_fields['virtuemart_paymentmethod_id'] = $virtuemart_paymentmethod_id;
		$response_fields['order_number'] = $order_id;
		$response_fields['paytm_custom'] = $custom;
		$response_fields['billing_currency'] = $method->payment_currency;
		$response_fields['response_code'] = $res_code;
		$response_fields['response_description'] = $res_desc;
		$response_fields['amount'] = $amount;
		$response_fields['mode'] = $mode;
		$response_fields['payment_id'] = $payment_id;
		$this->storePSPluginInternalData($response_fields, 'virtuemart_order_id', true);
		return $response_fields;		
		
    }
    
 	function plgVmOnUserPaymentCancel() {
		if (!class_exists('VirtueMartModelOrders'))
		    require( JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php' );
	
		$order_number = JRequest::getString('orderId', '');
		$virtuemart_paymentmethod_id = $this->_getPaytmPluginCode()->virtuemart_paymentmethod_id;
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
		$html .= $this->getHtmlRowBE('PAYTM_MODE', $paymentTable->mode);
		$html .= $this->getHtmlRowBE('PAYTM_PAYMENT_DATE', $paymentTable->modified_on);
		$html .= '</table>' . "\n";
		return $html;
    }

		
		
		
		function _getPaytmPluginCode() {
		  $db = JFactory::getDBO();
			$dVar=new JConfig();
			
		  $q = 'SELECT virtuemart_paymentmethod_id FROM `' . $dVar->dbprefix  . 'virtuemart_paymentmethods` WHERE payment_element="paytm"';
		  
		  $db->setQuery($q);
		  if (!($paymentTable = $db->loadObject())) {
		      return '';
		  }
		  return $paymentTable;
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
    
   

    function plgVmSetOnTablePluginParamsPayment($name, $id, &$table) {
		return $this->setOnTablePluginParams($name, $id, $table);
    }

		function plgVmDeclarePluginParamsPaymentVM3( &$data) {
		return $this->declarePluginParams('payment', $data);
	}
	
}

// No closing tag
