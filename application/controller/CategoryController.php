<?php

ClassLoader::import("application.controller.FrontendController");

/**
 * Index controller for frontend
 *
 * @package application.controller
 */
class CategoryController extends FrontendController 
{
	public function index() 
	{
		$this->categoryID = $this->request->getValue('id');
		
		if ($this->request->getValue('filters'))
		{
		  	ClassLoader::import('application.model.category.Filter');
			$filterIds = array();
			$filters = explode(',', $this->request->getValue('filters'));
		  	foreach ($filters as $filter)
			{
			  	$pair = explode('-', $filter);
			  	if (count($pair) != 2)
			  	{
				    continue;
				}
				$filterIds[] = $pair[1];
			}
			
			// get all filters
			$f = new ARSelectFilter();
			$c = new INCond(new ARFieldHandle('Filter', 'ID'), $filterIds);
			$f->setCondition($c);
			$this->filters = ActiveRecord::getRecordSet('Filter', $f);
		}
		
		$response = new ActionResponse();
		$response->setValue('id', $this->categoryID);
		return $response;
	}	
}

?>