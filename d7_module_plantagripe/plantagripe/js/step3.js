(function ($) {

    Drupal.behaviors.validateYouTube = {
        attach: function (context, settings) {
            $('#edit-youtube-url').change(
                function () {
                    var error = '<ul>';
                    var youTubeURL = $('#edit-youtube-url').val();
                    if(youTubeURL != '') {
                        var matchYouTubeURL = _isValidYouTubeURL(youTubeURL);
                        if(matchYouTubeURL == 0) {
                            error += '<li>Please provide a valid YouTube URL.</li>';
                        }
                    }
                    error += '</ul>';
                    if($('.messages').is(':visible')) {
                        $('.messages').hide();
                    }
                    if(error != '<ul></ul>') {
                        $('.messages').html(error);
                        $('.messages').addClass('error');
                        $('.messages').show();
                    }
                }
            );
        }
    };

    //validation for youtube url
    function _isValidYouTubeURL(youtubeURL)
    {
        var regExp = /^.*(youtu.be\/|v\/|u\/\w\/|embed\/|watch\?v=)([^#\&\?]*).*/;
        var match = youtubeURL.match(regExp);
        if (match&&match[2].length == 11) {
            return match[2];
        }else{
            return 0;
        }

    }

})(jQuery);
