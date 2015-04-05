<?php

defined ('_JEXEC') or die('Restricted access');

if (!class_exists ('vmPSPlugin')) 
{
	require(JPATH_VM_PLUGINS . DS . 'vmpsplugin.php');
}

class plgVmPaymentTrustpay extends vmPSPlugin 
{
	public static $_this = FALSE;

	function __construct (& $subject, $config) {

		parent::__construct ($subject, $config);

		$this->_loggable = TRUE;
		$this->tableFields = array_keys ($this->getTableSQLFields ());
		$this->_tablepkey = 'id';
		$this->_tableId = 'id';
    
		$varsToPush = array('payment_logos' => array('', 'char'),
                        'aid'  => array('', 'char'),
		                    'key'  => array('', 'char'),
		                    'env'  => array('', 'char'),
                        'payment_currency'       => array('', 'int'));

		$this->setConfigParameterable ($this->_configTableFieldName, $varsToPush);
	}
  
	public function getVmPluginCreateTableSQL () 
  {
		return $this->createTableSQL ('Payment Trustpay Table');
	}

	function getTableSQLFields () {

		$SQLfields = array(
			'id'                                     => 'int(11) UNSIGNED NOT NULL AUTO_INCREMENT',
			'virtuemart_order_id'                    => 'int(1) UNSIGNED',
			'order_number'                           => 'char(64)',
			'virtuemart_paymentmethod_id'            => 'mediumint(1) UNSIGNED',
			'payment_name'                           => 'varchar(5000)',
			'payment_order_total'                    => 'decimal(15,5) NOT NULL',
			'payment_currency'                       => 'smallint(1)',
			'email_currency'                         => 'smallint(1)',
			'cost_per_transaction'                   => 'decimal(10,2)',
			'cost_percent_total'                     => 'decimal(10,2)',
			'tax_id'                                 => 'smallint(1)',
		);
		return $SQLfields;
	}

	function plgVmConfirmedOrder ($cart, $order) {

		if (!($method = $this->getVmPluginMethod ($order['details']['BT']->virtuemart_paymentmethod_id))) {
			return NULL;
		}
		if (!$this->selectedThisElement ($method->payment_element)) {
			return FALSE;
		}
		$session = JFactory::getSession ();
		$return_context = $session->getId ();
		$this->logInfo ('plgVmConfirmedOrder order number: ' . $order['details']['BT']->order_number, 'message');

		if (!class_exists ('VirtueMartModelOrders')) {
			require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');
		}
		if (!class_exists ('VirtueMartModelCurrency')) {
			require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'currency.php');
		}

		$address = ((isset($order['details']['ST'])) ? $order['details']['ST'] : $order['details']['BT']);

		if (!class_exists ('TableVendors')) {
			require(JPATH_VM_ADMINISTRATOR . DS . 'table' . DS . 'vendors.php');
		}
                          
		$vendorModel = VmModel::getModel ('Vendor');
		$vendorModel->setId (1);
		$vendor = $vendorModel->getVendor ();
		$vendorModel->addImages ($vendor, 1);
		$this->getPaymentCurrency ($method);
		$email_currency = $this->getEmailCurrency ($method);
		//$currency_code_3 = shopFunctions::getCurrencyByID ($method->payment_currency, 'currency_code_3');

		$paymentCurrency = CurrencyDisplay::getInstance ($method->payment_currency);
		$totalInPaymentCurrency = round ($paymentCurrency->convertCurrencyTo ($method->payment_currency, $order['details']['BT']->order_total, FALSE), 2);
		
    if ($totalInPaymentCurrency <= 0) {
			vmInfo (JText::_ ('VMPAYMENT_PAYPAL_PAYMENT_AMOUNT_INCORRECT'));
			return FALSE;
		}
    
		$quantity = 0;
		foreach ($cart->products as $key => $product) {
			$quantity = $quantity + $product->quantity;
		}
    
		$post_variables = Array(
			'AID' => $method->aid,
			'AMT' => $totalInPaymentCurrency,
			'CUR' => 'EUR', 
			'REF' => $order['details']['BT']->order_number,
			'SIG'   => '',
      'LNG'   => 'sk');
      
    $post_variables['SIG'] = $this->CreateInputSIG($post_variables, $method->key);  

		// Prepare data that should be stored in the database
		$dbValues['order_number'] = $order['details']['BT']->order_number;
		$dbValues['payment_name'] = $this->renderPluginName ($method, $order);
		$dbValues['virtuemart_paymentmethod_id'] = $cart->virtuemart_paymentmethod_id;
		$dbValues['cost_per_transaction'] = 0;//$method->cost_per_transaction;
		$dbValues['cost_percent_total'] = 0;//$method->cost_percent_total;
		$dbValues['payment_currency'] = $method->payment_currency;
		$dbValues['email_currency'] = $email_currency;
		$dbValues['payment_order_total'] = $totalInPaymentCurrency;
		$dbValues['tax_id'] = 0;//$method->tax_id;
		$this->storePSPluginInternalData ($dbValues);

		$url = $this->getTrustPayUrlHttps ($method);

		$html = '<html><head><title>Redirection</title><style type="text/css">p{margin-top: 10px;}</style></head><body><div>';
		$html .= '<form action="' . "https://" . $url . '" method="post" name="vm_trustpay_form" >';
		$html .= '<p>Počkajte, prosím, budete automaticky presmerovaný na stránku TrustPay. Ak Vás prehliadač nepresmeruje počas niekoľkých sekúnd, kliknite na talčidlo nižšie.</p>';
    $html .= '<p><input type="submit"  value="Ísť na stránku TrustPay" /></p>';
		foreach ($post_variables as $name => $value) {
			$html .= '<input type="hidden" name="' . $name . '" value="' . htmlspecialchars ($value) . '" />';
		}
		$html .= '</form></div>';
		$html .= ' <script type="text/javascript">';
		$html .= ' document.vm_trustpay_form.submit();';
		$html .= ' </script></body></html>';

    $modelOrder = VmModel::getModel ('orders');
		$order['customer_notified'] = 1;
		$order['comments'] = '';
		$modelOrder->updateStatusForOneOrder ($order['details']['BT']->virtuemart_order_id, $order, TRUE);

		// 	2 = don't delete the cart, don't send email and don't redirect
		$cart->_confirmDone = FALSE;
		$cart->_dataValidated = FALSE;
		$cart->setCartIntoSession ();
		JRequest::setVar ('html', $html);
	}

  private function CreateInputSIG($data, $key)
  {		
    return $this->GetSign($key, $data['AID'].$data['AMT'].$data['CUR'].$data['REF']);
  }
  
  private function CreateOutputSIG($data, $key)
  {		
    return $this->GetSign($key, $data['AID'].$data['TYP'].$data['AMT'].$data['CUR'].$data['REF'].$data['RES'].$data['TID'].$data['OID'].$data['TSS']);
  }

	/**
	 * @param $virtuemart_paymentmethod_id
	 * @param $paymentCurrencyId
	 * @return bool|null
	 */
	function plgVmgetPaymentCurrency ($virtuemart_paymentmethod_id, &$paymentCurrencyId) {

		if (!($method = $this->getVmPluginMethod ($virtuemart_paymentmethod_id))) {
			return NULL;
		}
		if (!$this->selectedThisElement ($method->payment_element)) {
			return FALSE;
		}
		$this->getPaymentCurrency ($method);
		$paymentCurrencyId = $method->payment_currency;
	}

	/**
	 * @param $virtuemart_paymentmethod_id
	 * @param $paymentCurrencyId
	 * @return bool|null
	 */
	function plgVmgetEmailCurrency ($virtuemart_paymentmethod_id, $virtuemart_order_id, &$emailCurrencyId) 
  {
		if (!($method = $this->getVmPluginMethod ($virtuemart_paymentmethod_id))) {
			return NULL;
		}
		if (!$this->selectedThisElement ($method->payment_element)) {
			return FALSE;
		}
		if (!($payments = $this->getInternalData ($virtuemart_order_id))) {
			return '';
		}
		if (empty($payments[0]->email_currency)) {
			$vendorId = 1;
			$db = JFactory::getDBO ();
			$q = 'SELECT   `vendor_currency` FROM `#__virtuemart_vendors` WHERE `virtuemart_vendor_id`=' . $vendorId;
			$db->setQuery ($q);
			$emailCurrencyId = $db->loadResult ();
		} else {
			$emailCurrencyId = $payments[0]->email_currency;
		}
	}
  
	function getInternalData ($virtuemart_order_id, $order_number = '') 
  {
		$db = JFactory::getDBO ();
		$q = 'SELECT * FROM `' . $this->_tablename . '` WHERE ';
		if ($order_number) {
			$q .= " `order_number` = '" . $order_number . "'";
		} else {
			$q .= ' `virtuemart_order_id` = ' . $virtuemart_order_id;
		}

		$db->setQuery ($q);
		if (!($payments = $db->loadObjectList ())) {
			return '';
		}
		return $payments;
	}

	/**
	 * @return bool|null
	 */
	function plgVmOnUserPaymentCancel () {
		return TRUE;
	}

	/**
	 * plgVmOnPaymentNotification()
	 * This event is fired by Offline Payment. It can be used to validate the payment data as entered by the user.  
	 * @return bool|null
	 */
	function plgVmOnPaymentNotification () {
    
		if (!class_exists ('VirtueMartModelOrders')) {
			require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');
		}
       
    //TrustPay will repeat notification every 5 minute until 200 OK is received within 75 hours (900 attempts).
    exit(header("Status: 200 OK"));
	}
  
  /**
	 * @param $html
	 * @return bool|null|string
	 */
	function plgVmOnPaymentResponseReceived (&$html, &$paymentResponse) 
  {            
    $data = JRequest::get ('get');
    
    $order_number = JRequest::getVar('REF', '');
    $res = JRequest::getInt('RES', '-1');
    
    $paymentResponse = '';
                
    if($order_number == FALSE || $res < 0){
      return NULL;
    }
                            
    if (!($virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber ($order_number))) {
			return NULL;
		}
              
    if (!($payments = $this->getInternalData($virtuemart_order_id))) {
    	return NULL;
    }
    
    if (!($method = $this->getVmPluginMethod ($payments[0]->virtuemart_paymentmethod_id))) {
    	return NULL;
    }
  
  	if (!$this->selectedThisElement($method->payment_element)) {
			return NULL;
		}
  
    $response = $payments[0]->payment_name;
  
    if($res == 0) {
      //OK
      $response.= '<div><span style="color:red">PLATBA PREBEHLA V PORIADKU</span></div>';
    }
    elseif($res == 1) {
     //Pending
     $response.= '<div><span style="color:red">PLATBA NEBOLA ZATIAĽ ZREALIZOVANÁ.</span><br/><span>Prosím, informujte sa o stave objednávky.</span></div>';
    }
    elseif ($res == 1005) {
      //storno
      $response.= '<div><span style="color:red">PLATBA BOLA STORNOVANÁ</span></div>';
    }
    elseif ($res > 1000 && $res != 1005) {
      //ERROR
      $response.= '<div><span style="color:red">POČAS SPRACOVANIA PLATBY SA VYSKYTLA NEOČAKÁVANÁ CHYBA. <br/> PLATBA NEBOLA ZREALIZOVANÁ.</span><br/><span>Prosím, kontaktujte naše obchodné oddelenie.</span><br/>Číslo Vašej objednávky: '.$order_number.'</div>';
    }
    else {
      //neocakavany status, nemalo by nastat
      $response.= '<div><span style="color:red">PLATBA NEBOLA ZATIAĽ ZREALIZOVANÁ.</span><br/><span>Prosím, informujte sa o stave objednávky.</span></div>';
    }
               
		$html = $response;

		return TRUE;
	}
  
  private function GetSign($key, $message)
  {
    return strtoupper(hash_hmac('sha256', pack('A*', $message), pack('A*', $key)));
  }

		/**
	 * Display stored payment data for an order
	 *
	 * @see components/com_virtuemart/helpers/vmPSPlugin::plgVmOnShowOrderBEPayment()
	 */
	function plgVmOnShowOrderBEPayment ($virtuemart_order_id, $payment_method_id) {
                            
		if (!$this->selectedThisByMethodId ($payment_method_id)) {
			return NULL;
		}

		if (!($payments = $this->getInternalData($virtuemart_order_id))) {
			return '';
		}

		$html = '<table class="adminlist" width="50%">' . "\n";
		$html .= $this->getHtmlHeaderBE ();
		$code = "trustpay_response_";
		$first = TRUE;
		foreach ($payments as $payment) {
			$html .= '<tr class="row1"><td>' . JText::_ ('VMPAYMENT_PAYPAL_DATE') . '</td><td align="left">' . $payment->created_on . '</td></tr>';
			// Now only the first entry has this data when creating the order
			if ($first) {
				$html .= $this->getHtmlRowBE ('PAYPAL_PAYMENT_NAME', $payment->payment_name);
				// keep that test to have it backwards compatible. Old version was deleting that column  when receiving an IPN notification
				if ($payment->payment_order_total and  $payment->payment_order_total != 0.00) {
					$html .= $this->getHtmlRowBE ('PAYPAL_PAYMENT_ORDER_TOTAL', $payment->payment_order_total . " " . shopFunctions::getCurrencyByID ($payment->payment_currency, 'currency_code_3'));
				}
				if ($payment->email_currency and  $payment->email_currency != 0) {
					$html .= $this->getHtmlRowBE ('PAYPAL_PAYMENT_EMAIL_CURRENCY', shopFunctions::getCurrencyByID ($payment->email_currency, 'currency_code_3'));
				}
				$first = FALSE;
			}
			foreach ($payment as $key => $value) {
				// only displays if there is a value or the value is different from 0.00 and the value
				if ($value) {
					if (substr ($key, 0, strlen ($code)) == $code) {
						$html .= $this->getHtmlRowBE ($key, $value);
					}
				}
			}

		}
		$html .= '</table>' . "\n";
		return $html;
	}

	private function getTrustPayUrlHttps ($method) 
  {
		return $method->env == 'L' ? 'ib.trustpay.eu/mapi/paymentservice.aspx ' : 'test.trustpay.eu/mapi/paymentservice.aspx';
	}

	/**
	 * Create the table for this plugin if it does not yet exist.
	 * This functions checks if the called plugin is active one.
	 * When yes it is calling the standard method to create the tables
	 */
	function plgVmOnStoreInstallPaymentPluginTable ($jplugin_id) 
  {
		return $this->onStoreInstallPluginTable ($jplugin_id);
	}

	/**
	 * This event is fired after the payment method has been selected. It can be used to store
	 * additional payment info in the cart.
	 * @param VirtueMartCart $cart: the actual cart
	 * @return null if the payment was not selected, true if the data is valid, error message if the data is not vlaid
	 */
	public function plgVmOnSelectCheckPayment (VirtueMartCart $cart, &$msg) 
  {
		return $this->OnSelectCheck ($cart);
	}

	/**
	 * plgVmDisplayListFEPayment
	 * This event is fired to display the pluginmethods in the cart (edit shipment/payment) for exampel
	 *
	 * @param object  $cart Cart object
	 * @param integer $selected ID of the method selected
	 * @return boolean True on succes, false on failures, null when this plugin was not selected.
	 * On errors, JError::raiseWarning (or JError::raiseError) must be used to set a message.
	 */
	public function plgVmDisplayListFEPayment (VirtueMartCart $cart, $selected = 0, &$htmlIn) 
  {
		return $this->displayListFE ($cart, $selected, $htmlIn);
	}

	/*
		 * plgVmonSelectedCalculatePricePayment
		 * Calculate the price (value, tax_id) of the selected method
		 * It is called by the calculator
		 * This function does NOT to be reimplemented. If not reimplemented, then the default values from this function are taken.
		 * @author Valerie Isaksen
		 * @cart: VirtueMartCart the current cart
		 * @cart_prices: array the new cart prices
		 * @return null if the method was not selected, false if the shiiping rate is not valid any more, true otherwise
		 *
		 *
		 */

	/**
	 * @param VirtueMartCart $cart
	 * @param array          $cart_prices
	 * @param                $cart_prices_name
	 * @return bool|null
	 */
	public function plgVmonSelectedCalculatePricePayment (VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name) 
  {
		return $this->onSelectedCalculatePrice ($cart, $cart_prices, $cart_prices_name);
	}

	/**
	 * plgVmOnCheckAutomaticSelectedPayment
	 * Checks how many plugins are available. If only one, the user will not have the choice. Enter edit_xxx page
	 * The plugin must check first if it is the correct type
	 * @param VirtueMartCart cart: the cart object
	 * @return null if no plugin was found, 0 if more then one plugin was found,  virtuemart_xxx_id if only one plugin is found
	 */
	function plgVmOnCheckAutomaticSelectedPayment (VirtueMartCart $cart, array $cart_prices = array(), &$paymentCounter) 
  {
		return $this->onCheckAutomaticSelected ($cart, $cart_prices, $paymentCounter);
	}

	/**
	 * This method is fired when showing the order details in the frontend.
	 * It displays the method-specific data.
	 *
	 * @param integer $order_id The order ID
	 * @return mixed Null for methods that aren't active, text (HTML) otherwise
	 */
	public function plgVmOnShowOrderFEPayment ($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name) 
  {
		$this->onShowOrderFE ($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name);
	}

	/**
	 * This method is fired when showing when priting an Order
	 * It displays the the payment method-specific data.
	 *
	 * @param integer $_virtuemart_order_id The order ID
	 * @param integer $method_id  method used for this order
	 * @return mixed Null when for payment methods that were not selected, text (HTML) otherwise
	 */
	function plgVmonShowOrderPrintPayment ($order_number, $method_id) 
  {
		return $this->onShowOrderPrint ($order_number, $method_id);
	}

	function plgVmDeclarePluginParamsPayment ($name, $id, &$data) 
  {
		return $this->declarePluginParams ('payment', $name, $id, $data);
	}

	/**
	 * @param $name
	 * @param $id
	 * @param $table
	 * @return bool
	 */
	function plgVmSetOnTablePluginParamsPayment ($name, $id, &$table) 
  {
		return $this->setOnTablePluginParams ($name, $id, $table);
	}
  
  /**
	 * Check if the payment conditions are fulfilled for this payment method
	 * @param $cart_prices: cart prices
	 * @param $payment
	 * @return true: if the conditions are fulfilled, false otherwise
	 */
	protected function checkConditions ($cart, $method, $cart_prices) 
  {
    return TRUE;
  }
}
