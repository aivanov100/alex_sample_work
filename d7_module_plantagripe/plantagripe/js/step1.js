(function ($) {

    Drupal.behaviors.tooltipGripeTitle = {
        attach: function (context, settings) {
            $("#gripe_title_tooltip").hover(
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

    Drupal.behaviors.tooltipGripeDescription = {
        attach: function (context, settings) {
            $("#gripe_description_tooltip").hover(
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

})(jQuery);
