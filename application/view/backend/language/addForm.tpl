<div>	
	<form onSubmit="lng.add(this); return false;" action="{link controller=backend.language action=add}">
		<select name="id" class="select" id="addLang-sel">
		   {html_options options=$languages_select}
		</select>
		<span class="progressIndicator" id="addLangFeedback" style="display: none;"></span>
		<input type="submit" value="{t _add_lang_button}" name="sm" class="submit" />
		<span>{t _or} </span>
		<a href="#" class="cancel" onClick="restoreMenu('addLang', 'langPageMenu'); return false;">{t _cancel}</a>
	</form>	
</div>