jQuery(document).ready(function($) {
    var container = $('.wp-user-rewards-container');
    $('.wp-user-rewards-title').click(function() {
        if (container.is(':visible'))
            container.slideUp();
        else
            container.slideDown();
    });
});
