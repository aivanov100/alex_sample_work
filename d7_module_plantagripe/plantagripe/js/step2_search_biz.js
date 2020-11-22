function selectFactualBiz(factual_id, bizName, bizAddress){
    jQuery('#edit-factual-id').val(factual_id);
    jQuery('#edit-manual-biz-selected').val("0");

    jQuery('#edit-selected-biz-name').val(bizName);
    jQuery('#edit-selected-biz-address').val(bizAddress);

    jQuery('.business_title').html(bizName);
    jQuery('.business_address_content').html(bizAddress);
    jQuery("#biz_search_div").hide('');
    jQuery('.step_2_messages').hide();
    jQuery('#business_information_container').show();
}
