<?php

ClassLoader::import("application.controller.FrontendController");
ClassLoader::import('application.model.category.Category');
ClassLoader::import('application.model.category.Filter');
ClassLoader::import('application.model.product.Product');
ClassLoader::import('application.model.product.ProductFilter');
ClassLoader::import('application.model.product.ProductCount');

/**
 * Index controller for frontend
 *
 * @package application.controller
 */
class CategoryController extends FrontendController
{
  	protected $filters = array();
  	
  	protected $productFilter;
  	
  	protected $category;
  	
	protected $categoryID = 1;
	  
	public function init()
  	{
	  	parent::init();
	  	$this->addBlock('FILTER_BOX', 'boxFilter', 'block/box/filter');	    
	}
  
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
			$this->filters = ActiveRecordModel::getRecordSet('Filter', $f, ActiveRecord::LOAD_REFERENCES);
		}

		// get category instance
		$this->category = Category::getInstanceById($this->categoryID, Category::LOAD_DATA);

		// pagination
		$currentPage = $this->request->getValue('page') 
			or $currentPage = 1;

		$perPage = $this->config->getValue('NUM_PRODUCTS_PER_CAT');
		$offsetStart = (($currentPage - 1) * $perPage) + 1;
		$offsetEnd = $currentPage * $perPage;
		
		$selectFilter = new ARSelectFilter();
		$selectFilter->setLimit($perPage, $offsetStart - 1);

		// setup ProductFilter
		$productFilter = new ProductFilter($this->category, $selectFilter);
		foreach ($this->filters as $filter)
		{
			$productFilter->applyFilter($filter);  
		}

		$this->productFilter = $productFilter;

		$products = $this->category->getProductsArray($productFilter, ActiveRecord::LOAD_REFERENCES);

		$count = new ProductCount($this->productFilter);
		$totalCount = $count->getCategoryProductCount($productFilter);
		$offsetEnd = min($totalCount, $offsetEnd);
		
		$urlParams = array('controller' => 'category', 'action' => 'index', 
						   'id' => $this->request->getValue('id'),
						   'cathandle' => $this->request->getValue('cathandle')
						   );
		if ($this->request->getValue('filters'))
		{
			$urlParams['filters'] = $this->request->getValue('filters');
		}
		$url = Router::getInstance()->createURL($urlParams) . '/p';
		
		$response = new ActionResponse();
		$response->setValue('id', $this->categoryID);
		$response->setValue('url', $url);
		$response->setValue('products', $products);
		$response->setValue('count', $totalCount);
		$response->setValue('offsetStart', $offsetStart);
		$response->setValue('offsetEnd', $offsetEnd);
		$response->setValue('perPage', $perPage);
		$response->setValue('currentPage', $currentPage);
		$response->setValue('category', $this->category->toArray());
		return $response;
	}
	
	protected function boxFilterBlock()
	{
		ClassLoader::import('application.model.category.Category');	 
		ClassLoader::import('application.model.category.Filter');
			 		
		if ($this->categoryID < 1)
		{
		  	$this->categoryID = 1;
		}
		
		// get current category instance
		$currentCategory = Category::getInstanceByID($this->categoryID, true);	
		
		// get category filter groups
		$filterGroupSet = $currentCategory->getFilterGroupSet();
		if (!$filterGroupSet || (0 == $filterGroupSet->getTotalRecordCount()))
		{
		  	return new RawResponse();
		}		
		$filterGroups = $filterGroupSet->toArray(true);

		// get counts by filters, categories, etc
		$count = new ProductCount($this->productFilter);
		$filtercount = $count->getCountByFilters($this->category->getFilterSet());

		// get group filters
		$ids = array();
		foreach ($filterGroups as $group)
		{
		  	$ids[] = $group['ID'];
		}		

		if ($ids)
		{
			$filterCond = new INCond(new ARFieldHandle('Filter', 'filterGroupID'), $ids);
			$filterFilter = new ARSelectFilter();
			$filterFilter->setCondition($filterCond);
			$filterFilter->setOrder(new ARFieldHandle('Filter', 'filterGroupID'));
			$filterFilter->setOrder(new ARFieldHandle('Filter', 'position'));
			
			$filters = ActiveRecord::getRecordSet('Filter', $filterFilter, true)->toArray(true);
								
			// sort filters by group
			$sorted = array();
			foreach ($filters as $filter)
			{
				$filter['count'] = $filtercount[$filter['ID']];
				$sorted[$filter['FilterGroup']['ID']][] = $filter;  	
			}
			
			// assign sorted filters to group arrays
			foreach ($filterGroups as &$group)
			{
			  	if (isset($sorted[$group['ID']]))
			  	{
				    $group['filters'] = $sorted[$group['ID']];
				}
			}
		}

	 	$response = new BlockResponse();
	 	if ($this->filters)
	 	{
			$filters = $this->filters->toArray(true);
			$response->setValue('filters', $filters);	

			// remove already applied filter groups
			$activeFilterGroups = $filterGroups;
			foreach ($filters as $key => &$filter)
			{
			 	$id = $filter['FilterGroup']['ID'];

				foreach ($filterGroups as $k => &$group)
				{
					if ($group['ID'] == $id)
				  	{						
					    unset($activeFilterGroups[$k]);
					}
				} 	
			}
			$filterGroups = $activeFilterGroups;	
		}

	 	$response->setValue('category', $currentCategory->toArray());		 
	 	$response->setValue('groups', $filterGroups);		 

		return $response;	 	
	}	
}

?>