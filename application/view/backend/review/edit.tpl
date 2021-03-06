{form handle=$form action="controller=backend.review action=update id=`$review.ID`" onsubmit="Backend.Review.Editor.prototype.getInstance(`$review.ID`, false).submitForm(); return false;" method="post" role="product.update"}

	{foreach $ratingTypes as $type}
		{input name="rating_`$type.ID`"}
			{label}{$type.name_lang|@or:_rating}:{/label}
			{selectfield options=$ratingOptions}
		{/input}
	{/foreach}

	<p class="required">
		{input name="nickname"}
			{label}{t _nickname}:{/label}
			{textfield}
		{/input}

		{input name="title"}
			{label}{t _title}:{/label}
			{textfield}
		{/input}

		{input name="text"}
			{label}{t _text}:{/label}
			{textarea}
		{/input}
	</p>

	{include file="backend/eav/fields.tpl" item=$review}

	<fieldset class="controls">
		<span class="progressIndicator" style="display: none;"></span>
		<input type="submit" name="save" class="submit" value="{t _save}">
		{t _or}
		<a class="cancel" href="#">{t _cancel}</a>
	</fieldset>

{/form}