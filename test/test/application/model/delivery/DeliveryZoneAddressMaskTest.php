<?php
if(!defined('TEST_SUITE')) require_once dirname(__FILE__) . '/../../Initialize.php';

ClassLoader::import("application.model.delivery.DeliveryZone");
ClassLoader::import("application.model.delivery.DeliveryZoneAddressMask");

/**
 *
 * @package test.model.delivery
 * @author Integry Systems
 */
class DeliveryZoneAddressMaskTest extends LiveCartTest
{
	/**
	 * @var DeliveryZone
	 */
	private $zone;

	public function __construct()
	{
		parent::__construct('delivery zone city masks tests');
	}

	public function getUsedSchemas()
	{
		return array(
			'DeliveryZone',
			'DeliveryZoneAddressMask'
		);
	}

	public function setUp()
	{
		parent::setUp();

		$this->zone = DeliveryZone::getNewInstance();
		$this->zone->name->set(':TEST_ZONE');
		$this->zone->isEnabled->set(1);
		$this->zone->isFreeShipping->set(1);
		$this->zone->save();
	}

	public function testCreateNewDeliveryZoneAddressMask()
	{
		$addressMask = DeliveryZoneAddressMask::getNewInstance($this->zone, 'Viln%');
		$addressMask->save();

		$addressMask->reload();

		$this->assertEquals($addressMask->deliveryZone->get(), $this->zone);
		$this->assertEquals($addressMask->mask->get(), 'Viln%');
	}
}
?>