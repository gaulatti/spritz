<?php

/**
 * Spritz Auth — Google OAuth via OpenID Connect.
 *
 * Configures the daggerhart-openid-connect-generic plugin
 * for Google sign-in on the WordPress admin.
 */

add_action('init', 'spritz_configure_oidc', 100);

function spritz_configure_oidc() {
    $client_id = defined('GOOGLE_CLIENT_ID') ? GOOGLE_CLIENT_ID : getenv('GOOGLE_CLIENT_ID');
    $client_secret = defined('GOOGLE_CLIENT_SECRET') ? GOOGLE_CLIENT_SECRET : getenv('GOOGLE_CLIENT_SECRET');
    $allowed_domain = defined('GOOGLE_ALLOWED_DOMAIN') ? GOOGLE_ALLOWED_DOMAIN : getenv('GOOGLE_ALLOWED_DOMAIN');

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

    if ($allowed_domain) {
        add_filter('openid-connect-generic-auth-url', function ($url) {
            return add_query_arg('hd', GOOGLE_ALLOWED_DOMAIN, $url);
        });
    }

    add_action('openid-connect-generic-user-create', function ($user, $user_claim) use ($allowed_domain) {
        if ($allowed_domain) {
            $domain = strtolower(trim($allowed_domain));
            $email_domain = strtolower(trim(substr(strrchr($user_claim->email, '@'), 1)));
            if ($email_domain !== $domain) {
                wp_die('Access restricted to @' . esc_html($domain) . ' accounts.');
            }
        }
        if (!empty($user_claim->email)) {
            wp_update_user([
                'ID'         => $user->ID,
                'user_email' => sanitize_email($user_claim->email),
            ]);
        }
    }, 10, 2);
}

add_action('login_enqueue_scripts', 'spritz_oidc_login_button');
add_action('login_form', 'spritz_oidc_login_button');

function spritz_oidc_login_button() {
    $client_id = defined('GOOGLE_CLIENT_ID') ? GOOGLE_CLIENT_ID : getenv('GOOGLE_CLIENT_ID');
    if (!$client_id) return;

    $provider_url = 'https://accounts.google.com/o/oauth2/v2/auth';
    $site_url = get_site_url();
    $redirect = $site_url . '/wp-admin/admin-ajax.php?action=openid-connect-authorize';
    $state = wp_create_nonce('openid-connect-generic');

    $url = add_query_arg([
        'response_type' => 'code',
        'client_id'     => $client_id,
        'redirect_uri'  => $redirect,
        'scope'         => 'openid email profile',
        'state'         => $state,
        'nonce'         => $state,
    ], $provider_url);

    echo '<div style="text-align:center;margin:20px 0;">';
    echo '<a href="' . esc_url($url) . '" class="button button-primary button-large" style="background:#4285f4;border-color:#4285f4;color:#fff;padding:10px 24px;font-size:16px;text-decoration:none;display:inline-block;">';
    echo 'Sign in with Google';
    echo '</a>';
    echo '</div>';
}
