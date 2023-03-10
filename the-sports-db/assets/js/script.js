jQuery(document).ready(function() {
	
	const submithandler = function(e) {
		
		let event = e;
		
		let content = jQuery('#content').val();
		
		const regex = /\[athlete id="?(\d+)"?\]/gi;
		
		let athlete_ids = content.matchAll(regex);
		
		athlete_ids = Array.from(athlete_ids);
		
		if(athlete_ids.length > 0) {
			
			let ids = new Array();
			
			jQuery(athlete_ids).each(function() {
				ids.push(this[1]);
			});
			
			event.preventDefault();
			
			let data = 'ids='+ids+'&action=curl_sports_db';
			
			jQuery.post(ajaxurl, data, function(response) {
				
				response = jQuery.parseJSON(response);
				
				if(response.type === 'error') {
					event.stopPropagation();
					alert(response.errors[response.error]);
				} else if(response.type === 'success') {
					jQuery('form#post').unbind('submit', submithandler);
					jQuery('form#post').submit();
				}
				
			});
	
		}
	}

	jQuery('form#post').bind('submit', submithandler);
	
});