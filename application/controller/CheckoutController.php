<?php

ClassLoader::import('application.model.Currency');
ClassLoader::import('application.model.order.CustomerOrder');
ClassLoader::import('application.model.order.ExpressCheckout');
ClassLoader::import('application.model.order.Transaction');
ClassLoader::import('application.model.order.LiveCartTransaction');
ClassLoader::import('application.model.order.SessionOrder');

/**
 *  Handles order checkout process
 *
 *  The order checkout consists of the following steps:
 *
 *  1. Determine user status
 *
 *	  If the user is logged in, this step is skipped
 *	  If the user is not logged in there are 2 or 3 choices depending on configuration:
 *		  a) log in
 *		  b) create a new user account
 *		  c) continue checkout without registration (anonymous checkout).
 *			 In this case the user account will be created automatically
 *
 *  2. Process login
 *
 *	  If the user is already logged in or is checking out anonymously this step is skipped.
 *
 *  3. Select or enter billing and shipping addresses
 *
 *	  If the user has just been registered, this step is skipped, as these addresses have already been provided
 *	  If the user was logged in, the billing and shipping addresses have to be selected (or new addresses entered/edited)
 *
 *  4. Select shipping method and calculate tax
 *
 *	  Based on the shipping addresses, determine the available shipping methods and costs.
 *	  Based on the shipping or billing address (depending on config), calculate taxes if any.
 *
 *  5. Confirm order totals and select payment method
 *
 *  6. Enter payment details
 *
 *	  Redirected to external site if it's a 3rd party payment processor (like Paypal)
 *	  This step is skipped if a non-online payment method is selected (check, wire transfer, phone, etc.)
 *
 *  7. Process payment and reserve products
 *
 *	  This step is skipped also if the payment wasn't made
 *	  If the payment was attempted, but unsuccessful, return to payment form (6)
 *
 *  8. Process order and send invoice (optional)
 *
 *	  Whether the order is processed, depends on the configuration (auto vs manual processing)
 *
 *  9. Show the order confirmation page
 *
 * @author Integry Systems
 * @package application.controller
 */
class CheckoutController extends FrontendController
{
	const STEP_ADDRESS = 3;
	const STEP_SHIPPING = 4;
	const STEP_PAYMENT = 5;

	public function init()
	{
		parent::init();
		$this->addBreadCrumb($this->translate('_checkout'), $this->router->createUrl(array('controller' => 'order', 'action' => 'index'), true));

		$action = $this->request->getActionName();

		if ('index' == $action)
		{
			return false;
		}

		$this->addBreadCrumb($this->translate('_select_addresses'), $this->router->createUrl(array('controller' => 'checkout', 'action' => 'selectAddress'), true));

		if ('selectAddress' == $action)
		{
			return false;
		}

		$this->addBreadCrumb($this->translate('_shipping'), $this->router->createUrl(array('controller' => 'checkout', 'action' => 'shipping'), true));

		if ('shipping' == $action)
		{
			return false;
		}

		$this->addBreadCrumb($this->translate('_pay'), $this->router->createUrl(array('controller' => 'checkout', 'action' => 'pay'), true));
	}

	/**
	 *  1. Determine user status
	 */
	public function index()
	{
		if ($this->user->isLoggedIn())
		{
			// try to go to payment page
			return new ActionRedirectResponse('checkout', 'pay');
		}
		else
		{
			return new ActionRedirectResponse('user', 'checkout');
		}
	}

	/**
	 *  Redirect to an external site to acquire customer information and initiate payment (express checkout)
	 */
	public function express()
	{
		// redirect to external site
		$class = $this->request->get('id');

		$handler = $this->application->getExpressPaymentHandler($class, $this->getTransaction());

		$returnUrl = $this->router->createFullUrl($this->router->createUrl(array('controller' => 'checkout', 'action' => 'expressReturn', 'id' => $class), true));
		$cancelUrl = $this->router->createFullUrl($this->router->createUrl(array('controller' => 'order'), true));

		$url = $handler->getInitUrl($returnUrl, $cancelUrl, !$handler->getConfigValue('AUTHONLY'));

		return new RedirectResponse($url);
	}

	public function expressReturn()
	{
		$class = $this->request->get('id');

		$handler = $this->application->getExpressPaymentHandler($class, $this->getTransaction());
		$details = $handler->getTransactionDetails($this->request->toArray());

		$address = UserAddress::getNewInstanceByTransaction($details);
		$address->save();

		$paymentData = array_diff_key($this->request->toArray(), array_flip(array('controller', 'action', 'id', 'route', 'PHPSESSID')));

		// @todo - determine if the order is new or completed earlier, but unpaid
		// for now only new orders can be paid with express checkout methods
		$order = $this->getPaymentOrder();
		$express = ExpressCheckout::getNewInstance($order, $handler);
		$express->address->set($address);
		$express->paymentData->set(serialize($paymentData));
		$express->save();

		// auto-login user if anonymous
		if ($this->user->isAnonymous())
		{
			// create new user account if it doesn't exist
			if (!($user = User::getInstanceByEmail($details->email->get())))
			{
				$user = User::getNewInstance($details->email->get());
				$user->firstName->set($details->firstName->get());
				$user->lastName->set($details->lastName->get());
				$user->companyName->set($details->companyName->get());
				$user->isEnabled->set(true);
				$user->save();
			}

			SessionUser::setUser($user);
			$order->user->set($user);
		}

		$order->billingAddress->set($address);
		$order->shippingAddress->set($address);
		$order->save();

		return new ActionRedirectResponse('checkout', 'shipping');
	}

	/**
	 *  3. Select or enter billing and shipping addresses
	 *	@role login
	 */
	public function selectAddress()
	{
		$this->user->loadAddresses();

		if ($redirect = $this->validateOrder($this->order))
		{
			return $redirect;
		}

		$step = $this->config->get('ENABLE_CHECKOUTDELIVERYSTEP') ? $this->request->get('step', 'billing') : null;

		$form = $this->buildAddressSelectorForm($this->order, $step);

		if ($this->order->billingAddress->get())
		{
			$form->set('billingAddress', $this->order->billingAddress->get()->getID());
		}
		else
		{
			if ($this->user->defaultBillingAddress->get())
			{
				$form->set('billingAddress', $this->user->defaultBillingAddress->get()->userAddress->get()->getID());
			}
		}

		if ($this->order->shippingAddress->get())
		{
			$form->set('shippingAddress', $this->order->shippingAddress->get()->getID());
		}
		else
		{
			if ($this->user->defaultShippingAddress->get())
			{
				$form->set('shippingAddress', $this->user->defaultShippingAddress->get()->userAddress->get()->getID());
			}
		}

		if (!$form->get('checkbox_sameAsBilling'))
		{
			$form->set('sameAsBilling', (int)($form->get('billingAddress') == $form->get('shippingAddress') || !$this->user->defaultShippingAddress->get()));
		}

		$addressTypes = array('billing');
		if (!$this->config->get('ENABLE_CHECKOUTDELIVERYSTEP'))
		{
			$addressTypes[] = 'shipping';
		}

		foreach (array('firstName', 'lastName') as $name)
		{
			foreach ($addressTypes as $type)
			{
				$var = $type . '_' . $name;
				if (!$form->get($var))
				{
					$form->set($var, $this->user->$name->get());
				}
			}
		}

		$response = new ActionResponse();
		$response->set('billingAddresses', $this->user->getBillingAddressArray());
		$response->set('shippingAddresses', $this->user->getShippingAddressArray());
		$response->set('form', $form);
		$response->set('order', $this->order->toArray());
		$response->set('countries', $this->getCountryList($form));
		$response->set('billing_states', $this->getStateList($form->get('billing_country')));
		$response->set('shipping_states', $this->getStateList($form->get('shipping_country')));
		$response->set('step', $step);

		return $response;
	}

	/**
	 *	@role login
	 */
	public function doSelectAddress()
	{
		$this->user->loadAddresses();

		$step = $this->request->get('step');

		$validator = $this->buildAddressSelectorValidator($this->order, $step);
		if (!$validator->isValid())
		{
			return new ActionRedirectResponse('checkout', 'selectAddress', array('query' => array('step' => $step)));
		}

		// create a new billing address
		if (!$step || ('billing' == $step))
		{
			if (!$this->request->get('billingAddress'))
			{
				$this->request->set('billingAddress', $this->createAddress('BillingAddress', 'billing_')->userAddress->get()->getID());
			}
		}

		// create a new shipping address
		if (!$step || ('shipping' == $step))
		{
			if (!$this->request->get('shippingAddress') && !$this->request->get('sameAsBilling'))
			{
				$this->request->set('shippingAddress', $this->createAddress('ShippingAddress', 'shipping_')->userAddress->get()->getID());
			}
		}

		try
		{
			if (!$step || ('billing' == $step))
			{
				$f = new ARSelectFilter();
				$f->setCondition(new EqualsCond(new ARFieldHandle('BillingAddress', 'userID'), $this->user->getID()));
				$f->mergeCondition(new EqualsCond(new ARFieldHandle('BillingAddress', 'userAddressID'), $this->request->get('billingAddress')));
				$r = ActiveRecordModel::getRecordSet('BillingAddress', $f, array('UserAddress'));

				if (!$r->size())
				{
					throw new ApplicationException('Invalid billing address');
				}

				$billing = $r->get(0);
				$this->order->billingAddress->set($billing->userAddress->get());
			}

			// shipping address
			if ($this->order->isShippingRequired() && !$this->order->isMultiAddress->get() & (!$step || ('shipping' == $step)))
			{
				if ($this->request->get('sameAsBilling'))
				{
					$shipping = $billing;
				}
				else
				{
					$f = new ARSelectFilter();
					$f->setCondition(new EqualsCond(new ARFieldHandle('ShippingAddress', 'userID'), $this->user->getID()));
					$f->mergeCondition(new EqualsCond(new ARFieldHandle('ShippingAddress', 'userAddressID'), $this->request->get('shippingAddress')));
					$r = ActiveRecordModel::getRecordSet('ShippingAddress', $f, array('UserAddress'));

					if (!$r->size())
					{
						throw new ApplicationException('Invalid shipping address');
					}

					$shipping = $r->get(0);
				}

				$this->order->shippingAddress->set($shipping->userAddress->get());
			}

			$this->order->resetShipments();
		}
		catch (Exception $e)
		{
			throw $e;
			return new ActionRedirectResponse('checkout', 'selectAddress', array('query' => array('step' => $step)));
		}

		SessionOrder::save($this->order);

		if (('billing' == $step) && ($this->order->isShippingRequired() && !$this->order->isMultiAddress->get()))
		{
			return new ActionRedirectResponse('checkout', 'selectAddress', array('query' => array('step' => 'shipping')));
		}
		else
		{
			return new ActionRedirectResponse('checkout', 'shipping');
		}
	}

	/**
	 *  4. Select shipping methods
	 *	@role login
	 */
	public function shipping()
	{
		if ($redirect = $this->validateOrder($this->order, self::STEP_SHIPPING))
		{
			return $redirect;
		}

		if (!$this->order->isShippingRequired())
		{
			return new ActionRedirectResponse('checkout', 'pay');
		}

		$shipments = $this->order->getShipments();

		foreach($shipments as $shipment)
		{
			if(count($shipment->getItems()) == 0)
			{
				$shipment->delete();
				$shipments->removeRecord($shipment);
			}
		}

		$form = $this->buildShippingForm($shipments);

		$needSelecting = false;

		foreach ($shipments as $key => $shipment)
		{
			$shipmentRates = $shipment->getAvailableRates();

			if ($shipmentRates->size() > 1)
			{
				$needSelecting = true;
			}
			else if (!$shipmentRates->size())
			{
				$validator = $this->buildAddressSelectorValidator($this->order);
				$validator->triggerError('selectedAddress', $this->translate('_err_no_rates_for_address'));
				$validator->saveState();

			 	return new ActionRedirectResponse('checkout', 'selectAddress');
			}
			else
			{
				$shipment->setRateId($shipmentRates->get(0)->getServiceId());
				if ($this->order->isMultiAddress->get())
				{
					$shipment->save();
				}
			}

			$rates[$key] = $shipmentRates;
			if ($shipment->getSelectedRate())
			{
				$form->set('shipping_' . $key, $shipment->getSelectedRate()->getServiceID());
			}

			if (!$shipment->isShippable())
			{
				$download = $shipment;
				$downloadIndex = $key;
			}
		}

		SessionOrder::save($this->order);

		// only one shipping method for each shipment, so we pre-select it automatically
		if (!$needSelecting)
		{
			$this->order->serializeShipments();
			SessionOrder::save($this->order);
			return new ActionRedirectResponse('checkout', 'pay');
		}

		$rateArray = array();
		foreach ($rates as $key => $rate)
		{
			$rateArray[$key] = $rate->toArray();
		}

		$response = new ActionResponse();

		$shipmentArray = $shipments->toArray();

		if (isset($download))
		{
			$response->set('download', $download->toArray());
			unset($shipmentArray[$downloadIndex]);
		}

		$response->set('shipments', $shipmentArray);
		$response->set('rates', $rateArray);
		$response->set('currency', $this->getRequestCurrency());
		$response->set('form', $form);
		$response->set('order', $this->order->toArray());

		return $response;
	}

	/**
	 *	@role login
	 */
	public function doSelectShippingMethod()
	{
		$shipments = $this->order->getShipments();

		if (!$this->buildShippingValidator($shipments)->isValid())
		{
			return new ActionRedirectResponse('checkout', 'shipping');
		}

		foreach ($shipments as $key => $shipment)
		{
			if ($shipment->isShippable())
			{
				$rates = $shipment->getAvailableRates();

				$selectedRateId = $this->request->get('shipping_' . $key);

				if (!$rates->getByServiceId($selectedRateId))
				{
					throw new ApplicationException('No rate found: ' . $key .' (' . $selectedRateId . ')');
					return new ActionRedirectResponse('checkout', 'shipping');
				}

				$shipment->setRateId($selectedRateId);

				if ($this->order->isMultiAddress->get())
				{
					$shipment->save();
				}
			}
		}

		if (!$this->order->isMultiAddress->get())
		{
			$this->order->serializeShipments();
		}

		SessionOrder::save($this->order);

		return new ActionRedirectResponse('checkout', 'pay');
	}

	/**
	 *  5. Make payment
	 *	@role login
	 */
	public function pay()
	{
		$this->order->loadAll();
		$this->order->getSpecification();

		// @todo: variation prices appear as 0.00 without the extra toArray() call :/
		$this->order->toArray();

		if ($redirect = $this->validateOrder($this->order, self::STEP_PAYMENT))
		{
			return $redirect;
		}

		// @todo: the addresses should be loaded automatically...
		foreach ($this->order->getShipments() as $shipment)
		{
			if ($shipment->shippingAddress->get())
			{
				$shipment->shippingAddress->get()->load();
			}
		}

		// check for express checkout data for this order
		if (ExpressCheckout::getInstanceByOrder($this->order))
		{
			return new ActionRedirectResponse('checkout', 'payExpress');
		}

		$currency = $this->request->get('currency', $this->application->getDefaultCurrencyCode());

		$response = new ActionResponse();
		$response->set('order', $this->order->toArray());
		$response->set('currency', $this->getRequestCurrency());

		$this->setPaymentMethodResponse($response);

		$external = $this->application->getPaymentHandlerList(true);
		// auto redirect to external payment page if only one handler is enabled
		if (1 == count($external) && !$this->config->get('OFFLINE_PAYMENT') && !$this->config->get('CC_ENABLE') && !$response->get('ccForm')->getValidator()->getErrorList())
		{
			$this->request->set('id', $external[0]);
			return $this->redirect();
		}

		return $response;
	}

	public function setPaymentMethodResponse(ActionResponse $response)
	{
		$ccHandler = $this->application->getCreditCardHandler();
		$ccForm = $this->buildCreditCardForm();
		$response->set('ccForm', $ccForm);
		if ($ccHandler)
		{
			$response->set('ccHandler', $ccHandler->toArray());

			$months = range(1, 12);
			$months = array_combine($months, $months);

			$years = range(date('Y'), date('Y') + 20);
			$years = array_combine($years, $years);

			$response->set('months', $months);
			$response->set('years', $years);
			$response->set('ccTypes', $this->application->getCardTypes($ccHandler));
		}

		// other payment methods
		$external = $this->application->getPaymentHandlerList(true);
		$response->set('otherMethods', $external);
	}

	/**
	 *	@role login
	 */
	public function payCreditCard()
	{
		if ($id = $this->request->get('id'))
		{
			$this->request->set('order', $id);
		}

		$order = $this->getPaymentOrder();

		if ($redirect = $this->validateOrder($order, self::STEP_PAYMENT))
		{
			return $redirect;
		}

		if (!$this->buildCreditCardValidator()->isValid())
		{
			return $this->getPaymentPageRedirect();
		}

		// already paid?
		if ($order->isPaid->get())
		{
			return new ActionRedirectResponse('checkout', 'completed');
		}

		ActiveRecordModel::beginTransaction();

		// process payment
		$handler = $this->application->getCreditCardHandler($this->getTransaction());
		if ($this->request->isValueSet('ccType'))
		{
			$handler->setCardType($this->request->get('ccType'));
		}

		$handler->setCardData($this->request->get('ccNum'), $this->request->get('ccExpiryMonth'), $this->request->get('ccExpiryYear'), $this->request->get('ccCVV'));

		if ($this->config->get('CC_AUTHONLY'))
		{
			$result = $handler->authorize();
		}
		else
		{
			$result = $handler->authorizeAndCapture();
		}

		if ($result instanceof TransactionResult)
		{
			$response = $this->registerPayment($result, $handler);
		}
		elseif ($result instanceof TransactionError)
		{
			// set error message for credit card form
			$validator = $this->buildCreditCardValidator();
			$validator->triggerError('creditCardError', $this->translate('_err_processing_cc'));
			$validator->saveState();

			$response = $this->getPaymentPageRedirect();
		}
		else
		{
			var_dump($result);
			throw new Exception('Unknown transaction result type: ' . get_class($result));
		}

		ActiveRecordModel::commit();

		return $response;
	}

	private function getPaymentPageRedirect()
	{
		if ($id = $this->request->get('id'))
		{
			return new ActionRedirectResponse('user', 'pay', array('id' => $id));
		}
		else
		{
			return new ActionRedirectResponse('checkout', 'pay');
		}
	}

	/**
	 *	@role login
	 */
	public function payOffline()
	{
		if (!$this->config->get('OFFLINE_PAYMENT'))
		{
			return new ActionRedirectResponse('checkout', 'pay');
		}

		return $this->finalizeOrder();
	}

	/**
	 *	@role login
	 */
	public function payExpress()
	{
		$res = $this->validateExpressCheckout();
		if ($res instanceof Response)
		{
			return $res;
		}

		$response = new ActionResponse;
		$response->set('order', $this->order->toArray());
		$response->set('currency', $this->getRequestCurrency());
		$response->set('method', $res->toArray());
		return $response;
	}

	/**
	 *	@role login
	 */
	public function payExpressComplete()
	{
		$res = $this->validateExpressCheckout();
		if ($res instanceof Response)
		{
			return $res;
		}

		$handler = $res->getHandler($this->getTransaction());
		if ($handler->getConfigValue('AUTHONLY'))
		{
			$result = $handler->authorize();
		}
		else
		{
			$result = $handler->authorizeAndCapture();
		}

		if ($result instanceof TransactionResult)
		{
			return $this->registerPayment($result, $handler);
		}
		elseif ($result instanceof TransactionError)
		{
			ExpressCheckout::deleteInstancesByOrder($this->order);

			// set error message for credit card form
			$validator = $this->buildCreditCardValidator();
			$validator->triggerError('creditCardError', $result->getMessage());
			$validator->saveState();

			return $this->getPaymentPageRedirect();
		}
		else
		{
			throw new Exception('Unknown transaction result type: ' . get_class($result));
		}
	}

	/**
	 *  Redirect to a 3rd party payment processor website to complete the payment
	 *  (Paypal IPN, 2Checkout, Moneybookers, etc)
	 *
	 *	@role login
	 */
	public function redirect()
	{
		$order = $this->getPaymentOrder();
		if ($redirect = $this->validateOrder($order, self::STEP_PAYMENT))
		{
			return $redirect;
		}

		$notifyParams = $this->request->isValueSet('order') ? array('order' => $this->request->get('order')) : array();

		$class = $this->request->get('id');
		$handler = $this->application->getPaymentHandler($class, $this->getTransaction());
		$handler->setNotifyUrl($this->router->createFullUrl($this->router->createUrl(array('controller' => 'checkout', 'action' => 'notify', 'id' => $class, 'query' => $notifyParams))));
		$handler->setReturnUrl($this->router->createFullUrl($this->router->createUrl(array('controller' => 'checkout', 'action' => 'completeExternal', 'id' => $order->getID()))));
		$handler->setCancelUrl($this->router->createFullUrl($this->router->createUrl(array('controller' => 'checkout', 'action' => 'pay'))));
		$handler->setSiteUrl($this->router->createFullUrl($this->router->createUrl(array('controller' => 'index', 'action' => 'index'))));

		// transaction information is not return back online, so the order is finalized right away
		if (!$handler->isNotify())
		{
			$this->finalizeOrder();
		}

		if ($handler->isPostRedirect())
		{
			$response = new ActionResponse();
			$response->set('url', $handler->getUrl());
			$response->set('params', $handler->getPostParams());
			return $response;
		}

		return new RedirectResponse($handler->getUrl());
	}

	/**
	 *  Payment confirmation post-back URL for 3rd party payment processors
	 *  (Paypal IPN, 2Checkout, Moneybookers, etc)
	 */
	public function notify()
	{
		$handler = $this->application->getPaymentHandler($this->request->get('id'));
		$orderId = $handler->getOrderIdFromRequest($this->request->toArray());

		if (!$this->getRequestCurrency())
		{
			$this->request->set('currency', $handler->getCurrency($this->request->get('currency')));
		}

		$order = CustomerOrder::getInstanceById($orderId, CustomerOrder::LOAD_DATA);
		$order->loadAll();
		$this->order = $order;
		$handler->setDetails($this->getTransaction());

		$result = $handler->notify($this->request->toArray());

		if ($result instanceof TransactionResult)
		{
			$this->registerPayment($result, $handler);
		}
		else
		{
			// set error message for credit card form
			$validator = $this->buildCreditCardValidator();
			$validator->triggerError('creditCardError', $result->getMessage());
			$validator->saveState();

			return $this->getPaymentPageRedirect();
		}

		// determine if the notification URL is called by payment gateway or the customer himself
		// this shouldn't usually happen though as the payment notifications should be sent by gateway
		if ($order->user->get() == $this->user)
		{
			$this->request->set('id', $this->order->getID());
			return $this->completeExternal();
		}

		// some payment gateways (2Checkout, for example) require to return HTML response
		// to be displayed after the payment. In this case we're doing meta-redirect to get back to our site.
		else if ($handler->isHtmlResponse())
		{
			$returnUrl = $handler->getReturnUrlFromRequest($this->request->toArray());
			if (!$returnUrl)
			{
				$returnUrl = $this->router->createUrl(array('controller' => 'checkout', 'action' => 'completed', 'query' => array('id' => $this->order->getID())));
			}
			$response = new ActionResponse('order', $order->toArray());
			$response->set('returnUrl', $returnUrl);
			return $response;
		}
	}

	/**
	 *	@role login
	 */
	public function completeExternal()
	{
		SessionOrder::destroy();
		$order = CustomerOrder::getInstanceById($this->request->get('id'), CustomerOrder::LOAD_DATA);
		if ($order->user->get() != $this->user)
		{
			throw new ApplicationException('Invalid order');
		}

		$this->session->set('completedOrderID', $order->getID());
		return new ActionRedirectResponse('checkout', 'completed');
	}

	/**
	 *	@role login
	 */
	public function completed()
	{
		$order = CustomerOrder::getInstanceByID((int)$this->session->get('completedOrderID'), CustomerOrder::LOAD_DATA);
		$order->loadAll();
		$response = new ActionResponse();
		$response->set('order', $order->toArray());
		$response->set('url', $this->router->createUrl(array('controller' => 'user', 'action' => 'viewOrder', 'id' => $this->session->get('completedOrderID')), true));
		return $response;
	}

	public function cvv()
	{
		$this->addBreadCrumb($this->translate('_cvv'), '');

		return new ActionResponse();
	}

	private function createAddress($addressClass, $prefix)
	{
		$address = UserAddress::getNewInstance();
		$this->saveAddress($address, $prefix);

		$addressType = call_user_func_array(array($addressClass, 'getNewInstance'), array($this->user, $address));
		$addressType->save();

		return $addressType;
	}

	private function getPaymentOrder()
	{
		if (!$this->paymentOrder)
		{
			if ($this->request->get('order'))
			{
				$f = new ARSelectFilter(new EqualsCond(new ARFieldHandle('CustomerOrder', 'ID'), $this->request->get('order')));
				$f->mergeCondition(new EqualsCond(new ARFieldHandle('CustomerOrder', 'userID'), $this->user->getID()));
				$f->mergeCondition(new EqualsCond(new ARFieldHandle('CustomerOrder', 'isFinalized'), true));
				$f->mergeCondition(new EqualsCond(new ARFieldHandle('CustomerOrder', 'isPaid'), false));
				$f->mergeCondition(new EqualsCond(new ARFieldHandle('CustomerOrder', 'isCancelled'), 0));

				$s = ActiveRecordModel::getRecordSet('CustomerOrder', $f);
				if ($s->size())
				{
					$order = $s->get(0);
					$order->loadAll();
					$this->paymentOrder = $this->order = $order;
				}
			}
			else
			{
				$this->paymentOrder = $this->order;
			}
		}

		return $this->paymentOrder;
	}

	private function registerPayment(TransactionResult $result, TransactionPayment $handler)
	{
		$transaction = Transaction::getNewInstance($this->order, $result);
		$transaction->setHandler($handler);
		$transaction->save();

		return $this->finalizeOrder();
	}

	private function finalizeOrder()
	{
		$newOrder = $this->order->finalize(Currency::getValidInstanceById($this->getRequestCurrency()));

		$orderArray = $this->order->toArray(array('payments' => true));

		// send order confirmation email
		if ($this->config->get('EMAIL_NEW_ORDER'))
		{
			$email = new Email($this->application);
			$email->setUser($this->user);
			$email->setTemplate('order.new');
			$email->set('order', $orderArray);
			$email->send();
		}

		// notify store admin
		if ($this->config->get('NOTIFY_NEW_ORDER'))
		{
			$email = new Email($this->application);
			$email->setTo($this->config->get('NOTIFICATION_EMAIL'), $this->config->get('STORE_NAME'));
			$email->setTemplate('notify.order');
			$email->set('order', $orderArray);
			$email->set('user', $this->user->toArray());
			$email->send();
		}

		$this->session->set('completedOrderID', $this->order->getID());

		SessionOrder::save($newOrder);

		return new ActionRedirectResponse('checkout', 'completed');
	}

	private function getTransaction()
	{
		$this->order->loadAll();
		return new LiveCartTransaction($this->order, Currency::getValidInstanceById($this->getRequestCurrency()));
	}

	/******************************* VALIDATION **********************************/

	/**
	 *	Determines if the necessary steps have been completed, so the order could be finalized
	 *
	 *	@return RedirectResponse
	 *	@return ActionRedirectResponse
	 *	@return false
	 */
	private function validateOrder(CustomerOrder $order, $step = 0)
	{
		if ($order->isFinalized->get())
		{
			return false;
		}

		// no items in shopping cart
		if (!count($order->getShoppingCartItems()))
		{
			if ($this->request->isValueSet('return'))
			{
				return new RedirectResponse($this->router->createUrlFromRoute($this->request->get('return')));
			}
			else
			{
				return new ActionRedirectResponse('index', 'index');
			}
		}

		// order is not orderable (too few/many items, etc.)
		$isOrderable = $order->isOrderable(true, true);

		if (!$isOrderable || $isOrderable instanceof OrderException)
		{
			return new ActionRedirectResponse('order', 'index');
		}

		// shipping address selected
		if ($step >= self::STEP_SHIPPING)
		{
			if ((!$order->shippingAddress->get() && $order->isShippingRequired() && !$order->isMultiAddress->get()) || !$order->billingAddress->get())
			{
				return new ActionRedirectResponse('checkout', 'selectAddress');
			}
		}

		// shipping method selected
		if ($step >= self::STEP_PAYMENT && $order->isShippingRequired())
		{
			foreach ($order->getShipments() as $shipment)
			{
				if (!$shipment->getSelectedRate() && $shipment->isShippable())
				{
					return new ActionRedirectResponse('checkout', 'shipping');
				}
			}
		}

		return false;
	}

	private function validateExpressCheckout()
	{
		if ($redirect = $this->validateOrder($this->order, self::STEP_PAYMENT))
		{
			return $redirect;
		}

		$expressInstance = ExpressCheckout::getInstanceByOrder($this->order);

		if (!$expressInstance)
		{
			return new ActionRedirectResponse('order', 'index');
		}

		try
		{
			$handler = $expressInstance->getTransactionDetails($this->getTransaction());
		}
		catch (PaymentException $e)
		{
			$expressInstance->delete();
			return new ActionRedirectResponse('checkout', 'express', array('id' => $expressInstance->method->get()));
		}

		return $expressInstance;
	}

	private function buildShippingForm(/*ARSet */$shipments)
	{
		ClassLoader::import("framework.request.validator.Form");
		return new Form($this->buildShippingValidator($shipments));
	}

	private function buildShippingValidator(/*ARSet */$shipments)
	{
		ClassLoader::import("framework.request.validator.RequestValidator");
		$validator = new RequestValidator("shipping", $this->request);
		foreach ($shipments as $key => $shipment)
		{
			if ($shipment->isShippable())
			{
				$validator->addCheck('shipping_' . $key, new IsNotEmptyCheck($this->translate('_err_select_shipping')));
			}
		}
		return $validator;
	}

	private function buildAddressSelectorForm(CustomerOrder $order, $step)
	{
		ClassLoader::import("framework.request.validator.Form");
		$validator = $this->buildAddressSelectorValidator($order, $step);

		$form = new Form($validator);
		$form->set('billing_country', $this->config->get('DEF_COUNTRY'));
		$form->set('shipping_country', $this->config->get('DEF_COUNTRY'));

		return $form;
	}

	private function buildAddressSelectorValidator(CustomerOrder $order, $step)
	{
		$this->loadLanguageFile('User');

		ClassLoader::import("framework.request.validator.Form");
		$validator = new RequestValidator("addressSelectorValidator", $this->request);

		if (!$step || ('billing' == $step))
		{
			$validator->addCheck('billingAddress', new OrCheck(array('billingAddress', 'billing_address1'), array(new IsNotEmptyCheck($this->translate('_select_billing_address')), new IsNotEmptyCheck('')), $this->request));
			$this->validateAddress($validator, 'billing_', new CheckoutBillingAddressCheckCondition($this->request));
		}

		if (!$step || ('shipping' == $step))
		{
			if ($order->isShippingRequired() && !$order->isMultiAddress->get())
			{
				$validator->addCheck('shippingAddress', new OrCheck(array('shippingAddress', 'sameAsBilling', 'shipping_address1'), array(new IsNotEmptyCheck($this->translate('_select_shipping_address')), new IsNotEmptyCheck(''), new IsNotEmptyCheck('')), $this->request));
				$this->validateAddress($validator, 'shipping_', new CheckoutShippingAddressCheckCondition($this->request));
			}
		}

		return $validator;
	}

	private function validateAddress(RequestValidator $validator, $prefix, CheckCondition $condition)
	{
		$validator->addCheck($prefix . 'firstName', new ConditionalCheck($condition, new IsNotEmptyCheck($this->translate('_err_enter_first_name'))));
		$validator->addCheck($prefix . 'lastName', new ConditionalCheck($condition, new IsNotEmptyCheck($this->translate('_err_enter_last_name'))));
		$validator->addCheck($prefix . 'address1', new ConditionalCheck($condition, new IsNotEmptyCheck($this->translate('_err_enter_address'))));
		$validator->addCheck($prefix . 'city', new ConditionalCheck($condition, new IsNotEmptyCheck($this->translate('_err_enter_city'))));
		$validator->addCheck($prefix . 'country', new ConditionalCheck($condition, new IsNotEmptyCheck($this->translate('_err_select_country'))));
		$validator->addCheck($prefix . 'zip', new ConditionalCheck($condition, new IsNotEmptyCheck($this->translate('_err_enter_zip'))));

		if (!$this->config->get('DISABLE_STATE'))
		{
			$stateCheck = new OrCheck(array($prefix . 'state_select', $prefix . 'state_text'), array(new IsNotEmptyCheck($this->translate('_err_select_state')), new IsNotEmptyCheck('')), $this->request);
			$validator->addCheck($prefix . 'state_select', new ConditionalCheck($condition, $stateCheck));
		}

		if ($this->config->get('REQUIRE_PHONE'))
		{
			$validator->addCheck($prefix . 'phone', new ConditionalCheck($condition, new IsNotEmptyCheck($this->translate('_err_enter_phone'))));
		}
	}

	public function buildCreditCardForm()
	{
		ClassLoader::import("framework.request.validator.Form");
		$form = new Form($this->buildCreditCardValidator());
		$form->set('ccExpiryMonth', date('n'));
		$form->set('ccExpiryYear', date('Y'));
		return $form;
	}

	private function buildCreditCardValidator()
	{
		ClassLoader::import("framework.request.validator.RequestValidator");
		$validator = new RequestValidator("creditCard", $this->request);
		$validator->addCheck('ccNum', new IsNotEmptyCheck($this->translate('_err_enter_cc_num')));
//		$validator->addCheck('ccType', new IsNotEmptyCheck($this->translate('_err_select_cc_type')));
		$validator->addCheck('ccExpiryMonth', new IsNotEmptyCheck($this->translate('_err_select_cc_expiry_month')));
		$validator->addCheck('ccExpiryYear', new IsNotEmptyCheck($this->translate('_err_select_cc_expiry_year')));

		if ($this->config->get('REQUIRE_CVV'))
		{
			$validator->addCheck('ccCVV', new IsNotEmptyCheck($this->translate('_err_enter_cc_cvv')));
		}

		$validator->addFilter('ccCVV', new RegexFilter('[^0-9]'));
		$validator->addFilter('ccNum', new RegexFilter('[^ 0-9]'));

		return $validator;
	}
}

ClassLoader::import('framework.request.validator.check.CheckCondition');

class CheckoutBillingAddressCheckCondition extends CheckCondition
{
	function isSatisfied()
	{
		return !$this->request->get('billingAddress');
	}
}

class CheckoutShippingAddressCheckCondition extends CheckCondition
{
	function isSatisfied()
	{
		return  !$this->request->get('shippingAddress') &&
				!$this->request->get('sameAsBilling');
	}
}

?>