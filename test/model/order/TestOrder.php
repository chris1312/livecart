<?php

if(!defined('TEST_SUITE')) require_once dirname(__FILE__) . '/../../Initialize.php';

ClassLoader::import("application.model.category.*");
ClassLoader::import("application.model.product.*");
ClassLoader::import("application.model.order.*");
ClassLoader::import("application.model.user.*");
ClassLoader::import("application.model.Currency");
ClassLoader::import("library.payment.*");

/**
 *	Test Order model 
 *
 *  @author Integry Systems
 *  @package test.model.order 
 */ 
class TestOrder extends UnitTestCase
{  
	private $order;
	
	private $products = array();
	
	private $usd;
	
	private $user;
	
	function __construct()
	{
		parent::__construct('Test order logic');
		ActiveRecordModel::beginTransaction();			
				
		// set up currency
		try
		{
			$this->usd = Currency::getInstanceByID('USD', Currency::LOAD_DATA);
		}
		catch (ARNotFoundException $e)
		{
			$this->usd = Currency::getNewInstance('USD');
			$this->usd->setAsDefault();
			$this->usd->save();
		}		

		// initialize order
		$user = User::getNewInstance('test@test.com');
		$user->save();
		$this->user = $user;
		
		$address = UserAddress::getNewInstance();
		$address->countryID->set('US');
		$state = State::getInstanceById(1, State::LOAD_DATA);
		$address->state->set(State::getInstanceById(1));
		$address->postalCode->set(90210);
		$address->save();
		$billing = BillingAddress::getNewInstance($user, $address);
		$billing->save();
		
		$address = clone $address;
		$shipping = ShippingAddress::getNewInstance($user, $address);
		$shipping->save();
		
		$user->defaultBillingAddress->set($billing);
		$user->defaultShippingAddress->set($shipping);
		$user->save();
								
		$this->order = CustomerOrder::getNewInstance($user);
		$this->order->shippingAddress->set($shipping->userAddress->get());
		$this->order->billingAddress->set($billing->userAddress->get());

		// set up products
		$product = Product::getNewInstance(Category::getInstanceById(Category::ROOT_ID), 'test1');   
		$product->save();
		$product->setPrice('USD', 100);
		$product->stockCount->set(20);
		$product->save();
		$product->isEnabled->set(true);
		$this->products[] = $product;

		$product = Product::getNewInstance(Category::getInstanceById(Category::ROOT_ID), 'test2');   
		$product->save();
		$product->setPrice('USD', 200);
		$product->stockCount->set(20);
		$product->isEnabled->set(true);
		$product->save();
		$this->products[] = $product;
		
		$product = Product::getNewInstance(Category::getInstanceById(Category::ROOT_ID), 'test3');   
		$product->save();
		$product->setPrice('USD', 400);
		$product->isSeparateShipment->set(true);
		$product->stockCount->set(20);
		$product->isEnabled->set(true);
		$product->save();
		$this->products[] = $product;
	}
	
	function tearDown()
	{
		$this->order->save(true);
	}
	
	function testAddingToAndRemovingFromCart()
	{		
		$this->order->addProduct($this->products[0], 1);   
		$this->order->addProduct($this->products[0], 0);
		$this->assertEqual($this->order->getSubTotal($this->usd), 0);
		
		$this->order->addProduct($this->products[0], 1);   
		$this->order->addProduct($this->products[0], -1);
		$this->assertEqual($this->order->getSubTotal($this->usd), 0);
	}	

	function testSubTotal()
	{		
		$subtotal = 0;
		foreach ($this->products as $product)
		{
			$this->order->addProduct($product, 1); 
			$subtotal += $product->getPrice('USD');
		}
		$this->assertEqual($this->order->getSubTotal($this->usd), $subtotal);
	}	

	function testShipments()
	{
		$this->assertEqual($this->order->getShipments()->size(), 2);
	}
	
/*
	function XtestSerialization()
	{
		$rates = new ShippingRateSet();
		
		$rate = new ShipmentDeliveryRate();
		$rate->setServiceID(12);
		$rate->setCost(33, 'USD');
		$rates->add($rate);
		
		$rate = new ShipmentDeliveryRate();
		$rate->setServiceID(14);
		$rate->setCost(53, 'USD');
		$rates->add($rate);
		
		$shipments = $this->order->getShipments();
		
		foreach ($shipments as $shipment)
		{
			$shipment->setAvailableRates($rates);
		}
		
		$subTotal = $this->order->getSubTotal($this->usd);

		// make sure none of the old objects are used after unserialization
		ActiveRecord::clearPool();

		$this->order = unserialize(serialize($this->order));
		
		$this->assertEqual($subTotal, $this->order->getSubTotal($this->usd));		
		
		$this->assertEqual($shipments->size(), $this->order->getShipments()->size());
		$this->assertEqual(count($shipments->get(0)->getItems()), count($this->order->getShipments()->get(0)->getItems()));
	}
*/	

	function testFinalize()
	{		
		$total = $this->order->getTotal($this->usd);
		$this->order->finalize($this->usd);
		
		// the sum of all shipments amounts should be equal to the order amount
		$sum = 0;
		foreach ($this->order->getShipments() as $shipment)
		{
			$sum += $shipment->amount->get();
		}
		
		$this->assertEqual($sum, $this->order->totalAmount->get());
		
		ActiveRecord::clearPool();		
		
		// reload the whole order data - the calculated total should still match
		$order = CustomerOrder::getInstanceById($this->order->getID(), true);		
		$order->loadAll();
		$this->assertEqual($total, $order->getTotal($this->usd));

		// change price for one product...
		foreach ($order->getShoppingCartItems() as $item)
		{
			$product = $item->product->get();
			$product->setPrice('USD', $product->getPrice('USD') + 10);			
//			$order->removeProduct($product);
//			var_dump(count($order->getShoppingCartItems()));
			$order->save();
//			var_dump(count($order->getShoppingCartItems()));
//			$order->save();
//			var_dump(count($order->getShoppingCartItems()));
//			var_dump($order->totalAmount->get() . '!');
//			var_dump('test');
//			$order->addProduct($product, 1);
			$order->save();
//			var_dump('test');			
//			var_dump(count($order->getShoppingCartItems()));
//			var_dump($order->getShoppingCartItems());
			break;
		}

//		var_dump($order->getTotal($this->usd));

		// ...so the new total calculated total would be different
		// $this->assertNotEqual($total, $order->getTotal($this->usd));

		// however the "closed" price should still be the same as this order is already finalized
		$this->assertEqual($total, $order->totalAmount->get());			   
	}

	function testPayment()
	{
		$result = new TransactionResult();
		$result->amount->set($this->order->totalAmount->get());
		$result->currency->set($this->order->currency->get()->getID());
		$result->gatewayTransactionID->set('TESTTRANSACTION');
		$result->setTransactionType(TransactionResult::TYPE_SALE);
		
		$transaction = Transaction::getNewInstance($this->order, $result);
		$transaction->save();
		
		$this->assertEqual($this->order->totalAmount->get(), $this->order->capturedAmount->get());
	}

	function testMerge()
	{
		$order =  CustomerOrder::getNewInstance($this->user);
		$second = CustomerOrder::getNewInstance($this->user);
		
		$order->addProduct($this->products[0], 1);
		$second->addProduct($this->products[1], 1);
		
		$order->merge($second);
		
		$this->assertEqual(count($order->getOrderedItems()), 2);
		
		$order->save();
		$second->save();
		
		// empty orders (without items) should not be saved
		$this->assertNull($second->getID());
	
		ActiveRecord::clearPool();
		
		$order = CustomerOrder::getInstanceById($order->getID());
		$order->loadAll();
		$this->assertEqual(count($order->getOrderedItems()), 2);		
	}

	function testUpdateCounts()
	{
		$product = $this->products[0];
		$order = CustomerOrder::getNewInstance($this->user);	
		
		// allow fractional units
		$product->isFractionalUnit->set(true);
		$order->addProduct($product, 1.5);		
		$items = $order->getItemsByProduct($product);
		$this->assertEqual($items[0]->count->get(), 1.5);

		// disable fractional units
		$product->isFractionalUnit->set(false);
		$order->updateCount($items[0], 1.2);		
		$this->assertEqual($items[0]->count->get(), 1);		
		
		$order->removeProduct($product);
		$order->addProduct($product, 3.3);				
		$items = $order->getItemsByProduct($product);
		$this->assertEqual($items[0]->count->get(), 3);
	}

	function testDigitalItems()
	{
		$order = CustomerOrder::getNewInstance($this->user);		
		
		$price = 400;
		
		$product = Product::getNewInstance(Category::getInstanceById(Category::ROOT_ID), 'test3');   
		$product->save();
		$product->setPrice('USD', $price);
		$product->type->set(Product::TYPE_DOWNLOADABLE);
		$product->isEnabled->set(true);
		$product->save();		
		
		$order->addProduct($product, 1);
		$order->save();
		
		$this->assertTrue($order->getSubTotal($this->usd), $price);

		$order->finalize($this->usd);
		$this->assertTrue($order->getSubTotal($this->usd), $price);
				
		ActiveRecord::clearPool();
		
		$loadedOrder = CustomerOrder::getInstanceById($order->getID());
		$loadedOrder->loadAll();
		$this->assertTrue($loadedOrder->getSubTotal($this->usd), $price);		
	}

	function testDigitalItemsAddedThroughShipment()
	{
		$order = CustomerOrder::getNewInstance($this->user);		
		
		$price = 400;
		
		$product = Product::getNewInstance(Category::getInstanceById(Category::ROOT_ID), 'test3');   
		$product->save();
		$product->setPrice('USD', $price);
		$product->type->set(Product::TYPE_DOWNLOADABLE);
		$product->isEnabled->set(true);
		$product->save();		
		
		$order->addProduct($product, 1);
		
		$item = array_shift($order->getItemsByProduct($product));

		$shipment = Shipment::getNewInstance($order);
		$shipment->addItem($item);
		
		$order->save();
		$shipment->recalculateAmounts();
		$shipment->save();
		$order->save();		
		
		$this->assertTrue($order->getSubTotal($this->usd), $price);

		ActiveRecord::clearPool();
		
		$loadedOrder = CustomerOrder::getInstanceById($order->getID());
		$loadedOrder->loadAll();
		$this->assertTrue($loadedOrder->getSubTotal($this->usd), $price);		

	}

	function test_SuiteTearDown()
	{
		ActiveRecordModel::rollback();
	}   
}

?>
