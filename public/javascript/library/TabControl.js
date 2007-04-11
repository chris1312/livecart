Event.fire = function(element, event) 
{
   Event.observers.each(function(observer) 
   {
        if(observer[1] == event && observer[0] == element)
        {
            var func = observer[2];
            func();
        }
   });
};


var TabControl = Class.create();
TabControl.prototype = {
    __instance__: null,
    
	activeTab: null,
	indicatorImageName: "image/indicator.gif",

	initialize: function(tabContainerName, urlParserCallback, idParserCallback, callbacks)
	{
        try
        {  
            this.tabContainerName = tabContainerName;
            this.urlParserCallback = urlParserCallback;
            this.idParserCallback = idParserCallback;
            this.callbacks = callbacks ? callbacks : {};
            
            this.__nodes__();
            this.__bind__();

            this.decorateTabs();
            this.countersCache = {};
        }
        catch(e)
        {
            console.info(e)
        }
	},
    
    __nodes__: function()
    {
        this.nodes = {};
        this.nodes.tabContainer = $(this.tabContainerName);
		this.nodes.tabList = this.nodes.tabContainer.down(".tabList");
		this.nodes.tabListElements = document.getElementsByClassName("tab", this.nodes.tabList);
		this.nodes.sectionContainer = this.nodes.tabContainer.down(".sectionContainer");
    },
    
    __bind__: function()
    {
        var self = this;   
        this.nodes.tabListElements.each(function(li) 
        {
			var link = li.down('a');
            var indicator = '<img src="' + self.indicatorImageName + '" class="tabIndicator hidden" alt="Tab indicator" /> ';
            
            Event.observe(link, 'click', function(e) { if(e) Event.stop(e); });
            Event.observe(li, 'click', function(e) { 
                if(e) Event.stop(e); 
                self.handleTabClick({'target': li}) 
            });
			Event.observe(li, 'mouseover', function(e) { 
                if(e) Event.stop(e); 
                self.handleTabMouseOver({'target': li}) 
            });
			Event.observe(li, 'mouseout', function(e) { 
                if(e) Event.stop(e); 
                self.handleTabMouseOut({'target': li}) 
            });
            
            li.update(indicator + li.innerHTML);
		});
    },
    
    __init__: function()
    {
        this.activateTab();
    },

    decorateTabs: function()
    {
        this.nodes.tabListElements.each(function(tab)
        {
            var firstLink = tab.down('a');
            new Insertion.After(firstLink, '<span class="tabCounter"> </span>');
        });  
    },

    getInstance: function(tabContainerName, urlParserCallback, idParserCallback, callbacks)
    {
        if(!TabControl.prototype.__instance__)
        {
            TabControl.prototype.__instance__ = new TabControl(tabContainerName, urlParserCallback, idParserCallback, callbacks);
            TabControl.prototype.__instance__.__init__();
        }
        else if(false !== urlParserCallback)
        {
            TabControl.prototype.__instance__.__init__();
        }
        
        return TabControl.prototype.__instance__;
    },

	handleTabMouseOver: function(args)
	{
		if (this.activeTab != args.target)
		{
			Element.removeClassName(args.target, 'inactive');
			Element.addClassName(args.target, 'hover');
		}
	},

	handleTabMouseOut: function(args)
	{
		if (this.activeTab != args.target)
		{
			Element.removeClassName(args.target, 'hover');
			Element.addClassName(args.target, 'inactive');
		}
	},

	handleTabClick: function(args)
	{
        if(this.callbacks.beforeClick) this.callbacks.beforeClick.call(this);
        this.activateTab(args.target);
        if(this.callbacks.afterClick) this.callbacks.afterClick.call(this);
	},
    
    addHistory: function()
    {
        var locationHash = "#" + Backend.ajaxNav.getHash();
        this.nodes.tabListElements.each(function(tab)
        {
            if(locationHash.indexOf("#" + tab.id) !== -1)
            {
                locationHash = locationHash.substring(0, locationHash.indexOf(tab.id) - 1);
                throw $break;
            }
        });
        
		Backend.ajaxNav.add(locationHash.substring(1) + "#" + this.activeTab.id);
    },

	activateTab: function(targetTab, onComplete)
	{
        targetTab = $(targetTab);
        
		if(!targetTab) 
		{
			targetTab = this.nodes.tabListElements[0];	
		}
                
		// get help context
		var helpContext = document.getElementsByClassName('tabHelp', targetTab);
		if (helpContext.length > 0)
		{
			Backend.setHelpContext(helpContext[0].firstChild.nodeValue);
		}
		
		var contentId = this.idParserCallback(targetTab.id);
        if(!$(contentId)) new Insertion.Top(this.nodes.sectionContainer, '<div id="' + contentId + '"></div>');		

		if(this.activeTab)
		{
			Element.removeClassName(this.activeTab, 'active');
			Element.addClassName(this.activeTab, 'inactive');
			Element.hide(this.activeContent);
		}
		this.activeTab = targetTab;
        this.activeContent = $(contentId);
                
        Element.removeClassName(this.activeTab, 'hover');
		Element.addClassName(this.activeTab, 'active');
		Element.show(contentId);
        
		if (Element.empty($(contentId)))
		{
            new LiveCart.AjaxUpdater(this.urlParserCallback(targetTab.down('a').href), contentId, targetTab.down('.tabIndicator'), 'bottom',  onComplete);
		}
        else
        {
            onComplete();
        }
        
        this.addHistory();
	},

	/**
	 * Reset content related to a given tab. When tab will be activated content must
	 * be resent
	 */
	resetContent: function(tabObj)
	{
		$($(tabObj).id + 'Content').update();
	},

	reloadActiveTab: function()
	{
		this.resetContent(this.activeTab);
		this.activateTab(this.activeTab);
	},

	getActiveTab: function()
	{
		return this.activeTab;
	},

	setTabUrl: function(tabId, url)
	{
		$(tabId).url = url;
	},
    
    setCounter: function(tab, value, hashId)
    {
        if(!this.countersCache[hashId]) this.countersCache[hashId] = {};
        
        tab = $(tab);
        
        if(!tab) throw new Error('Could not find tab!');
        
        var counter = tab.down('.tabCounter');
        if(false === value)
        {
            counter.update('');
            delete this.countersCache[hashId][tab.id];
        }
        else
        {
            counter.update("(" + value + ")");
            this.countersCache[hashId][tab.id] = value;
        }
    },
        
    setAllCounters: function(counters, hashId)
    {     
        var self = this;
        $H(counters).each(function(tab) {
            self.setCounter(tab[0], tab[1], hashId);
        });
    },
    
    restoreCounter: function(tab, hashId)
    {
        tab = $(tab);

        if(tab && this.countersCache[hashId][tab.id])
        {
            this.setCounter(tab.id, this.countersCache[hashId][tab.id]);
            return true;
        }
        
        return false;
    },
    
    restoreAllCounters: function(hashId)
    {
        var self = this;
        var restored = false;
        if(this.countersCache[hashId])
        {
            $A(this.nodes.tabListElements).each(function(tab) {
                restored = self.restoreCounter(tab, hashId) ? true : restored;    
            });
        }
        
        return restored;  
    },
    
    getCounter: function(tab)
    {
        tab = $(tab);
        
        if(!tab) throw new Error('Could not find tab!');
        
        var counter = tab.down('.tabCounter');      
        var match = counter.innerHTML.match(/\((\d+)\)/);
        return match ? parseInt(match[1]) : 0;
    }
}