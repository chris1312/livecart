{*nolive*}
<div id="customizeMenu">
	<span id="modeTitle">Live Customization Mode</span>
	<ul>
		<li id="modeTemplate" class="active"><a href="">Edit templates</a></li>
		<li id="modeTranslation"><a href="">Edit translations</a></li>
		<li id="modeCss"><a href="">Edit CSS</a></li>
		<li id="modeExit"><a href="">Exit</a></li>
	</ul>
</div>

<div id="transDialogBox" style="position: absolute;z-index: 10000; display: none;">
	<div class="menuLoadIndicator" id="transDialogIndicator"></div>
	<div id="transDialogContent">
	</div>
</div>

<div id="transDialogMenu" style="display:none;position: absolute;z-index: 60000; background-color: yellow; border: 1px solid black; padding: 3px;"><a href="#" id="transLink">{tn _live_translate}</a></div>

<script type="text/javascript">
	var cust = new Customize();
	cust.setActionUrl('{link controller=backend.language action=translationDialog}');
	cust.initLang();
	new Draggable('transDialogBox');
	Event.observe('transDialogBox', 'mousedown', cust.stopTransCancel.bind(cust), false);
	Event.observe('transLink', 'click', cust.translationMenuClick.bindAsEventListener(cust), true);
</script>