(function($) {
	$(document).ready(function() {
		$('#automessage-hook-select').change(function(event) {
			event.preventDefault();

			var text = automessage.replace_additional[$(this).val()];
			text = typeof text !== 'undefined' ? text : '';
			console.log(text);
			$('#automessage-instructions-aditional').html(text);
		});
	});
})(jQuery);