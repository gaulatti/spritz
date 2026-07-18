<?php

/**
 * Spritz Auth — Google OAuth via OpenID Connect.
 *
 * Configures the daggerhart-openid-connect-generic plugin
 * for Google sign-in on the WordPress admin.
 */

add_action('init', 'spritz_configure_oidc', 100);
add_action('admin_init', 'spritz_restrict_admin_to_content_users');

function spritz_configure_oidc() {
    if (!function_exists('is_blog_installed') || !is_blog_installed()) {
        return;
    }

    $client_id = defined('GOOGLE_CLIENT_ID') ? GOOGLE_CLIENT_ID : getenv('GOOGLE_CLIENT_ID');
    $client_secret = defined('GOOGLE_CLIENT_SECRET') ? GOOGLE_CLIENT_SECRET : getenv('GOOGLE_CLIENT_SECRET');

    if (!$client_id || !$client_secret) return;

    $site_url = get_site_url();

    update_option('openid_connect_generic_settings', [
        'client_id'              => $client_id,
        'client_secret'          => $client_secret,
        'login_type'             => 'button',
        'endpoint_login'         => 'https://accounts.google.com/o/oauth2/v2/auth',
        'endpoint_token'         => 'https://oauth2.googleapis.com/token',
        'endpoint_userinfo'      => 'https://openidconnect.googleapis.com/v1/userinfo',
        'scope'                  => 'openid email profile',
        'redirect_uri'           => $site_url . '/wp-admin/admin-ajax.php?action=openid-connect-authorize',
        'token_refresh_enable'   => false,
        'link_existing_users'    => true,
        'create_if_does_not_exist' => true,
        'identify_with_username' => true,
        'http_request_timeout'   => 10,
        'nickname_key'           => 'given_name',
        'identity_key'           => 'sub',
        'email_format'           => '{{email}}',
        'displayname_format'     => '{{given_name}} {{family_name}}',
        'acr_values_supported'   => '',
        'enforce_privacy'        => false,
        'alternate_redirect_uri' => false,
    ]);

    add_action('openid-connect-generic-user-create', function ($user, $user_claim) {
        if (!empty($user_claim->email)) {
            wp_update_user([
                'ID'         => $user->ID,
                'user_email' => sanitize_email($user_claim->email),
            ]);
        }

        if (empty($user->roles)) {
            $user->set_role('subscriber');
        }
    }, 10, 2);
}

function spritz_restrict_admin_to_content_users() {
    if (
        wp_doing_ajax()
        || !is_user_logged_in()
        || current_user_can('edit_posts')
    ) {
        return;
    }

    wp_die('Your account can sign in, but it does not have CMS access yet. Ask an administrator to update your WordPress role.');
}
