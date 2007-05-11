<?php

/**
 * Currency model
 *
 * @package application.model
 * @author Rinalds Uzkalns <rinalds@integry.net>
 */
class Currency extends ActiveRecordModel
{
	public static function defineSchema($className = __CLASS__)
	{
		$schema = self::getSchemaInstance($className);
		$schema->setName("Currency");

		$schema->registerField(new ARPrimaryKeyField("ID", ArChar::instance(3)));

		$schema->registerField(new ARField("rate", ArFloat::instance(16)));
		$schema->registerField(new ARField("lastUpdated", ArDateTime::instance()));
		$schema->registerField(new ARField("isDefault", ArBool::instance()));
		$schema->registerField(new ARField("isEnabled", ArBool::instance()));
		$schema->registerField(new ARField("position", ARInteger::instance()));
		$schema->registerField(new ARField("pricePrefix", ARText::instance(20)));
		$schema->registerField(new ARField("priceSuffix", ARText::instance(20)));
	}

	public function setAsDefault($default = true)
	{
	  	$this->isDefault->set((bool)$default);
	}

	public function isDefault()
	{
	  	return $this->isDefault->get();
	}
	
	public function toArray()
	{
	  	$array = parent::toArray();
		$array['name'] = Store::getInstance()->getLocaleInstance()->info()->getCurrencyName($this->getId());	  	
		
		return $array;
	}
	
	public function getFormattedPrice($price)
	{
        $parts = explode('.', $price);                
        
        $dollars = $parts[0];
        
        if (!isset($parts[1]))
        {
            $parts[1] = 0;
        }
        
        if ($parts[1] > 0)
        {
            $cents = $parts[1];
            if (strlen($cents) == 1)
            {
                $cents = $cents . '0';
            }
            
            $price = $dollars . '.' . $cents;            
        }        
        else
        {
            $price = $dollars;
        }
        
        return $this->pricePrefix->get() . $price . $this->priceSuffix->get();
    }
	
	public function convertAmountFromDefaultCurrency($amount)
	{
        return $amount / $this->rate->get();   
    }
    
	public function convertAmountToDefaultCurrency($amount)
	{
        return $amount * $this->rate->get();        
    }

    public function convertAmount(Currency $currency, $amount)
	{
        $amount = $currency->convertAmountToDefaultCurrency($amount);
        return $this->convertAmountFromDefaultCurrency($amount);        
    }
	
	public static function getInstanceById($id, $loadData = false)
	{
		return ActiveRecordModel::getInstanceById(__CLASS__, $id, $loadData);
	}
	
	/**
	 *  Return Currency instance by ID and provide additional validation. If the currency doesn't exist
	 *  or is not valid, instance of the default currency is returned.
	 *
	 *  @return Currency
	 */
    public static function getValidInstanceById($id, $loadData = false)
	{
        try
        {
            $instance = ActiveRecordModel::getInstanceById(__CLASS__, $id, $loadData);    
        }
        catch (ARNotFoundException $e)
        {
            $instance = Store::getInstance()->getDefaultCurrency();
        }
        
        if (!$instance->isEnabled->get())
        {
            $instance = Store::getInstance()->getDefaultCurrency();    
        }
        
        return $instance;
	}

	public static function deleteById($id)
	{
		// make sure the currency record exists
		$inst = ActiveRecord::getInstanceById('Currency', $id, true);
		
		// make sure it's not the default currency
		if (true != $inst->isDefault->get())			
		{
			ActiveRecord::deleteByID('Currency', $id);
			return true;
		}
		else
		{
		  	return false;
		}
	}
	
	protected function insert()
	{
	  	// check if default currency exists
		$filter = new ARSelectFilter();
		$filter->setCondition(new EqualsCond(new ARFieldHandle('Currency', 'isDefault'), 1));
		
		$r = ActiveRecord::getRecordSet('Currency', $filter);
		$isDefault = ($r->getTotalRecordCount() == 0);

	  	// get max position
		$filter = new ARSelectFilter();
		$filter->setOrder(new ARFieldHandle('Currency', 'position'), 'DESC');
		$filter->setLimit(1);
		
		$r = ActiveRecord::getRecordSet('Currency', $filter);
		if ($r->getTotalRecordCount() > 0)
		{
			$max = $r->get(0);			
			$position = $max->position->get() + 1;		  		  
		}
		else
		{
		  	$position = 0;
		}
		
		if ($isDefault)
		{
		  	$this->isDefault->set(true);
		  	$this->isEnabled->set(true);
		}
		
		$this->position->set($position);
		
		parent::insert();
	}
}

?>