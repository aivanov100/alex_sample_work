(function ($) {

    Drupal.behaviors.showBizManualSummary = {
        attach: function (context, settings) {
            $('#biz_save_btn').click(
                function () {
                    var error = '<ul>';
                    if($('#edit-biz-manual-name').val() == '') {
                        error += '<li>Please provide the business\'s name.</li>';
                    }
                    if($('#edit-biz-manual-address').val() == '') {
                        error += '<li>Please provide the business\'s address.</li>';
                    }
                    if($('#edit-biz-manual-city').val() == '') {
                        error += '<li>Please provide the business\'s city.</li>';
                    }
                    if($('select[name="biz_manual_state"]').val() == 0 ) {
                        if($('#edit-biz-manual-country').val() == '79') {
                            error += '<li>Please select the business\'s state.</li>';
                        }
                        else if($('#edit-biz-manual-country').val() == '30') {
                            error += '<li>Please select the business\'s province.</li>';
                        }
                        else {
                            error += '<li>Please select the business\'s state/province.</li>';
                        }
                    }
                    if($('#edit-biz-manual-country').val() == 0) {
                        error += '<li>Please select the business\'s country</li>';
                    }
                    error += '</ul>';
                    if($('.biz-manual-messages').is(':visible')) {
                        $('.biz-manual-messages').hide();
                    }
                    if(error != '<ul></ul>') {
                        $('.biz-manual-messages').html(error);
                        $('.biz-manual-messages').addClass('error');
                        $('.biz-manual-messages').show();
                    }else{

                        $('#edit-factual-id').val("");
                        $('#edit-manual-biz-selected').val("1");

                        var summary = '';
                        var title = $('#edit-biz-manual-name').val();
                        var address = $('#edit-biz-manual-address').val();
                        var city = $('#edit-biz-manual-city').val();
                        var state = $('select[name="biz_manual_state"] :selected').text();

                        summary = address + ', ' + city + ', ' + state + ', ';
                        if($('#edit-biz-manual-zip').val() != 0 ) {
                            summary += $('#edit-biz-manual-zip').val();
                            summary += ', ';
                        }
                        summary += $('select[name="biz_manual_country"] :selected').text();

                        $('#edit-selected-biz-name').val(title);
                        $('#edit-selected-biz-address').val(summary);

                        $('.business_title').html(title);
                        $('.business_address_content').html(summary);
                        $("#biz_manual_div").hide('');
                        $('.step_2_messages').hide();
                        $('#business_information_container').show();
                    }
                }
            );
        }
    };

})(jQuery);
