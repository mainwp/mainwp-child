/**
 * Mouse click tracking
 */

var trackerData = [];
	
jQuery(document).ready(function($){
	
	
	$(document).click(function(e){
		var element = $(e.target).parents().map(getSelector).get().reverse().join(">");
		element += '>'+$(e.target).map(getSelector).get();
		var url = ( $(e.target).attr('href') ) ? $(e.target).attr('href') : $(e.target).attr('src');
		var title = $(e.target).attr('title');
		var alt = $(e.target).attr('alt');
		var text = ( $(e.target).text().length == $(e.target).html().length ) ? $(e.target).text().substring(0, 511) : '';
		trackerData.push({
			coord: e.pageX+','+e.pageY,
			type: 'left',
			viewport: $(window).width()+','+$(window).height(),
			element: element,
			url: url,
			title: title,
			alt: alt,
			text: text
		});
	});
	
	$(window).unload(function(){
		sendTrackData(false); // Make sure to send track data before going off from page, set it synchronious
	});
	
	function getSelector()
	{
    	var el_class = $(this).attr('class');
    	var el_id = $(this).attr('id');
    	var el_index = $(this).index();
    	return this.tagName + ( el_id ? '#'+el_id : '' ) + 
    		( el_class ? '.'+el_class.match(/^\S+/) : '' ) + 
    		( el_index > 0 ? ':eq('+(el_index)+')' : '' );
	}
	
	function sendTrackData( sync )
	{
		if ( trackerData.length < 1 )
			return;
		$.ajax({
			data : {
				data: trackerData,
				action: 'heatmapSaveClick',
				nonce: trackerNonce
			},
			complete: function(){
				trackerData = [];
			},
			async: ( sync ) ? false : true,
			type: 'POST',
			url: trackerAjaxUrl
		});
	}
	setInterval(function(){ sendTrackData(false); }, 10000);
});
