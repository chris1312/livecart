<?php
if(!defined('TEST_SUITE')) require_once dirname(__FILE__) . '/../../Initialize.php';

ClassLoader::import('library.locale.Locale');

/**
 * @author Integry Systems
 * @package test.model.locale 
 */
class TestLocale extends UnitTestCase  
{
  	/*
	function testCreate()
  	{
		// attempt to create non-existing locale
		$locale = Locale::getInstance('enz');
		$this->assertFalse($locale);		
		
		// create existing locale
		$locale = Locale::getInstance('en');
		$this->assertTrue($locale instanceof Locale);		

		// set as current locale
		Locale::setCurrentLocale('en');
		$current = Locale::getCurrentLocale();
		$this->assertIdentical($current, $locale);
	}
*/
}
?>