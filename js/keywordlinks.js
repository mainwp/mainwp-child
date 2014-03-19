/**
 * Mouse click tracking
 */
jQuery(document).ready(function($){  
  $('.kwl-regular-link').click(function(){                                            
        var link_id = $(this).attr('link-id');        
        if (link_id) {
            $.ajax({
                data : {
                        link_id: link_id,
                        ip: kwlIp,
                        referer: kwlReferer,
                        action: 'keywordLinksSaveClick',
                        nonce: kwlNonce
                },                                                         
                type: 'POST',
                url: kwlAjaxUrl
            });
        }                               
  });                  
});

