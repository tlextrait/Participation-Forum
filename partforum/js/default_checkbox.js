$(document).ready(function() {

   $('#id_ratingtime').attr( 'checked', true );
 //  $('#id_scale_modgrade_point').attr('disabled',true);
   $( "input[name='scale[modgrade_point]").attr('readonly',true);
   
   $('#id_scale_modgrade_type').change(function(){
       $('#id_scale_modgrade_point').attr('readonly',true);
   });
   
});
