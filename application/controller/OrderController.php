<?php

ClassLoader::import('application.model.order.CustomerOrder');
ClassLoader::import('application.model.discount.DiscountCondition');
ClassLoader::import('application.model.Currency');
ClassLoader::import('application.model.product.Product');
ClassLoader::import('application.model.product.ProductOption');

/**
 * @author Integry Systems
 * @package application.controller
 */
class OrderController extends FrontendController
{
	/**
	 * @var CustomerOrder
	 */
	protected $order;

	/**
	 *  View shopping cart contents
	 */
	public function index()
	{
		$this->addBreadCrumb($this->translate('_my_session'), $this->router->createUrlFromRoute($this->request->get('return'), true));
		$this->addBreadCrumb($this->translate('_my_basket'), '');

		$this->order->loadItemData();

		$options = $this->getItemOptions();

		$currency = Currency::getValidInstanceByID($this->request->get('currency', $this->application->getDefaultCurrencyCode()), Currency::LOAD_DATA);

		$form = $this->buildCartForm($this->order, $options);

		$response = new ActionResponse();
		$response->set('cart', $this->order->toArray());
		$response->set('form', $form);
		$response->set('return', $this->request->get('return'));
		$response->set('currency', $currency->getID());
		$response->set('options', $options['visible']);
		$response->set('moreOptions', $options['more']);
		$response->set('orderTotal', $currency->getFormattedPrice($this->order->getSubTotal($currency)));
		$response->set('expressMethods', $this->application->getExpressPaymentHandlerList(true));
		$response->set('isCouponCodes', DiscountCondition::isCouponCodes());

		$this->order->getSpecification()->setFormResponse($response, $form);

		return $response;
	}

	private function getItemOptions()
	{
		// load product options
		$products = array();
		foreach ($this->order->getOrderedItems() as $item)
		{
			$products[$item->product->get()->getID()] = $item->product->get();
		}

		$options = ProductOption::loadOptionsForProductSet(ARSet::buildFromArray($products));

		$moreOptions = $optionsArray = array();
		foreach ($this->order->getOrderedItems() as $item)
		{
			$productID = $item->product->get()->getID();
			if (isset($options[$productID]))
			{
				$optionsArray[$item->getID()] = $this->getOptionsArray($options[$productID], $item, 'isDisplayedInCart');
				$moreOptions[$item->getID()] = $this->getOptionsArray($options[$productID], $item, 'isDisplayed');
			}
		}

		// are there any options that are available for customer to set, but not displayed right away?
		foreach ($moreOptions as &$options)
		{
			foreach ($options as $key => $option)
			{
				if ($option['isDisplayedInCart'])
				{
					unset($options[$key]);
				}
			}
		}

		return array('visible' => $optionsArray, 'more' => $moreOptions);
	}

	public function options()
	{
		$response = $this->index();
		$response->set('editOption', $this->request->get('id'));
		return $response;
	}

	public function optionForm(CustomerOrder $order = null, $filter = 'isDisplayed')
	{
		$order = $order ? $order : $this->order;

		$item = $order->getItemByID($this->request->get('id'));
		$options = $optionsArray = array();
		$product = $item->product->get();
		$options[$product->getID()] = $product->getOptions(true);
		$optionsArray[$item->getID()] = $this->getOptionsArray($options[$product->getID()], $item, $filter);

		$this->setLayout('empty');

		$response = new ActionResponse();
		$response->set('form', $this->buildOptionsForm($item, $options));
		$response->set('options', $optionsArray);
		$response->set('item', $item->toArray());
		return $response;
	}

	private function getOptionsArray($set, $item, $filter = 'isDisplayed')
	{
		$out = array();
		foreach ($set as $option)
		{
			$arr = $option->toArray();
			$arr['fieldName'] = $this->getFormFieldName($item, $option);

			$invalid = !empty($_SESSION['optionError'][$item->getID()][$option->getID()]) && ('isDisplayedInCart' == $filter);
//$invalid = false;
			if (!$filter || $option->$filter->get() || $invalid)
			{
				$out[] = $arr;
			}
		}

		return $out;
	}

	/**
	 *  Update product quantities
	 */
	public function update()
	{
		// coupon code
		if ($this->request->get('coupon'))
		{
			$code = $this->request->get('coupon');

			if ($condition = DiscountCondition::getInstanceByCoupon($code))
			{
				$exists = false;
				foreach ($this->order->getCoupons() as $coupon)
				{
					if ($coupon->couponCode->get() == $code)
					{
						$exists = true;
					}
				}

				if (!$exists)
				{
					OrderCoupon::getNewInstance($this->order, $code)->save();
				}
			}

			$this->order->getCoupons(true);
		}

		$this->order->loadItemData();
		$validator = $this->buildCartValidator($this->order, $this->getItemOptions());

		if (!$validator->isValid())
		{
			return new ActionRedirectResponse('order', 'index');
		}

		foreach ($this->order->getOrderedItems() as $item)
		{
			$this->order->loadRequestData($this->request);

			if ($this->request->isValueSet('item_' . $item->getID()))
			{
				foreach ($item->product->get()->getOptions(true) as $option)
				{
					$this->modifyItemOption($item, $option, $this->request, $this->getFormFieldName($item, $option));
				}

				$item->save();

				$this->order->updateCount($item, $this->request->get('item_' . $item->getID(), 0));
			}
		}

		$this->order->mergeItems();

		SessionOrder::save($this->order);

		// proceed with the checkout
		if ($this->request->get('proceed'))
		{
			return new ActionRedirectResponse('checkout', 'index');
		}

		// redirect to payment gateway
		if ($url = $this->request->get('redirect'))
		{
			return new RedirectResponse($url);
		}

		return new ActionRedirectResponse('order', 'index', array('query' => 'return=' . $this->request->get('return')));
	}

	/**
	 *  Remove a product from shopping cart
	 */
	public function delete()
	{
		$this->order->removeItem(ActiveRecordModel::getInstanceByID('OrderedItem', $this->request->get('id')));
		SessionOrder::save($this->order);

		return new ActionRedirectResponse('order', 'index', array('query' => 'return=' . $this->request->get('return')));
	}

	/**
	 *  Add a new product to shopping cart
	 */
	public function addToCart()
	{
		$product = Product::getInstanceByID($this->request->get('id'));

		$productRedirect = new ActionRedirectResponse('product', 'index', array('id' => $product->getID(), 'query' => 'return=' . $this->request->get('return')));
		if (!$product->isAvailable())
		{
			$productController = new ProductController($this->application);
			$productController->setErrorMessage($this->translate('_product_unavailable'));
			return $productRedirect;
		}

		ClassLoader::import('application.controller.ProductController');
		if (!ProductController::buildAddToCartValidator($product->getOptions(true)->toArray())->isValid())
		{
			return $productRedirect;
		}

		ActiveRecordModel::beginTransaction();

		$item = $this->order->addProduct($product, $this->request->get('count', 1));

		if ($item instanceof OrderedItem)
		{
			foreach ($product->getOptions(true) as $option)
			{
				$this->modifyItemOption($item, $option, $this->request, 'option_' . $option->getID());
			}
		}

		$this->order->mergeItems();
		SessionOrder::save($this->order);

		ActiveRecordModel::commit();

		//$this->setMessage($this->makeText('_added_to_cart', array($product->getValueByLang('name', $this->getRequestLanguage()))));

		return new ActionRedirectResponse('order', 'index', array('query' => 'return=' . $this->request->get('return')));
	}

	public function moveToCart()
	{
		$item = $this->order->getItemByID($this->request->get('id'));
		$item->isSavedForLater->set(false);
		$this->order->mergeItems();
		$this->order->resetShipments();
		SessionOrder::save($this->order);

		return new ActionRedirectResponse('order', 'index', array('query' => 'return=' . $this->request->get('return')));
	}

	public function moveToWishList()
	{
		$item = $this->order->getItemByID($this->request->get('id'));
		$item->isSavedForLater->set(true);
		$this->order->mergeItems();
		$this->order->resetShipments();
		SessionOrder::save($this->order);

		return new ActionRedirectResponse('order', 'index', array('query' => 'return=' . $this->request->get('return')));
	}

	/**
	 *  Add a new product to wish list (save items for buying later)
	 */
	public function addToWishList()
	{
		$product = Product::getInstanceByID($this->request->get('id'), Product::LOAD_DATA);

		$this->order->addToWishList($product);
		$this->order->mergeItems();
		SessionOrder::save($this->order);

		return new ActionRedirectResponse('order', 'index', array('query' => 'return=' . $this->request->get('return')));
	}

	public function modifyItemOption(OrderedItem $item, ProductOption $option, Request $request, $varName)
	{
		if ($option->isBool() && $request->isValueSet('checkbox_' . $varName))
		{
			if ($request->get($varName))
			{
				$item->addOptionChoice($option->defaultChoice->get());
			}
			else
			{
				$item->removeOption($option);
			}
		}
		else if ($request->get($varName))
		{
			if ($option->isSelect())
			{
				$item->addOptionChoice($option->getChoiceByID($request->get($varName)));
			}
			else if ($option->isText())
			{
				$text = $request->get($varName);

				if ($text)
				{
					$choice = $item->addOptionChoice($option->defaultChoice->get());
					$choice->optionText->set($text);
				}
				else
				{
					$item->removeOption($option);
				}
			}
		}
	}

	/**
	 *	@todo Optimize loading of product options
	 */
	private function buildCartForm(CustomerOrder $order, $options)
	{
		ClassLoader::import("framework.request.validator.Form");

		$form = new Form($this->buildCartValidator($order, $options));

		foreach ($order->getOrderedItems() as $item)
		{
			$this->setFormItem($item, $form);
		}

		return $form;
	}

	private function buildOptionsForm(OrderedItem $item, $options)
	{
		ClassLoader::import("framework.request.validator.Form");

		$form = new Form($this->buildOptionsValidator($item, $options));
		$this->setFormItem($item, $form);

		return $form;
	}

	private function setFormItem(OrderedItem $item, Form $form)
	{
		$name = 'item_' . $item->getID();
		$form->set($name, $item->count->get());

		foreach ($item->getOptions() as $option)
		{
			$productOption = $option->choice->get()->option->get();

			if ($productOption->isBool())
			{
				$value = true;
			}
			else if ($productOption->isText())
			{
				$value = $option->optionText->get();
			}
			else if ($productOption->isSelect())
			{
				$value = $option->choice->get()->getID();
			}

			$form->set($this->getFormFieldName($item, $productOption), $value);
		}
	}

	public function getFormFieldName(OrderedItem $item, $option)
	{
		$optionID = $option instanceof ProductOption ? $option->getID() : $option['ID'];
		return 'itemOption_' . $item->getID() . '_' . $optionID;
	}

	/**
	 * @return RequestValidator
	 */
	private function buildCartValidator(CustomerOrder $order, $options)
	{
		unset($_SESSION['optionError']);

		ClassLoader::import("framework.request.validator.RequestValidator");

		$validator = new RequestValidator("cartValidator", $this->request);

		foreach ($order->getOrderedItems() as $item)
		{
			$this->buildItemValidation($validator, $item, $options);
		}

		$order->getSpecification()->setValidation($validator, true);

		return $validator;
	}

	private function buildOptionsValidator(OrderedItem $item, $options)
	{
		ClassLoader::import("framework.request.validator.RequestValidator");

		$validator = new RequestValidator("optionValidator", $this->request);
		$this->buildItemValidation($validator, $item, $options);

		return $validator;
	}

	private function buildItemValidation(RequestValidator $validator, $item, $options)
	{
		$name = 'item_' . $item->getID();
		$validator->addCheck($name, new IsNumericCheck($this->translate('_err_not_numeric')));
		$validator->addFilter($name, new NumericFilter());

		$productID = $item->product->get()->getID();

		if (isset($options['visible'][$productID]))
		{
			foreach ($options['visible'][$productID] as $option)
			{
				if ($option['isRequired'])
				{
					$validator->addCheck($this->getFormFieldName($item, $option), new IsNotEmptyCheck($this->translate('_err_option_' . $option['type'])));
				}
			}
		}

		if (isset($options['more'][$productID]))
		{
			foreach ($options['more'][$productID] as $option)
			{
				if ($option['isRequired'])
				{
					$field = $this->getFormFieldName($item, $option);
					if ($this->request->isValueSet($field) || $this->request->isValueSet('checkbox_' . $field))
					{
						$validator->addCheck($field, new IsNotEmptyCheck($this->translate('_err_option_' . $option['type'])));
						if (!$this->request->get($field))
						{
							$_SESSION['optionError'][$item->getID()][$option['ID']] = true;
						}
					}
				}
			}
		}
	}
}

?>