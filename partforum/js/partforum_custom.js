

function hide_all_replies(postid) {

    $(".hide_all_reply" + postid).toggle("slow", function() {
        if ($(".hide_all_reply"+postid ).css('display') == 'block') {
            $("#hide_rply_"+postid).text('Hide this post replies');      
        } else {
            $("#hide_rply_"+postid).text('show this post replies');
        }
    }); 

}

function partforum_post_toggle(Y, postid){ 
 
    $( document ).ready(function() {
        //  $( "#row_partforum_maincontent"+postid ).hide();
        $( "#header_partforum"+postid ).click(function() {

            $( "#row_partforum_maincontent"+postid ).toggle( "slow", function() {
                // Animation complete.

                if ($( "#row_partforum_maincontent"+postid ).css('display') == 'block') {
                    $("#partform_img"+postid).html('<img src=\'' + M.cfg.wwwroot +'/pix/t/expanded.png\'>');
                } else {
                    $("#partform_img"+postid).html('<img src=\'' + M.cfg.wwwroot +'/pix/t/collapsed.png\'>');
                }
            });
        });
    });
 
}

function partforum_instruction_visibility(enablepopup) {
   
    if (enablepopup > 0) {
        $('.mod_participation_instruction').hide();
    } else {
       $('.mod_participation_instruction').show();
    }  
    
}

function show_rating_map(){
    // Configure the popup.
    var config = {
        headerContent : 'Graph relating participation to grade',
        bodyContent :'<img id=partform_img src=\'' + M.cfg.wwwroot +'/mod/partforum/pix/partforum_rating.png\'>',
        draggable : true,
        modal : true,
        zIndex : 1000,         
        centered: true,
        width: '60em',
        visible: false,
        postmethod: 'form',
        footerContent: null
    };

    var popup = { dialog: null };
    popup.dialog = new M.core.dialogue(config);
    popup.dialog.show();
    
}

function show_instruction_dialog(e,args){
   // content =  $('mod_participation_instruction').html(args.message);
   // Configure the popup.
    var config = {
        headerContent : args.heading,
        bodyContent : args.message,
        draggable : true,
        modal : true,
        zIndex : 1000,         
        centered: false,
        width: '60em',
        visible: false,
        postmethod: 'form',
        footerContent: null
    };

    var popup = { dialog: null };
    popup.dialog = new M.core.dialogue(config);
    popup.dialog.show();
    
}
