<?php

ClassLoader::import("application.model.product.Product");
ClassLoader::import("application.model.order.OrderedItem");

/**
 * Represents a collection of ordered items that are shipped in the same package
 *
 * @package application.model.order
 */
class Shipment extends ActiveRecordModel
{
    protected $items = array();
    
    /**
     *  Used only for serialization
     */
    protected $itemIds = array();
    
	protected $availableShippingRates;   
	
	protected $selectedRateId; 
    
    const STATUS_NEW = 0;
    const STATUS_PENDING = 1;
    const STATUS_AWAITING = 2;
    const STATUS_SHIPPED = 3;
    
    /**
	 * Define database schema used by this active record instance
	 *
	 * @param string $className Schema name
	 */
	public static function defineSchema($className = __CLASS__)
	{
		$schema = self::getSchemaInstance($className);
		$schema->setName($className);
		
		$schema->registerField(new ARPrimaryKeyField("ID", ARInteger::instance()));
		$schema->registerField(new ARForeignKeyField("orderID", "CustomerOrder", "ID", "CustomerOrder", ARInteger::instance()));
		$schema->registerField(new ARForeignKeyField("amountCurrencyID", "Currency", "ID", "Currency", ARInteger::instance()));
		$schema->registerField(new ARForeignKeyField("shippingServiceID", "ShippingService", "ID", "ShippingService", ARInteger::instance()));
		

		$schema->registerField(new ARField("trackingCode", ARVarchar::instance(100)));
		$schema->registerField(new ARField("shippingServiceCode", ARVarchar::instance(50)));
		$schema->registerField(new ARField("dateShipped", ARDateTime::instance()));
		$schema->registerField(new ARField("amount", ARFloat::instance()));
		$schema->registerField(new ARField("shippingAmount", ARFloat::instance()));
		$schema->registerField(new ARField("status", ARInteger::instance(2)));
	}       
	
	public function load($loadReferencedRecords = false)
	{
	    parent::load($loadReferencedRecords);
	}
	
	public function loadItems()
	{
	    if(empty($this->items))
	    {
		    $filter = new ARSelectFilter();
			$filter->setCondition(new EqualsCond(new ARFieldHandle('OrderedItem', 'shipmentID'), $this->getID()));
		
			foreach(OrderedITem::getRecordSet('OrderedItem', $filter) as $item)
			{
			    $this->items[] = $item;
			}
	    }
	}
	
	public static function getNewInstance(CustomerOrder $order)
	{
        $instance = parent::getNewInstance(__class__);
        $instance->order->set($order);
        return $instance;
    }
	
	public function addItem(OrderedItem $item)
	{
	    foreach($this->items as $key => $shipmentItem)
        {
            if($shipmentItem === $item)
            {
				return;
            }
        }
        $this->items[] = $item;
        $item->shipment->set($this);
    }
	
	public function removeItem(OrderedItem $item)
	{
        foreach($this->items as $key => $shipmentItem)
        {
            if($shipmentItem === $item)
            {
				unset($this->items[$key]);
				$item->shipment->setNull( );
				break;
            }
        }
        
    }
    
    public function getChargeableWeight(DeliveryZone $zone)
    {
        $weight = 0;
        
        foreach ($this->items as $item)
        {
            if (!$item->product->get()->isFreeShipping->get() || !$zone->isFreeShipping->get())
            {
                $weight += $item->product->get()->shippingWeight->get();
            }
        }   
        
        return $weight;
    }
    
    public function getChargeableItemCount(DeliveryZone $zone)
    {
        $count = 0;
        
        foreach ($this->items as $item)
        {
            if (!$item->product->get()->isFreeShipping->get() || !$zone->isFreeShipping->get())
            {
                $count += $item->count->get();
            }
        }   
        
        return $count;
    }
    
    public function setAvailableRates(ShippingRateSet $rates)
    {
        $this->availableShippingRates = $rates;
    }
    
    public function getAvailableRates()
    {
		return $this->availableShippingRates;
	}
	
	public function setRateId($serviceId)
	{
        $this->selectedRateId = $serviceId;
    }
    
    public function getSelectedRate()
    {
        if (!$this->availableShippingRates)
        {
            return null;
        }
        
        return $this->availableShippingRates->getByServiceId($this->selectedRateId);
    }
    
    public function toArray()
    {
        $array = parent::toArray();
        
        // ordered items
        $items = array();       
        foreach ($this->items as $item)
        {            
            $items[] = $item->toArray();
        }        
        $array['items'] = $items;      
        
        // subtotal
        $currencies = Store::getInstance()->getCurrencySet();
        $subTotal = array();
        foreach ($currencies as $id => $currency)
        {
            $subTotal[$id] = $this->getSubTotal($currency);
        }
        $array['subTotal'] = $subTotal;
               
        // formatted subtotal
        $formattedSubTotal = array();
        foreach ($subTotal as $id => $price)
        {
            $formattedSubTotal[$id] = Currency::getInstanceById($id)->getFormattedPrice($price);
        }        
        $array['formattedSubTotal'] = $formattedSubTotal;
        
        // selected shipping rate
        if ($selected = $this->getSelectedRate())
        {
            $array['selectedRate'] = $selected->toArray();            
        }
                
        return $array;
    }
    
    public function getSubTotal(Currency $currency)
    {
        $subTotal = 0;
        foreach ($this->items as $item)
        {            
            $subTotal += $item->getSubTotal($currency);
        }            
        
        return $subTotal;    
    }
    
	public function serialize()
	{
        $this->itemIds = array();
        foreach ($this->items as $item)
        {
            $this->itemIds[] = $item->getID();
        }
        
        return parent::serialize(array('orderID'), array('itemIds', 'availableShippingRates', 'selectedRateId'));
    }      
    
    public function unserialize($serialized)
    {
        parent::unserialize($serialized);
        if ($this->itemIds)
        {
            $this->items = array();
            foreach ($this->itemIds as $id)    
            {
                $item = ActiveRecordModel::getInstanceById('OrderedItem', $id);
                $this->items[] = $item;
            }
            
            $this->itemIds = array();
        }
    }
    
    protected function insert()
    {      
        $rate = $this->getSelectedRate();
        $this->shippingServiceCode->set($rate->getServiceName());
        $this->shippingService->set(ShippingService::getInstanceByID($rate->getServiceID()));

        $this->recalculateAmounts();
        
        $this->status->set(self::STATUS_NEW);

        $ret = parent::insert();
        
        foreach ($this->items as $item)
        {
            $item->shipment->set($this);
            $item->save();
        }
        
        return $ret;
    }
    
    public function getItems()
    {
	    $this->loadItems();
        return $this->items;
    }
    
    public function recalculateAmounts()
    {
       $currency = $this->order->get()->currency->get();
       $this->amountCurrency->set($currency);
       $rate = $this->getSelectedRate();
       $this->amount->set($this->getSubTotal($currency));
       $this->shippingAmount->set($rate->getAmountByCurrency($currency));
    }
}

?>