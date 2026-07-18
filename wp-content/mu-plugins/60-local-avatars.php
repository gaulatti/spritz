<?php

/**
 * Spritz Local Avatars.
 *
 * Lets editors upload a profile photo from the WordPress profile screen
 * and uses it anywhere WordPress asks for the user's avatar.
 */

add_action('show_user_profile', 'spritz_local_avatar_field');
add_action('edit_user_profile', 'spritz_local_avatar_field');
add_action('personal_options_update', 'spritz_save_local_avatar');
add_action('edit_user_profile_update', 'spritz_save_local_avatar');
add_filter('pre_get_avatar_data', 'spritz_use_local_avatar', 10, 2);
add_action('admin_enqueue_scripts', 'spritz_local_avatar_form_support');

function spritz_local_avatar_field($user) {
    if (!current_user_can('edit_user', $user->ID)) {
        return;
    }

    $avatar_id = (int) get_user_meta($user->ID, 'spritz_local_avatar_id', true);
    $avatar_url = $avatar_id ? wp_get_attachment_image_url($avatar_id, 'thumbnail') : '';
    ?>
    <h2>Profile Photo</h2>
    <table class="form-table" role="presentation">
        <tr>
            <th><label for="spritz_local_avatar">Profile photo</label></th>
            <td>
                <?php if ($avatar_url) : ?>
                    <p>
                        <img
                            src="<?php echo esc_url($avatar_url); ?>"
                            alt=""
                            style="width:96px;height:96px;object-fit:cover;border-radius:50%;"
                        />
                    </p>
                <?php endif; ?>
                <input type="file" id="spritz_local_avatar" name="spritz_local_avatar" accept="image/*" />
                <?php if ($avatar_id) : ?>
                    <p>
                        <label>
                            <input type="checkbox" name="spritz_remove_local_avatar" value="1" />
                            Remove profile photo
                        </label>
                    </p>
                <?php endif; ?>
                <p class="description">Upload a local profile photo instead of using Gravatar.</p>
            </td>
        </tr>
    </table>
    <?php
}

function spritz_save_local_avatar($user_id) {
    if (!current_user_can('edit_user', $user_id)) {
        return;
    }

    if (!empty($_POST['spritz_remove_local_avatar'])) {
        delete_user_meta($user_id, 'spritz_local_avatar_id');
        return;
    }

    if (empty($_FILES['spritz_local_avatar']['name'])) {
        return;
    }

    if (!function_exists('media_handle_upload')) {
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
    }

    $attachment_id = media_handle_upload('spritz_local_avatar', 0);

    if (is_wp_error($attachment_id)) {
        add_action('user_profile_update_errors', function ($errors) use ($attachment_id) {
            $errors->add('spritz_local_avatar_upload', $attachment_id->get_error_message());
        });
        return;
    }

    update_user_meta($user_id, 'spritz_local_avatar_id', (int) $attachment_id);
}

function spritz_use_local_avatar($args, $id_or_email) {
    $user = spritz_get_avatar_user($id_or_email);

    if (!$user) {
        return $args;
    }

    $avatar_id = (int) get_user_meta($user->ID, 'spritz_local_avatar_id', true);
    if (!$avatar_id) {
        return $args;
    }

    $size = isset($args['size']) ? (int) $args['size'] : 96;
    $url = wp_get_attachment_image_url($avatar_id, [$size, $size]);

    if (!$url) {
        $url = wp_get_attachment_image_url($avatar_id, 'thumbnail');
    }

    if ($url) {
        $args['url'] = $url;
        $args['found_avatar'] = true;
    }

    return $args;
}

function spritz_get_avatar_user($id_or_email) {
    if ($id_or_email instanceof WP_User) {
        return $id_or_email;
    }

    if ($id_or_email instanceof WP_Post) {
        return get_user_by('id', (int) $id_or_email->post_author);
    }

    if ($id_or_email instanceof WP_Comment) {
        if (!empty($id_or_email->user_id)) {
            return get_user_by('id', (int) $id_or_email->user_id);
        }

        return get_user_by('email', $id_or_email->comment_author_email);
    }

    if (is_numeric($id_or_email)) {
        return get_user_by('id', (int) $id_or_email);
    }

    if (is_string($id_or_email) && is_email($id_or_email)) {
        return get_user_by('email', $id_or_email);
    }

    return null;
}

function spritz_local_avatar_form_support($hook_suffix) {
    if ($hook_suffix !== 'profile.php' && $hook_suffix !== 'user-edit.php') {
        return;
    }

    wp_add_inline_script(
        'jquery-core',
        'document.addEventListener("DOMContentLoaded",function(){var form=document.getElementById("your-profile");if(form){form.setAttribute("enctype","multipart/form-data");}});'
    );
}
