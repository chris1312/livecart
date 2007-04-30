<?php

ClassLoader::import("application.model.ActiveRecordModel");
ClassLoader::import("application.model.user.BillingAddress");
ClassLoader::import("application.model.user.ShippingAddress");

/**
 * Store user base class (including frontend and backend)
 *
 * @package application.model.user
 * @author Integry Systems
 *
 */
class User extends ActiveRecordModel
{
	/**
	 * ID of anonymous user that is not authorized
	 *
	 */
	const ANONYMOUS_USER_ID = 0;
	
	private $newPassword;

	public static function defineSchema($className = __CLASS__)
	{
		$schema = self::getSchemaInstance($className);
		$schema->setName("User");

		$schema->registerField(new ARPrimaryKeyField("ID", ARInteger::instance()));
		$schema->registerField(new ARForeignKeyField("defaultShippingAddressID", "defaultShippingAddress", "ID", 'ShippingAddress', ARInteger::instance()));
		$schema->registerField(new ARForeignKeyField("defaultBillingAddressID", "defaultBillingAddress", "ID", 'BillingAddress', ARInteger::instance()));

		$schema->registerField(new ARField("email", ARVarchar::instance(60)));
		$schema->registerField(new ARField("password", ARVarchar::instance(16)));
		$schema->registerField(new ARField("firstName", ARVarchar::instance(60)));
		$schema->registerField(new ARField("lastName", ARVarchar::instance(60)));
		$schema->registerField(new ARField("companyName", ARVarchar::instance(60)));
		$schema->registerField(new ARField("dateCreated", ARDateTime::instance()));
		$schema->registerField(new ARField("isEnabled", ARBool::instance()));		
	}

    public static function getNewInstance()
    {
        $instance = parent::getNewInstance(__CLASS__);    
        
        return $instance;
    }

    public static function getCurrentUser()
    {
        $id = Session::getInstance()->getValue('User');
    
        if (!$id)
        {
            $user = self::getNewInstance();
            $user->setID(self::ANONYMOUS_USER_ID);
        }
        else
        {
			$user = User::getInstanceById($id);
		}
        
        return $user;
    }
    
    public static function getInstanceById($id)
    {
		return parent::getInstanceById(__CLASS__, $id, self::LOAD_REFERENCES);
	}
    
    public function setAsCurrentUser()
    {
		Session::getInstance()->setValue('User', $this->getID());
	}

	public function setPassword($password)
	{
		$this->password->set(md5($password));
		$this->newPassword = $password;
	}

    public function save()
    {
        // auto-generate password if not set
        if (!$this->password->get())
        {
            $this->setPassword($this->getAutoGeneratedPassword());
        }
        
        return parent::save();
    }

    protected function insert()
    {
        parent::insert();
        
        // send welcome email with user account details
        $email = new Email();
        $email->setUser($this);
        $email->setTemplate('user.new');
        $email->send();
    }

	public function loadAddresses()
	{
		if ($this->defaultBillingAddress->get())
		{
			$this->defaultBillingAddress->get()->load(array('UserAddress'));					
		}

		if ($this->defaultShippingAddress->get())
		{
			$this->defaultShippingAddress->get()->load(array('UserAddress'));			
		}
	}
	
	/**
	 * Gets an instance of user by using login information
	 *
	 * @param string $email
	 * @param string $password
	 * @return mixed User instance or null if user is not found
	 */
	public static function getInstanceByLogin($email, $password)
	{
		$loginCond = new EqualsCond(new ARFieldHandle('User', 'email'), $email);
		$loginCond->addAND(new EqualsCond(new ARFieldHandle('User', 'password'), md5($password)));
		
		$recordSet = ActiveRecordModel::getRecordSet(__CLASS__, new ARSelectFilter($loginCond));

		if (!$recordSet->size())
		{
			return null;
		}
		else
		{
			return $recordSet->get(0);
		}
	}

	/**
	 * Checks if a user can access a particular controller/action identified by a role string (handle)
	 *
	 * Role string represents hierarchial role, that grants access to a given node:
	 * rootNode.someNode.lastNode
	 *
	 * (i.e. admin.store.catalog) this role string identifies that user has access to
	 * all actions/controller that are mapped to this string (admin.store.catalog.*)
	 *
	 * @param string $roleName
	 * @return bool
	 */
	public function hasAccess($roleName)
	{
		if ('login' == $roleName)
		{
			return $this->getID() > 0;	
		}

		return true;
		
		// disable all login protected content from deactivated users
		if ($roleName && !$this->isEnabled->get())
		{
			return false;	
		}
		
		return true;
		
		// pseudo check
		if ($this->getID() > 0)
		{
			return true;
		}
		else
		{
			return false;
		}
	}

    public function isLoggedIn()
    {
        return ($this->getID() != self::ANONYMOUS_USER_ID);
    }

    public function getName()
    {
        return $this->firstName->get() . ' ' . $this->lastName->get();
    }

	public function toArray()
	{
		$array = parent::toArray();
		$array['newPassword'] = $this->newPassword;
		return $array;
	}

    public function getBillingAddressArray($defaultFirst = true)
    {
        $f = new ARSelectFilter();
        $f->setCondition(new EqualsCond(new ARFieldHandle('BillingAddress', 'userID'), $this->getID()));
        if (!$defaultFirst)
        {
            $f->setOrder(new ARExpressionHandle('ID = ' . $this->defaultBillingAddress->get()->getID()));
        }
        
        return ActiveRecordModel::getRecordSetArray('BillingAddress', $f, array('UserAddress', 'State'));
    }

    public function getShippingAddressArray($defaultFirst = true)
    {
        $f = new ARSelectFilter();
        $f->setCondition(new EqualsCond(new ARFieldHandle('ShippingAddress', 'userID'), $this->getID()));
        if (!$defaultFirst)
        {
            $f->setOrder(new ARExpressionHandle('ID = ' . $this->defaultShippingAddress->get()->getID()));
        }
        
        return ActiveRecordModel::getRecordSetArray('ShippingAddress', $f, array('UserAddress', 'State'));
    }
    
    public static function transformArray($array, $class = __CLASS__)
    {
        $array = parent::transformArray($array, $class);
        $array['fullName'] = $array['firstName'] . ' ' . $array['lastName'];
        
        return $array;
    }
    
	public static function getRecordSet(ARSelectFilter $filter, $loadReferencedRecords)
	{
		return ActiveRecord::getRecordSet(__CLASS__, $filter, $loadReferencedRecords);
	}

    private function getAutoGeneratedPassword($length = 8)
    {        
        $chars = array();
        for ($k = 1; $k <= $length; $k++)
        {
            $chars[] = chr(rand(97, 122));
        }        
        
        return implode('', $chars);
    }
}

?>