/**
 * Initiate heatmap object
 */



jQuery(window).load(function(){
	if ( heatmapError == 0 )
	{
		jQuery('body').append( '<div id="hmap_loading" style="position:fixed;top:0;left:50%;margin-left:-200px;width:400px;height:30px;line-height:30px;background:#ffd;border:1px solid #bb9;border-top:none;text-align:center;font-weight:bold;border-bottom-left-radius:8px;border-bottom-right-radius:8px;">Loading...</div>' );
		setTimeout(generate_heatmap, 1000);
	}
	else
	{
		jQuery('body').append( '<div id="hmap_error" style="position:fixed;top:0;left:50%;margin-left:-200px;width:400px;height:30px;line-height:30px;background:#fee;border:1px solid #b99;border-top:none;text-align:center;font-weight:bold;border-bottom-left-radius:8px;border-bottom-right-radius:8px;">An error occured.</div>' );
	}
});


function generate_heatmap()
{
	var hmap = h337.create({"element":document.body, "radius":15, "visible":true});
	var width = jQuery(document).width();
	var data = [];
	for ( i in heatmapClick )
	{
		data.push({
			x: ( heatmapClick[i].w-width > 0 ? heatmapClick[i].x - ( Math.floor(heatmapClick[i].w-width)/2 ) : heatmapClick[i].x ),
			y: heatmapClick[i].y,
			count: 1
		});
	}
	var max = Math.floor(data.length/10);
	hmap.store.setDataSet({
		max: ( max > 5 ? Math.floor(data.length/max) : 5 ), 
		data: data,
		callback: function(){
			jQuery('#hmap_loading').fadeOut(500);
		}
	});
}
