jQuery(document).ready(function($) {
    var i10n = i10n_WPUserRewards, working = false;
    var submitBtn = $('#wp-user-reward-wechat').closest('form').find('input[type=submit]');

    function toggleFrame(status, spinner) {
        switch (status) {
            case 'unlock':
                working = false;
                submitBtn.removeAttr('disabled');
                spinner.hide();
                break;
            case 'lock':
                working = false;
                submitBtn.attr('disabled', 'disabled');
                spinner.show();
                break;
        }
    }

    function installEvents(key) {
        var mediaBtn = $('#wp-user-reward-' + key + '-media'),
            removeBtn = $('#wp-user-reward-' + key + '-remove'),
            spinner = $('#wp-user-reward-' + key + '-spinner'),
            container = $('#wp-user-reward-' + key + '-photo');
        var mediaFrame = wp.media.frames['wp_user_reward_' + key + '_frame'] = wp.media({
            title: i10n.insertMediaTitle,
            button: { text: i10n.insertIntoPost },
            library : { type : 'image' },
            multiple: false
        });
        mediaFrame.on('select', function() {
            toggleFrame('lock', spinner);
            var qrcodeUrl = mediaFrame.state().get('selection').first().toJSON().id;
            $.post(ajaxurl, {
                action: 'assign_wp_user_reward_' + key + '_media',
                media_id: qrcodeUrl,
                user_id: i10n.user_id,
                _wpnonce: i10n[key + 'MediaNonce']
            }, function(data) {
                if (data != '') {
                    container.html(data);
                    removeBtn.show();
                    toggleFrame('unlock', spinner);
                }
            });
        });
        mediaBtn.click(function() {
            if (working)
                return;
            mediaFrame.open();
        });
        removeBtn.click(function() {
            if (working)
                return;
            toggleFrame('lock', spinner);
            $.get(ajaxurl, {
                action: 'remove_wp_user_reward_' + key,
                user_id: i10n.user_id,
                _wpnonce: i10n[key + 'DeleteNonce']
            }).done(function(data) {
                if (data != '') {
                    container.html(data);
                    removeBtn.hide();
                    toggleFrame('unlock', spinner);
                }
            });
        });
    }

    installEvents('wechat');
    installEvents('alipay');
});
