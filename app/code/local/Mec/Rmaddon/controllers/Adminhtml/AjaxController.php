<?phpclass Mec_Rmaddon_Adminhtml_AjaxController extends Mage_Adminhtml_Controller_Action{			public function ajaxAccraditationRefundAction()	{						$rma_id = $this->getRequest()->getParam('rma_id');		$order_id = $this->getRequest()->getParam('order_id');				$financial_id = Mage::helper('rmaddon')->getFinancialIdByRmaIdAndOrderId($rma_id, $order_id);				$shipping_amount = $this->getRequest()->getParam('refund_accraditation_shipping_amount');		$products_amount = $this->getRequest()->getParam('refund_accraditation_products_amount');				$data = array();		$data['rma_id'] = $rma_id;		$data['main_order_id'] = $order_id;		$data['amount'] = $products_amount;		$data['shipping_amount'] = $shipping_amount;		$data['status'] = 1;				Mage::getModel("rmaddon/financial")						->addData($data)						->setId($financial_id)						->save();				$log_text = "";		if($financial_id == null){			$log_text = Mage::helper('rmaddon')->__('Create Accraditation Shipping Amount %s, Products Amount %s', $shipping_amount, $products_amount); 		}else{			$log_text = Mage::helper('rmaddon')->__('Update Accraditation Shipping Amount %s, Products Amount %s', $shipping_amount, $products_amount); 		}				$log_data_array = array(			'rma_id' => $rma_id,			// 'receive_id'=> $model->getId(),			'log_text' => $log_text,			// 'comment' => $note,			'create_at' => time(),			'order_id' => $order_id		);		Mage::helper('rmaddon')->addLog($log_data_array);				$this->getResponse()->setBody(Zend_Json::encode(array('status' => true, 'txt' => 'ok')));			}					public function ajaxRefundAction()	{			$items_info = array();		$items = array_filter($this->getRequest()->getParam('items'));		$order_id = $this->getRequest()->getParam('order_id');		$rma_id = $this->getRequest()->getParam('rma_id');				$refund_products_amount = $this->getRequest()->getParam('refund_products_amount');		$refund_shipping_amount = $this->getRequest()->getParam('refund_shipping_amount');				$tip_amount = Mage::helper('rmaddon')->getRefundAmountTip($rma_id, $order_id);		$refund_products_amount = $tip_amount - $refund_products_amount;				$rma_obj = Mage::getModel('awrma/entity')->load($rma_id);		$rma_items = $rma_obj->getOrderItems();				foreach($rma_items as $order_item_id => $qty){			if($items[$order_item_id] != "" && $items[$order_item_id] > 0){				$items_info[$order_item_id] = array(					'qty' => $qty,					'back_to_stock' => $items[$order_item_id]				);			}else{				$items_info[$order_item_id] = array(					'qty' => $qty					// 'back_to_stock' => $items[$order_item_id]				);			}		}				$receive = Mage::getModel('rmaddon/receivelist')->getCollection()				   ->addFieldToFilter('rma_id', array('eq' => $rma_id))				   ->load();				   		$receive_status = 0;		foreach($receive as $_receive){			$receive_status = $_receive->getStatus();			break;		}				$creditmemo_info = $this->creaDevolucio($items_info, $order_id, $refund_shipping_amount, $refund_products_amount, $rma_obj);				//退款完毕，把客服的审批单子状态设置掉		$financial_id = Mage::helper('rmaddon')->getFinancialIdByRmaIdAndOrderId($rma_id, $order_id);			$financial_obj = Mage::getModel('rmaddon/financial')->load($financial_id);		$financial_obj->setStatus(2)->save();				if($receive_status != 3){			$rma_obj->setStatus(7)->save();			Mage::getModel('awrma/notify')->notifyNew($rma_obj, null);		}elseif($receive_status == 3){			$rma_obj->setStatus(5)->save();			Mage::getModel('awrma/notify')->notifyNew($rma_obj, null);		}				$this->getResponse()->setBody(Zend_Json::encode(array('txt' => $creditmemo_info)));	}		protected function _initCreditmemo($data, $order_id, $update = false)	{			$error = array();		$creditmemo = false;		$invoice=false;		$creditmemoId = null;//$this->getRequest()->getParam('creditmemo_id');		// $orderId = $info['order_increment_id'];//$this->getRequest()->getParam('order_id');		$invoiceId = $data['invoice_id'];		// echo "<br>abans if. OrderId: ".$orderId;		if ($creditmemoId) {			$creditmemo = Mage::getModel('sales/order_creditmemo')->load($creditmemoId);		} elseif ($order_id) {			$order  = Mage::getModel('sales/order')->load($order_id);			if ($invoiceId) {				$invoice = Mage::getModel('sales/order_invoice')					->load($invoiceId)					->setOrder($order);							}			 if (!$order->canCreditmemo()) {				// echo '<br>cannot create credit memo'; 				$error[] = Mage::helper('rmaddon')->__('cannot create credit memo');				if(!$order->isPaymentReview())				{					// echo '<br>cannot credit memo Payment is in review'; 					$error[] = Mage::helper('rmaddon')->__('cannot credit memo Payment is in review');				}				if(!$order->canUnhold())				{					// echo '<br>cannot credit memo Order is on hold'; 					$error[] = Mage::helper('rmaddon')->__('cannot credit memo Order is on hold');				}				if(abs($order->getTotalPaid()-$order->getTotalRefunded())<.0001)				{					$error[] = Mage::helper('rmaddon')->__('cannot credit memo Amount Paid is equal or less than amount refunded');					// echo '<br>cannot credit memo Amount Paid is equal or less than amount refunded'; 				}				if($order->getActionFlag('edit') === false)				{					// echo '<br>cannot credit memo Action Flag of Edit not set'; 					$error[] = Mage::helper('rmaddon')->__('cannot credit memo Action Flag of Edit not set');				}				if ($order->hasForcedCanCreditmemo()) {					$error[] = Mage::helper('rmaddon')->__('cannot credit memo Can Credit Memo has been forced set');				}				return array(					'status' => false,					'error' => $error				);			}			$savedData = array();			if (isset($data['items'])) {				$savedData = $data['items'];			} else {				$savedData = array();			}			$qtys = array();			$backToStock = array();			foreach ($savedData as $orderItemId =>$itemData) {				if (isset($itemData['qty'])) {					$qtys[$orderItemId] = $itemData['qty'];				}				if (isset($itemData['back_to_stock'])) {					$backToStock[$orderItemId] = true;				}			}			$data['qtys'] = $qtys;			$service = Mage::getModel('sales/service_order', $order);			if ($invoice) {				$creditmemo = $service->prepareInvoiceCreditmemo($invoice, $data);			} else {				$creditmemo = $service->prepareCreditmemo($data);			}			/**			 * Process back to stock flags			 */			foreach ($creditmemo->getAllItems() as $creditmemoItem) {				$orderItem = $creditmemoItem->getOrderItem();				$parentId = $orderItem->getParentItemId();				if (isset($backToStock[$orderItem->getId()])) {					$creditmemoItem->setBackToStock(true);				} elseif ($orderItem->getParentItem() && isset($backToStock[$parentId]) && $backToStock[$parentId]) {					$creditmemoItem->setBackToStock(true);				} elseif (empty($savedData)) {					$creditmemoItem->setBackToStock(Mage::helper('cataloginventory')->isAutoReturnEnabled());				} else {					$creditmemoItem->setBackToStock(false);				}			}		}		return array(			'status' => true,			'creditmemo' => $creditmemo		);			}				protected function creaDevolucio($items, $order_id, $shipping_amount, $adjustment_negative, $rma_obj){		// $qtys = array();		// foreach ($dif as $item) {			// if (isset($item['qty'])) {				// $qtys[$item['order_item_id']] = array("qty"=> $item['qty']);			// }			// if (isset($item['back_to_stock'])) {				// $backToStock[$item['order_item_id']] = true;			// }		// }		$order = Mage::getModel('sales/order')->load($order_id);		$data = array(			"items" => $items,			"do_offline" => "1",			"comment_text" => "",			"shipping_amount" => $shipping_amount,			"adjustment_positive" => "0",			"adjustment_negative" => $adjustment_negative,			'send_email' => $order->getCustomerEmail()		);						if ($order->hasInvoices()) {			$invIncrementIDs = array();			foreach ($order->getInvoiceCollection() as $inv) {				$invIncrementIDs[] = $inv->getEntityId();			//other invoice details...			} 			// Mage::log($invIncrementIDs);		}						$data['invoice_id'] = $invIncrementIDs[0];				if (!empty($data['comment_text'])) {			Mage::getSingleton('adminhtml/session')->setCommentText($data['comment_text']);		}					$creditmemo_array = $this->_initCreditmemo($data, $order_id);									if ($creditmemo_array['status']){				$creditmemo = $creditmemo_array['creditmemo'];					if (($creditmemo->getGrandTotal() <=0) && (!$creditmemo->getAllowZeroGrandTotal())) {					Mage::throwException(						$this->__('Credit memo\'s total must be positive.')					);				}				$comment = '';				if (!empty($data['comment_text'])) {					$creditmemo->addComment(						$data['comment_text'],						isset($data['comment_customer_notify']),						isset($data['is_visible_on_front'])					);					if (isset($data['comment_customer_notify'])) {						$comment = $data['comment_text'];					}				}				if (isset($data['do_refund'])) {					$creditmemo->setRefundRequested(true);				}				if (isset($data['do_offline'])) {					$creditmemo->setOfflineRequested((bool)(int)$data['do_offline']);				}				$creditmemo->register();				if (!empty($data['send_email'])) {					$creditmemo->setEmailSent(true);				}				$creditmemo->getOrder()->setCustomerNoteNotify(!empty($data['send_email']));				$this->_saveCreditmemo($creditmemo);				$creditmemo->sendEmail(!empty($data['send_email']), $comment);				// echo '<br>The credit memo has been created.';								// Mage::log('$creditmemo');				// Mage::log($creditmemo->getEntityId());				Mage::getSingleton('adminhtml/session')->getCommentText(true);				$rma_id = $rma_obj->getId();				$rma_obj->setCreditMemoId($creditmemo->getEntityId())->save();				$log_data_array = array(					'rma_id' => $rma_id,					// 'receive_id'=> $model->getId(),					'log_text' => Mage::helper('awrma')->__('The Rma Create Credit Memo %s', $creditmemo->getIncrementId()),					// 'comment' => $note,					'create_at' => time(),					'order_id' => $order->getEntityId()				);					Mage::helper('rmaddon')->addLog($log_data_array);				return 'The credit memo has been created.';			} else {					return $creditmemo_array['error'];			}			}		protected function _saveCreditmemo($creditmemo)	{		$transactionSave = Mage::getModel('core/resource_transaction')			->addObject($creditmemo)			->addObject($creditmemo->getOrder());		if ($creditmemo->getInvoice()) {			$transactionSave->addObject($creditmemo->getInvoice());		}		$transactionSave->save();		return $this;	}			/************************************************* Replace Function Start *************************************************/			public function ajaxRepalceCreateAction()	{		$replace_info = $this->getRequest()->getParam('replace_info');		$rma_id = $this->getRequest()->getParam('rma_id');		$order_id = $this->getRequest()->getParam('order_id');				$replace_info = array_filter($replace_info);		$order_amount = $this->getRequest()->getParam('order_amount');										if(count($replace_info) == 0){			$this->getResponse()->setBody(Zend_Json::encode(array('status' => false, 'error' => Mage::helper('rmaddon')->__('Repalce Qty Is 0'))));			return;		}						$order = Mage::getModel('sales/order')->load($order_id);		$customer_id = $order->getCustomerId();		$customer = Mage::getModel('customer/customer');		$customer->setWebsiteId(Mage::app()->getWebsite()->getId());		$customer->load($customer_id);								$quote = Mage::getModel('sales/quote');		$quote->assignCustomer($customer);				$quote->setStore(Mage::app()->getStore());    		$productModel = Mage::getModel('catalog/product');				foreach($replace_info as $item_id => $qty){			foreach($order->getAllItems() as $_item){				if($_item->getQuoteItemId() == $item_id){					$productModel->load($_item->getProductId());					$quoteItem = Mage::getModel('sales/quote_item')->setProduct($productModel);					$quoteItem->setQuote($quote);					$quoteItem->setQty($qty);					$quoteItem->setCustomPrice($order_amount/$qty);					$quoteItem->setOriginalCustomPrice($order_amount/$qty);					$quote->addItem($quoteItem);				}				}		}						$addressForm = Mage::getModel('customer/form');		$addressForm->setFormCode('customer_address_edit')					->setEntityType('customer_address');		foreach ($addressForm->getAttributes() as $attribute) {			if (isset($shippingAddress[$attribute->getAttributeCode()])) {				$quote->getShippingAddress()->setData($attribute->getAttributeCode(), $shippingAddress[$attribute->getAttributeCode()]);			}		}		foreach ($addressForm->getAttributes() as $attribute) {			if (isset($billingAddress[$attribute->getAttributeCode()])) {				$quote->getBillingAddress()->setData($attribute->getAttributeCode(), $billingAddress[$attribute->getAttributeCode()]);			}		}		$quote->getShippingAddress()->setShippingMethod($order->getShippingMethod());		$quote->getShippingAddress()->setCollectShippingRates(true);		$quote->getShippingAddress()->collectShippingRates(); 		$quote->collectTotals();    		$quote->save();				$items = $quote->getAllItems();		$quote->reserveOrderId();				$quotePayment = $quote->getPayment(); // Mage_Sales_Model_Quote_Payment		$quotePayment->setMethod($order->getPayment()->getMethodInstance()->getCode());		$quote->setPayment($quotePayment);				$convertQuote = Mage::getSingleton('sales/convert_quote');		$new_order = $convertQuote->addressToOrder($quote->getShippingAddress());		$orderPayment = $convertQuote->paymentToOrderPayment($quotePayment);		$new_order->setBillingAddress($convertQuote->addressToOrderAddress($quote->getBillingAddress()));		$new_order->setShippingAddress($convertQuote->addressToOrderAddress($quote->getShippingAddress()));		$new_order->setPayment($convertQuote->paymentToOrderPayment($quote->getPayment()));				foreach ($items as $item) {			$orderItem = $convertQuote->itemToOrderItem($item);			if ($item->getParentItem()) {				$orderItem->setParentItem($new_order->getItemByQuoteItemId($item->getParentItem()->getId()));			}			$new_order->addItem($orderItem);		}				try {			$new_order->place();			$new_order->save(); 			$new_order->setRmaId($rma_id);			$new_order->setMainOrder($order_id);			$new_order->save(); 			$log_data_array = array(					'rma_id' => $rma_id,					// 'receive_id'=> $model->getId(),					'log_text' => Mage::helper('awrma')->__('The Replace Order Has Create %s', $new_order->getIncrementId()),					// 'comment' => $note,					'create_at' => time(),					'order_id' => $order_id			);				Mage::helper('rmaddon')->addLog($log_data_array);			$this->getResponse()->setBody(Zend_Json::encode(array('status' => true, 'txt' => Mage::helper('rmaddon')->__('Repalce Created'))));		} catch (Exception $e){        			Mage::log($e->getMessage());		}			}							}