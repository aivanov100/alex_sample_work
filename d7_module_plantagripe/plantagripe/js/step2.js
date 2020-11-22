(function ($) {

    $(document).ready(
        function () {
            if ($('#edit-selected-biz-name').val() != "") {
                $("#biz_search_div").hide();
                $("#business_information_container").show();
                $('.business_title').html($('#edit-selected-biz-name').val());
                $('.business_address_content').html($('#edit-selected-biz-address').val());
            }
        }
    );

    Drupal.behaviors.defaultToBizManual = {
        attach: function (context, settings) {
            $('#click_to_enter_manually_btn').click(
                function () {

                    $('#edit-biz-manual-name').val($('#edit-biz-search-name').val());
                    $('#edit-biz-manual-address').val($('#edit-biz-search-address').val());
                    $('#edit-biz-manual-city').val($('#edit-biz-search-city').val());
                    $('#edit-biz-manual-country').val($('#edit-biz-search-country').val());
                    $('select[name="biz_manual_state"]').val($('select[name="biz_search_state"]').val());

                    $("#biz_search_div").hide();
                    $("#biz_manual_div").show();
                }
            );
        }
    };

    Drupal.behaviors.bizManualToBizSearch = {
        attach: function (context, settings) {
            $('#click_to_search_btn').click(
                function () {
                    $("#biz_manual_div").hide();
                    $("#biz_search_div").show();
                }
            );
        }
    };

    Drupal.behaviors.summaryToBizSearch = {
        attach: function (context, settings) {
            $('#click_to_search_btn2').click(
                function () {
                    $("#business_information_container").hide();
                    $("#biz_search_div").show();
                }
            );
        }
    };

    Drupal.behaviors.tooltipAccountNumber = {
        attach: function (context, settings) {
            $("#account_number_tooltip").hover(
                function () {
                    $("#tooltip_box_text_0").hide();
                    $("#tooltip_box_text_1").show();
                },function () {
                    $("#tooltip_box_text_1").hide();
                    $("#tooltip_box_text_0").show();
                }
            );
        }
    };

    Drupal.behaviors.tooltipReferenceNumber = {
        attach: function (context, settings) {
            $("#reference_number_tooltip").hover(
                function () {
                    $("#tooltip_box_text_0").hide();
                    $("#tooltip_box_text_2").show();
                },function () {
                    $("#tooltip_box_text_2").hide();
                    $("#tooltip_box_text_0").show();
                }
            );
        }
    };

    Drupal.behaviors.tooltipPrivateInfo = {
        attach: function (context, settings) {
            $("#private_info_tooltip").hover(
                function () {
                    $("#tooltip_box_text_0").hide();
                    $("#tooltip_box_text_3").show();
                },function () {
                    $("#tooltip_box_text_3").hide();
                    $("#tooltip_box_text_0").show();
                }
            );
        }
    };

    Drupal.behaviors.nextButtonValidation = {
        attach: function (context, settings) {
            $('input[name="next_step_2"]').click(
                function () {
                    if ($('#edit-factual-id').val() == "" && $('#edit-manual-biz-selected').val() == 0) {
                        var error = '<ul>';
                        error += '<li>Please select a business.</li>';
                        error += '</ul>';
                        if($('.step_2_messages').is(':visible')) {
                            $('.step_2_messages').hide();
                        }
                        if(error != '<ul></ul>') {
                            $('.step_2_messages').html(error);
                            $('.step_2_messages').addClass('error');
                            $('.step_2_messages').show();
                        }
                        return false;
                    }
                }
            );
        }
    };

    Drupal.behaviors.disableSearchKeypress1 = {
        attach: function (context, settings) {
            $('#edit-biz-search-name').keypress(
                function (event) {
                    var keycode = (event.keyCode ? event.keyCode : event.which);
                    if(keycode == '13') {
                        return false;
                    }
                }
            );
        }
    };

    Drupal.behaviors.disableSearchKeypress2 = {
        attach: function (context, settings) {
            $('#edit-biz-search-address').keypress(
                function (event) {
                    var keycode = (event.keyCode ? event.keyCode : event.which);
                    if(keycode == '13') {
                        return false;
                    }
                }
            );
        }
    };

    Drupal.behaviors.disableSearchKeypress3 = {
        attach: function (context, settings) {
            $('#edit-biz-search-city').keypress(
                function (event) {
                    var keycode = (event.keyCode ? event.keyCode : event.which);
                    if(keycode == '13') {
                        return false;
                    }
                }
            );
        }
    };

})(jQuery);
