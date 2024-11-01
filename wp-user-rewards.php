<?php
/**
 Plugin Name: WP User Rewards
 Plugin URI: https://bruceking.site/2017/08/20/wp-user-rewards/
 Description: Adds user reward/donate fields to user profiles, including Wechat pay QRCode, Alipay QRCode, and PayPal link.
 Version: 0.1
 Author: Bruce King
 Author URI: https://bruceking.site/author/bruceking/
 License: MIT license
 */

class WP_User_Rewards {

    private $remove_wechat_nonce, $remove_alipay_nonce, $reward_upload_error, $uid_being_edited;

    public function __construct() {
        load_plugin_textdomain('wp-user-rewards', false, dirname(plugin_basename(__FILE__)).'/lang/');

        add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
        add_action('show_user_profile', array($this, 'edit_user_profile'));
        add_action('edit_user_profile', array($this, 'edit_user_profile'));

        add_action('personal_options_update', array($this, 'edit_user_profile_update'));
        add_action('edit_user_profile_update', array($this, 'edit_user_profile_update'));

        add_action('wp_ajax_assign_wp_user_reward_wechat_media', array($this, 'ajax_assign_wp_user_reward_wechat_media'));
        add_action('wp_ajax_assign_wp_user_reward_alipay_media', array($this, 'ajax_assign_wp_user_reward_alipay_media'));

        add_action('admin_action_remove-wp-user-reward-wechat', array($this, 'action_remove_wp_user_reward_wechat'));
        add_action('wp_ajax_remove_wp_user_reward_wechat', array($this, 'action_remove_wp_user_reward_wechat'));
        add_action('admin_action_remove-wp-user-reward-alipay', array($this, 'action_remove_wp_user_reward_alipay'));
        add_action('wp_ajax_remove_wp_user_reward_alipay', array($this, 'action_remove_wp_user_reward_alipay'));

        add_action('user_edit_form_tag', array($this, 'user_edit_form_tag'));

        add_filter('the_content', array($this, 'append_rewards'));
    }

    public function get_user_reward($key, $uid_or_email, $default = '', $size = 200) {
        if (is_numeric($uid_or_email))
            $uid = (int) $uid_or_email;
        elseif (is_string($uid_or_email) && ($user = get_user_by('email', $uid_or_email)))
            $uid = $user->ID;
        elseif (is_object($uid_or_email) && !empty($uid_or_email->user_id))
            $uid = (int) $uid_or_email->user_id;

        if (empty($uid))
            return $default;

        $rewards = (array) get_user_meta($uid, 'wp_user_reward', true);
        if (empty($rewards))
            return $default;

        $reward = &$rewards[$key];
        if (empty($reward))
            return $default;

        if (is_string($reward))
            return $reward;

        $reward_media_id = $reward['media_id'];
        if (!empty($reward_media_id))
            if (!($qrcode_full_path = get_attached_file($reward_media_id)))
                return $default;

        if (!array_key_exists($size, $reward)) {
            $reward[$size] = $reward['full'];
            if ($allow_dynamic_resizing = apply_filters('wp_user_rewards_dynamic_resize', true)) {
                $upload_path = wp_upload_dir();
                $baseurl = $upload_path['baseurl'];
                $basedir = $upload_path['basedir'];
                if (!isset($qrcode_full_path))
                    $qrcode_full_path = str_replace($baseurl, $basedir, $reward['full']);
                $editor = wp_get_image_editor($qrcode_full_path);
                if (!is_wp_error($editor)) {
                    $resized = $editor->resize($size, $size, true);
                    if (!is_wp_error($resized)) {
                        $dest_file = $editor->generate_filename();
                        $saved = $editor->save($dest_file);
                        if (!is_wp_error($saved))
                            $reward[$size] = str_replace($basedir, $baseurl, $dest_file);
                    }
                }
                update_user_meta($uid, 'wp_user_reward', $rewards);
            }
        }
        $result = $reward[$size];
        if ('http' != substr($result, 0, 4))
            $result = home_url($result);

        return $result;
    }

    public function get_user_reward_qrcode($key, $uid_or_email, $default = '', $size = 200, $alt = '') {
        $uri = $this->get_user_reward($key, $uid_or_email, $default);
        if (empty($uri))
            return '';

        if (empty($alt))
            $alt = get_the_author_meta('display_name', $uid_or_email).' '.$key.' qrcode';

        return apply_filters('wp_user_reward',
                '<img class="qrcode qrcode-'.$size.' photo sl_lazyimg" alt="'.esc_attr($alt).'"
                  src="'.esc_url($uri).'" width="'.$size.'" height="'.$size.'" />');
    }

    public function admin_enqueue_scripts($hook_suffix) {
        if ('profile.php' != $hook_suffix && 'user-edit.php' != $hook_suffix)
            return;

        if (current_user_can('upload_files'))
            wp_enqueue_media();
        
        $uid = ('profile.php' == $hook_suffix) ? get_current_user_id() : (int) $_GET['user_id'];

        $this->remove_wechat_nonce = wp_create_nonce('remove_wp_user_reward_wechat_nonce');
        $this->remove_alipay_nonce = wp_create_nonce('remove_wp_user_reward_alipay_nonce');

        wp_enqueue_script('wp-user-rewards',
            plugins_url('js/wp-user-rewards.js', __FILE__),
            array('jquery'), false, true);
        wp_localize_script('wp-user-rewards', 'i10n_WPUserRewards', array(
            'user_id'           => $uid,
            'insertMediaTitle'  => __('Choose a QRCode', 'wp-user-rewards'),
            'insertIntoPost'    => __('Set as reward QRCode', 'wp-user-rewards'),
            'wechatMediaNonce'  => wp_create_nonce('assign_wp_user_reward_wechat_nonce'),
            'wechatDeleteNonce' => $this->remove_wechat_nonce,
            'alipayMediaNonce'  => wp_create_nonce('assign_wp_user_reward_alipay_nonce'),
            'alipayDeleteNonce' => $this->remove_alipay_nonce
        ));
    }

    public function edit_user_profile($user_profile) {
        $uid = $user_profile->ID;
        $can_upload_files = current_user_can('upload_files');
        $rewards = $user_profile->wp_user_reward;
        $no_reward = empty($rewards);
        $no_wechat = $no_reward ? true : empty($rewards['wechat']);
        $no_alipay = $no_reward ? true : empty($rewards['alipay']);
        $done_media_enqueue = did_action('wp_enqueue_media');
        wp_nonce_field('wp_user_reward_nonce', '_wp_user_reward_nonce', false); ?>
        <h3><?php _e('Rewards', 'wp-user-rewards'); ?></h3>
        <table class="form-table">
            <?php
                $this->show_qrcode_field('wechat', $uid,
                    __('Wechat pay QRCode', 'wp-user-rewards'),
                    $can_upload_files, $done_media_enqueue, $no_wechat,
                    __('Delete Wechat pay QRCode', 'wp-user-rewards'),
                    __('No Wechat pay QRCode is set', 'wp-user-rewards'));
                $this->show_qrcode_field('alipay', $uid,
                    __('Alipay QRCode', 'wp-user-rewards'),
                    $can_upload_files, $done_media_enqueue, $no_alipay,
                    __('Delete Alipay QRCode', 'wp-user-rewards'),
                    __('No Alipay QRCode is set', 'wp-user-rewards'));
            ?>
            <tr>
                <th scope="row">
                    <label for="wp-user-reward-paypal"><?php _e('PayPal Link', 'wp-user-rewards'); ?></label>
                </th>
                <td colspan="2">
                    <input type="text" value="<?php echo $this->get_user_reward('paypal', $uid); ?>"
                        name="wp-user-reward-paypal" id="wp-user-reward-paypal" class="standard-text" />
                </td>
            </tr>
        </table>
    <?php }

    private function show_qrcode_field($key, $uid, $title, $can_upload_files,
            $done_media_enqueue, $no_qrcode, $remove_button, $no_qrcode_msg) { ?>
        <tr>
            <th scope="row">
                <label for="wp-user-reward-<?php echo $key; ?>"><?php echo $title; ?></label>
            </th>
            <td style="width: 200px; height: 200px; background: url(<?php echo plugins_url('', __FILE__).'/img/bg.png'; ?>) no-repeat;"
                id="wp-user-reward-<?php echo $key; ?>-photo">
                <?php echo $this->get_user_reward_qrcode($key, $uid); ?>
            </td>
            <td><?php
                if ($can_upload_files) {
                    do_action('wp_user_reward_'.$key.'_notices');
                    $nonce_field = 'remove_'.$key.'_nonce';
                    $remove_url = add_query_arg(array(
                        'action'   => 'remove-wp-user-reward-'.$key,
                        'user_id'  => $uid,
                        '_wpnonce' => $this->$nonce_field
                    )); ?>
                    <p style="display: inline-block; width: 26em;">
                        <span class="description">
                            <?php _e('Choose an image from your computer:'); ?>
                        </span><br />
                        <input type="file" name="wp-user-reward-<?php echo $key; ?>"
                                id="wp-user-reward-<?php echo $key; ?>" class="standard-text" />
                        <span class="spinner" id="wp-user-reward-<?php echo $key; ?>-spinner"></span>
                    </p>
                    <p>
                        <?php if ($can_upload_files && $done_media_enqueue) { ?>
                            <button type="button" class="button hide-if-no-js" id="wp-user-reward-<?php echo $key; ?>-media">
                                <?php _e('Choose from Media Library', 'wp-user-rewards'); ?>
                            </button> &nbsp;
                        <?php } ?>
                        <a href="<?php echo $remove_url; ?>" class="button item-delete submitdelete deletion"
                            id="wp-user-reward-<?php echo $key; ?>-remove"
                            <?php if ($no_qrcode) echo ' style="display:none;"'; ?>>
                            <?php echo $remove_button; ?>
                        </a>
                    </p>
                    <?php
                } else {
                    if (empty($no_qrcode))
                        echo '<span class="description">'.$no_qrcode_msg.'</span>';
                    else
                        echo '<span class="description">'.__('You do not have media management permissions. To change your QRCode, contact the blog administrator.', 'wp-user-rewards').'</span>';
                }
            ?></td>
        </tr>
    <?php }

    public function edit_user_profile_update($uid) {
        if (empty($_POST['_wp_user_reward_nonce']) ||
                !wp_verify_nonce($_POST['_wp_user_reward_nonce'], 'wp_user_reward_nonce'))
            return;

        if (!function_exists('wp_handle_upload'))
            require_once(ABSPATH.'wp-admin/includes/file.php');

        $rewards = (array) get_user_meta($uid, 'wp_user_reward', true);
        if ($rewards == null) $rewards = array(
            'wechat' => array('full' => ''),
            'alipay' => array('full' => ''),
            'paypal' => ''
        );

        $this->save_if_direct_upload('wechat', $rewards);
        $this->save_if_direct_upload('alipay', $rewards);

        $paypal_link = $_POST['wp-user-reward-paypal'];
        if (empty($paypal_link))
            unset($rewards['paypal']);
        else
            $rewards['paypal'] = $paypal_link;

        update_user_meta($uid, 'wp_user_reward', $rewards);
    }

    private function ajax_assign_wp_user_reward_media($key) {
        $uid = $_POST['user_id'];
        $media_id = $_POST['media_id'];
        $nonce = $_POST['_wpnonce'];
        if (empty($uid) || empty($media_id) ||
                !current_user_can('upload_files') || !current_user_can('edit_user', $uid) ||
                empty($nonce) || !wp_verify_nonce($nonce, 'assign_wp_user_reward_'.$key.'_nonce'))
            die;

        $uid = (int) $uid;
        $media_id = (int) $media_id;
        $rewards = (array) get_user_meta($uid, 'wp_user_reward', true);

        if (wp_attachment_is_image($media_id)) {
            $this->assign_new_user_reward($key, $media_id, $uid, $rewards);
            update_user_meta($uid, 'wp_user_reward', $rewards);
        }

        echo $this->get_user_reward_qrcode($key, $uid);

        die;
    }

    public function ajax_assign_wp_user_reward_wechat_media() {
        $this->ajax_assign_wp_user_reward_media('wechat');
    }

    public function ajax_assign_wp_user_reward_alipay_media() {
        $this->ajax_assign_wp_user_reward_media('alipay');
    }

    private function action_remove_qrcode($key) {
        $uid = $_GET['user_id'];
        $nonce = $_GET['_wpnonce'];
        $doingAjax = defined('DOING_AJAX') && DOING_AJAX;

        if (!empty($uid) &&  !empty($nonce) &&
                wp_verify_nonce($nonce, 'remove_wp_user_reward_'.$key.'_nonce')) {
            $uid = (int) $uid;

            if (!current_user_can('edit_user', $uid))
                wp_die(__('You do not have permission to edit this user.'));

            $rewards = (array) get_user_meta($uid, 'wp_user_reward', true);
            if (!empty($rewards)) {
                if (array_key_exists($key, $rewards)) {
                    $this->unset_or_delete_qrcode($rewards[$key]);
                    unset($rewards[$key]);
                    update_user_meta($uid, 'wp_user_reward', $rewards);
                }
            }

            if ($doingAjax)
                echo $this->get_user_reward_qrcode($key, $uid);
        }

        if ($doingAjax) die;
    }

    public function action_remove_wp_user_reward_wechat() {
        $this->action_remove_qrcode('wechat');
    }

    public function action_remove_wp_user_reward_alipay() {
        $this->action_remove_qrcode('alipay');
    }

    private function save_if_direct_upload($key, &$rewards) {
        $uploaded_file = $_FILES['wp-user-reward-'.$key];
        $uploaded_file_name = $uploaded_file['name'];
        if (!empty($uploaded_file_name)) {
            if (false !== strpos($uploaded_file_name, '.php')) {
                $this->reward_upload_error = __('For security reasons, the extension ".php" cannot be in your file name.', 'wp-user-rewards');
                add_action('user_profile_update_errors', array($this, 'user_profile_update_errors'));
                return;
            }

            add_filter('upload_size_limit', array($this, 'upload_size_limit'));

            $this->uid_being_edited = $uid;
            $qrcode = wp_handle_upload($uploaded_file, array(
                'mimes'                    => array(
                    'jpg|jpeg|jpe' => 'image/jpeg',
                    'gif'          => 'image/gif',
                    'png'          => 'image/png'),
                'test_form'                => false,
                'unique_filename_callback' => array($this, 'unique_filename_callback')));

            remove_filter('upload_size_limit', array($this, 'upload_size_limit'));

            if (empty($qrcode['file'])) {
                switch ($qrcode['error']) {
                    case 'File type does not meet security guidelines. Try another.':
                        $this->reward_upload_error = __('Please upload a valid image file for the QRCode.', 'wp-user-rewards');
                        break;
                    default:
                        $this->reward_upload_error = '<strong>'.__('There was an error uploading the avatar:', 'wp-user-rewards').'</strong> '.esc_html($qrcode['error']);
                }

                add_action('user_profile_update_errors', array($this, 'user_profile_update_errors'));
                return;
            }
            $this->assign_new_user_reward($key, $qrcode['url'], $uid, $rewards);
        }
    }

    private function assign_new_user_reward($key, $url_or_media_id, $uid, &$rewards) {
        $meta_value = array('full' => '');
        if (is_int($url_or_media_id)) {
            $meta_value['media_id'] = $url_or_media_id;
            $url_or_media_id = wp_get_attachment_url($url_or_media_id);
        }
        $meta_value['full'] = $url_or_media_id;
        $rewards[$key] = $meta_value;
    }

    public function user_profile_update_errors(WP_Error $errors) {
        $errors->add('reward_error', $this->reward_upload_error);
    }

    public function upload_size_limit($bytes) {
        return apply_filters('wp_user_rewards_upload_limit', $bytes);
    }

    public function unique_filename_callback($dir, $name, $ext) {
        $user = get_user_by('id', (int) $this->uid_being_edited);
        $name = $base_name = sanitize_file_name($user->display_name.'_reward_'.time());

        $number = 1;
        while (file_exists($dir."/$name$ext")) {
            $name = $base_name.'_'.$number;
            $number++;
        }

        return $name.$ext;
    }

    public function reward_delete($uid) {
        $rewards = (array) get_user_meta($uid, 'wp_user_reward', true);
        if (empty($rewards))
            return;

        $this->unset_or_delete_qrcode($rewards['wechat']);
        $this->unset_or_delete_qrcode($rewards['alipay']);

        delete_user_meta($uid, 'wp_user_reward');
    }

    private function unset_or_delete_qrcode(&$meta) {
        if (empty($meta))
            return;

        // unset qrcode info which uploaded to media library
        if (array_key_exists('media_id', $meta))
            unset($meta['media_id'], $meta['full']);

        // delete qrcode image file which uploaded directly
        if (!empty($meta)) {
            $upload_path = wp_upload_dir();
            foreach ($meta as $qrcode) {
                $qrcode_path = str_replace($upload_path['baseurl'], $upload_path['basedir'], $qrcode);
                if (file_exists($qrcode_path))
                    unlink($qrcode_path);
            }
        }
    }

    public function user_edit_form_tag() {
        echo 'enctype="multipart/form-data"';
    }

    public function append_rewards($content) {
        if (is_single()) {
            $uid = get_the_author_meta('ID');
            $wechat_qrcode = $this->get_user_reward_qrcode('wechat', $uid);
            $alipay_qrcode = $this->get_user_reward_qrcode('alipay', $uid);
            $paypal_link = $this->get_user_reward('paypal', $uid);
            $has_wechat = !empty($wechat_qrcode);
            $has_alipay = !empty($alipay_qrcode);
            $has_paypal = !empty($paypal_link);
            if ($has_wechat || $has_alipay || $has_paypal) {
                $html = '<div class="wp-user-rewards-author">'
                        .get_avatar($uid, 96)
                        .'<span class="wp-user-rewards-author-name">'.get_the_author_meta('display_name').'</span>'
                        .'<span class="wp-user-rewards-author-description">'.nl2br(get_the_author_meta('description')).'</span>'
                    .'</div>'
                    .'<div class="wp-user-rewards">';
                if ($has_wechat)
                    $html .= '<div class="wp-user-rewards-wechat">'
                        .'<span class="wp-user-rewards-wechat-title">'.__('Wechat pay', 'wp-user-rewards').'</span>'
                        .$wechat_qrcode
                    .'</div>';
                if ($has_alipay)
                    $html .= '<div class="wp-user-rewards-alipay">'
                        .'<span class="wp-user-rewards-alipay-title">'.__('Alipay', 'wp-user-rewards').'</span>'
                        .$alipay_qrcode
                    .'</div>';
                if ($has_paypal)
                    $html .= '<div class="wp-user-rewards-paypal">'
                        .'<span class="wp-user-rewards-paypal-title">'.__('PayPal Link', 'wp-user-rewards').'</span>'
                        .'<div class="wp-user-rewards-paypal-link">'
                            .'<a target="_blank" href="'.$paypal_link.'">'.$paypal_link.'</a>'
                        .'</div>'
                    .'</div>';
                $html .= '</div>';
                $content .= '<link rel="stylesheet" type="text/css" media="screen" href="'
                    .plugins_url('css/style.css', __FILE__).'" />'
                    .'<span class="wp-user-rewards-title">'.__('Author Rewards', 'wp-user-rewards').'</span>'
                    .'<div class="wp-user-rewards-container" style="display: none;">'
                        .$html
                        .'<div style="clear: both"></div>'
                    .'</div>'
                    .'<script type="text/javascript" src="'.plugins_url('js/toggle-rewards.js', __FILE__).'"></script>';
            }
        }
        return $content;
    }

}

$wp_user_rewards = new WP_User_Rewards;

register_uninstall_hook(__FILE__, 'wp_user_rewards_uninstall');

function wp_user_rewards_uninstall() {
    $wp_user_rewards = new WP_User_Rewards;
    $users = get_users(array(
        'meta_key' => 'wp_user_reward',
        'fields'   => 'ids'
    ));
    foreach ($users as $uid) {
        $wp_user_rewards->reward_delete($uid);
    }
}
?>
