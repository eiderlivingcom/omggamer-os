<?php
/**
 * Plugin Name: OMGGamer log in / sign up popup
 * Description: "Log in or sign up" dialog opened from the header's Log in button (and any [data-omg-auth] link).
 *              Email + password log in and account creation through WooCommerce. Google sign-in and Cloudflare
 *              Turnstile switch on when their keys are defined in wp-config.php:
 *              OMG_GOOGLE_CLIENT_ID / OMG_GOOGLE_CLIENT_SECRET, OMG_TURNSTILE_SITE_KEY / OMG_TURNSTILE_SECRET.
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class OMG_Auth_Modal
{
    private const MAX_FAILS = 6;

    public static function init(): void
    {
        add_action('wp_footer', [__CLASS__, 'render'], 80);
        add_action('wp_ajax_nopriv_omg_auth_nonce', [__CLASS__, 'nonce']);
        add_action('wp_ajax_omg_auth_nonce', [__CLASS__, 'nonce']);
        add_action('wp_ajax_nopriv_omg_auth', [__CLASS__, 'submit']);
        add_action('wp_ajax_omg_auth', [__CLASS__, 'submit']);
        add_action('init', [__CLASS__, 'google_routes']);
    }

    private static function key(string $name): string
    {
        return defined($name) ? (string) constant($name) : '';
    }

    private static function google_on(): bool
    {
        return self::key('OMG_GOOGLE_CLIENT_ID') !== '' && self::key('OMG_GOOGLE_CLIENT_SECRET') !== '';
    }

    private static function turnstile_on(): bool
    {
        return self::key('OMG_TURNSTILE_SITE_KEY') !== '' && self::key('OMG_TURNSTILE_SECRET') !== '';
    }

    private static function ip(): string
    {
        return (string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
    }

    public static function nonce(): void
    {
        nocache_headers();
        wp_send_json_success(['nonce' => wp_create_nonce('omg-auth')]);
    }

    private static function fail(string $msg, string $field = ''): void
    {
        wp_send_json_error(['message' => $msg, 'field' => $field], 200);
    }

    private static function turnstile_ok(): bool
    {
        if (!self::turnstile_on()) {
            return true;
        }
        $token = sanitize_text_field(wp_unslash($_POST['cf-turnstile-response'] ?? ''));
        if ($token === '') {
            return false;
        }
        $r = wp_remote_post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
            'timeout' => 10,
            'body' => ['secret' => self::key('OMG_TURNSTILE_SECRET'), 'response' => $token, 'remoteip' => self::ip()],
        ]);
        $j = is_wp_error($r) ? null : json_decode((string) wp_remote_retrieve_body($r), true);
        return !empty($j['success']);
    }

    public static function submit(): void
    {
        nocache_headers();
        if (!check_ajax_referer('omg-auth', 'nonce', false)) {
            self::fail('Your session expired. Please try again.');
        }
        if (is_user_logged_in()) {
            wp_send_json_success(['redirect' => '']);
        }
        $mode = ($_POST['mode'] ?? '') === 'register' ? 'register' : 'login';
        $email = sanitize_text_field(wp_unslash($_POST['email'] ?? ''));
        $pass = (string) wp_unslash($_POST['password'] ?? '');
        $tkey = 'omg_auth_f_' . md5(self::ip());
        $fails = (int) get_transient($tkey);
        if ($fails >= self::MAX_FAILS) {
            self::fail('Too many attempts. Please wait 15 minutes and try again.');
        }
        if ($email === '') {
            self::fail('Enter your email.', 'email');
        }
        if ($pass === '') {
            self::fail('Enter your password.', 'password');
        }
        if (!self::turnstile_ok()) {
            self::fail('Please complete the security check.');
        }

        if ($mode === 'register') {
            if ('yes' !== get_option('woocommerce_enable_myaccount_registration')) {
                self::fail('Sign-ups are closed right now.');
            }
            if (!is_email($email)) {
                self::fail('Enter a valid email address.', 'email');
            }
            if (strlen($pass) < 8) {
                self::fail('Use at least 8 characters for your password.', 'password');
            }
            if (email_exists($email)) {
                self::fail('There is already an account with this email. Log in instead.', 'email');
            }
            if (!function_exists('wc_create_new_customer')) {
                self::fail('Sign-ups are not available right now.');
            }
            $id = wc_create_new_customer($email, '', $pass);
            if (is_wp_error($id)) {
                self::fail(wp_strip_all_tags($id->get_error_message()));
            }
            wc_set_customer_auth_cookie($id);
            wp_send_json_success(['redirect' => '']);
        }

        $user = wp_signon(['user_login' => $email, 'user_password' => $pass, 'remember' => true], is_ssl());
        if (is_wp_error($user)) {
            set_transient($tkey, $fails + 1, 15 * MINUTE_IN_SECONDS);
            self::fail('That email and password do not match.', 'password');
        }
        delete_transient($tkey);
        wp_send_json_success(['redirect' => '']);
    }

    /* ---------- Google sign-in (OAuth 2.0 authorization code flow) ---------- */

    private static function google_redirect_uri(): string
    {
        return add_query_arg('omg_google', 'cb', home_url('/'));
    }

    public static function google_routes(): void
    {
        $g = $_GET['omg_google'] ?? '';
        if ($g === '' || !self::google_on()) {
            return;
        }
        nocache_headers();
        if ($g === 'start') {
            $back = wp_validate_redirect(esc_url_raw(wp_unslash($_GET['back'] ?? '')), home_url('/'));
            $state = wp_generate_password(32, false);
            set_transient('omg_g_' . $state, $back, 10 * MINUTE_IN_SECONDS);
            setcookie('omg_g_state', $state, ['expires' => time() + 600, 'path' => COOKIEPATH ?: '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax']);
            wp_redirect(add_query_arg([
                'client_id' => self::key('OMG_GOOGLE_CLIENT_ID'),
                'redirect_uri' => self::google_redirect_uri(),
                'response_type' => 'code',
                'scope' => 'openid email profile',
                'state' => $state,
                'prompt' => 'select_account',
            ], 'https://accounts.google.com/o/oauth2/v2/auth'));
            exit;
        }
        if ($g !== 'cb') {
            return;
        }
        $state = sanitize_text_field(wp_unslash($_GET['state'] ?? ''));
        $back = $state !== '' ? get_transient('omg_g_' . $state) : false;
        $fail = static function (string $why) {
            wp_safe_redirect(add_query_arg('omg_auth_error', rawurlencode($why), home_url('/')));
            exit;
        };
        if (!$back || !hash_equals((string) ($_COOKIE['omg_g_state'] ?? ''), $state)) {
            $fail('Google sign-in expired. Please try again.');
        }
        delete_transient('omg_g_' . $state);
        $code = sanitize_text_field(wp_unslash($_GET['code'] ?? ''));
        if ($code === '') {
            $fail('Google sign-in was cancelled.');
        }
        $r = wp_remote_post('https://oauth2.googleapis.com/token', ['timeout' => 15, 'body' => [
            'code' => $code,
            'client_id' => self::key('OMG_GOOGLE_CLIENT_ID'),
            'client_secret' => self::key('OMG_GOOGLE_CLIENT_SECRET'),
            'redirect_uri' => self::google_redirect_uri(),
            'grant_type' => 'authorization_code',
        ]]);
        $tok = is_wp_error($r) ? null : json_decode((string) wp_remote_retrieve_body($r), true);
        if (empty($tok['id_token'])) {
            $fail('Google sign-in failed. Please try again.');
        }
        $r = wp_remote_get(add_query_arg('id_token', rawurlencode($tok['id_token']), 'https://oauth2.googleapis.com/tokeninfo'), ['timeout' => 15]);
        $info = is_wp_error($r) ? null : json_decode((string) wp_remote_retrieve_body($r), true);
        if (empty($info['email']) || ($info['aud'] ?? '') !== self::key('OMG_GOOGLE_CLIENT_ID') || !in_array($info['email_verified'] ?? '', [true, 'true'], true)) {
            $fail('Google could not confirm your email.');
        }
        $email = sanitize_email($info['email']);
        $user = get_user_by('email', $email);
        if (!$user) {
            if ('yes' !== get_option('woocommerce_enable_myaccount_registration') || !function_exists('wc_create_new_customer')) {
                $fail('Sign-ups are closed right now.');
            }
            $id = wc_create_new_customer($email, '', wp_generate_password(24), [
                'first_name' => sanitize_text_field($info['given_name'] ?? ''),
                'last_name' => sanitize_text_field($info['family_name'] ?? ''),
            ]);
            if (is_wp_error($id)) {
                $fail(wp_strip_all_tags($id->get_error_message()));
            }
            $user = get_user_by('id', $id);
        }
        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID, true, is_ssl());
        do_action('wp_login', $user->user_login, $user);
        wp_safe_redirect($back);
        exit;
    }

    /* ---------- Front end ---------- */

    public static function render(): void
    {
        if (is_admin() || is_user_logged_in() || (function_exists('bricks_is_builder') && bricks_is_builder())) {
            return;
        }
        $terms = function_exists('wc_terms_and_conditions_page_id') && wc_terms_and_conditions_page_id() ? get_permalink(wc_terms_and_conditions_page_id()) : '';
        $privacy = get_privacy_policy_url();
        $cfg = [
            'ajax' => admin_url('admin-ajax.php'),
            'google' => self::google_on() ? add_query_arg('omg_google', 'start', home_url('/')) : '',
            'turnstile' => self::turnstile_on() ? self::key('OMG_TURNSTILE_SITE_KEY') : '',
            'register' => 'yes' === get_option('woocommerce_enable_myaccount_registration'),
            'lost' => function_exists('wc_lostpassword_url') ? wc_lostpassword_url() : wp_lostpassword_url(),
            'account' => function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : home_url('/my-account/'),
            'error' => isset($_GET['omg_auth_error']) ? sanitize_text_field(wp_unslash($_GET['omg_auth_error'])) : '',
        ];
        ?>
<style id="omg-auth-css">
.omg-au-ov{position:fixed;inset:0;z-index:100001;display:flex;align-items:center;justify-content:center;padding:16px;background:rgba(5,4,10,.72);backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px)}
.omg-au-ov[hidden]{display:none}
.omg-au{position:relative;box-sizing:border-box;width:440px;max-width:100%;max-height:calc(100vh - 32px);overflow:auto;padding:32px;background:#13111e;border:1px solid #2a2540;border-radius:28px;box-shadow:0 40px 80px -20px rgba(0,0,0,.8);color:#f4f2fb;font:500 14px/1.4 Poppins,system-ui,sans-serif}
.omg-au *{box-sizing:border-box}
.omg-au-x{position:absolute;top:18px;right:18px;display:inline-flex;align-items:center;justify-content:center;width:38px;height:38px;border:0;border-radius:10px;background:transparent;color:#f4f2fb;cursor:pointer}
.omg-au-x:hover{background:#1e1a2e}
.omg-au h2{margin:16px 0 20px;font:700 26px/1.2 Poppins,system-ui,sans-serif;color:#f4f2fb}
.omg-au-g{display:flex;align-items:center;justify-content:center;gap:10px;width:100%;height:46px;border:1px solid #2a2540;border-radius:12px;background:#1e1a2e;color:#f4f2fb;font:600 14px Poppins,system-ui,sans-serif;text-decoration:none}
.omg-au-g:hover{border-color:#4b3f78;color:#fff}
.omg-au-or{display:flex;align-items:center;gap:12px;margin:18px 0 6px;color:#77738c;font-size:13px}
.omg-au-or::before,.omg-au-or::after{content:"";flex:1;height:1px;background:#2a2540}
.omg-au label{display:block;margin:14px 0 8px;color:#a29fb6;font-size:13px;font-weight:500}
.omg-au-f{position:relative}
html body .omg-au input[type=email],html body .omg-au input[type=password],html body .omg-au input[type=text]{width:100%;height:48px;margin:0;padding:0 16px!important;border:1px solid #2a2540!important;border-radius:12px!important;background:#1e1a2e!important;color:#f4f2fb!important;font:500 15px Poppins,system-ui,sans-serif;outline:none;box-shadow:none}
html body .omg-au .omg-au-f input{padding-right:50px!important}
html body .omg-au input:focus{border-color:#8b5cf6!important;box-shadow:0 0 0 3px rgba(139,92,246,.25)!important}
html body .omg-au input[aria-invalid=true]{border-color:#f43f5e!important}
.omg-au-eye{position:absolute;right:8px;top:50%;transform:translateY(-50%);display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border:0;border-radius:8px;background:transparent;color:#a29fb6;cursor:pointer}
.omg-au-eye:hover{color:#f4f2fb}
.omg-au-row{display:flex;justify-content:flex-end;margin-top:10px}
.omg-au a.omg-au-link,.omg-au button.omg-au-link{border:0;background:none;padding:0;color:#b79cff;font:600 13px Poppins,system-ui,sans-serif;text-decoration:none;cursor:pointer}
.omg-au a.omg-au-link:hover,.omg-au button.omg-au-link:hover{color:#d4c5ff;text-decoration:underline}
.omg-au-ts{display:flex;justify-content:center;margin-top:16px;min-height:0}
.omg-au-err{margin:14px 0 0;padding:10px 12px;border-radius:10px;background:rgba(244,63,94,.12);border:1px solid rgba(244,63,94,.35);color:#fda4af;font-size:13px}
.omg-au-err[hidden]{display:none}
.omg-au-go{display:flex;align-items:center;justify-content:center;width:100%;height:50px;margin-top:18px;border:0;border-radius:14px;background:linear-gradient(120deg,#7C3AED,#C026D3 52%,#E0115F);color:#fff;font:700 16px Poppins,system-ui,sans-serif;cursor:pointer;box-shadow:0 12px 28px -14px rgba(192,38,211,.8)}
.omg-au-go:hover{filter:brightness(1.08)}
.omg-au-go[disabled]{opacity:.6;cursor:wait}
.omg-au-sw{margin-top:12px;text-align:right}
.omg-au-legal{margin:22px 0 0;text-align:center;color:#77738c;font-size:12px}
.omg-au-legal a{color:#f4f2fb;font-weight:600;text-decoration:underline}
.omg-au-hint{margin:6px 0 0;color:#77738c;font-size:12px}
.omg-au :focus-visible{outline:2px solid #b79cff;outline-offset:2px}
@media (max-width:520px){.omg-au-ov{align-items:flex-end;padding:0}.omg-au{border-radius:24px 24px 0 0;padding:24px 20px;max-height:94vh}.omg-au h2{font-size:22px}}
html.omg-au-lock,html.omg-au-lock body{overflow:hidden}
</style>
<script id="omg-auth-js">
(function(){
  var C = <?php echo wp_json_encode($cfg); ?>, ov, mode = 'login', opener, tsLoaded = false, tsId = null;
  var T = <?php echo wp_json_encode(['terms' => $terms, 'privacy' => $privacy]); ?>;
  var eye = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>';
  var eyeOff = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3l18 18M10.6 5.1A10.4 10.4 0 0 1 12 5c6.5 0 10 7 10 7a17 17 0 0 1-3.2 4.1M6.6 6.6C3.9 8.4 2 12 2 12s3.5 7 10 7c1.7 0 3.2-.5 4.5-1.2M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>';
  var gIcon = '<svg width="20" height="20" viewBox="0 0 48 48" aria-hidden="true"><path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3C33.7 32.7 29.2 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 13 4 4 13 4 24s9 20 20 20 20-9 20-20c0-1.3-.1-2.4-.4-3.5z"/><path fill="#FF3D00" d="M6.3 14.7l6.6 4.8C14.7 15.1 19 12 24 12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/><path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35.1 26.7 36 24 36c-5.2 0-9.6-3.3-11.3-7.9l-6.5 5C9.5 39.6 16.2 44 24 44z"/><path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.2-2.2 4.2-4.1 5.6l6.2 5.2C37 39.2 44 34 44 24c0-1.3-.1-2.4-.4-3.5z"/></svg>';
  function $(s){ return ov.querySelector(s); }
  function build(){
    ov = document.createElement('div'); ov.className = 'omg-au-ov'; ov.hidden = true;
    var legal = (T.terms || T.privacy) ? '<p class="omg-au-legal">By continuing, you agree to our ' + (T.terms ? '<a href="' + T.terms + '">Terms</a>' : 'Terms') + (T.privacy ? ' and <a href="' + T.privacy + '">Privacy Policy</a>' : '') + '.</p>' : '';
    ov.innerHTML = '<div class="omg-au" role="dialog" aria-modal="true" aria-labelledby="omg-au-title">'
      + '<button type="button" class="omg-au-x" aria-label="Close"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M6 6l12 12M18 6 6 18"/></svg></button>'
      + '<h2 id="omg-au-title">Log in or sign up</h2>'
      + (C.google ? '<a class="omg-au-g" href="#">' + gIcon + '<span>Google</span></a><div class="omg-au-or">or continue with</div>' : '')
      + '<form class="omg-au-form" novalidate>'
      + '<label for="omg-au-email">Email</label><input id="omg-au-email" type="email" name="email" autocomplete="email" placeholder="you@example.com" required>'
      + '<label for="omg-au-pass">Password</label><div class="omg-au-f"><input id="omg-au-pass" type="password" name="password" autocomplete="current-password" placeholder="Your password" required><button type="button" class="omg-au-eye" aria-label="Show password" aria-pressed="false">' + eye + '</button></div>'
      + '<p class="omg-au-hint" hidden>At least 8 characters.</p>'
      + '<div class="omg-au-row omg-au-lostrow"><a class="omg-au-link" href="' + C.lost + '">Forgot password?</a></div>'
      + '<div class="omg-au-ts"></div>'
      + '<p class="omg-au-err" role="alert" hidden></p>'
      + '<button type="submit" class="omg-au-go">Log in</button>'
      + (C.register ? '<div class="omg-au-sw"><button type="button" class="omg-au-link omg-au-toggle">New here? Create an account</button></div>' : '')
      + '</form>' + legal + '</div>';
    document.body.appendChild(ov);
    ov.addEventListener('mousedown', function(e){ if (e.target === ov) close(); });
    $('.omg-au-x').addEventListener('click', close);
    var g = $('.omg-au-g'); if (g) g.addEventListener('click', function(e){ e.preventDefault(); location.href = C.google + '&back=' + encodeURIComponent(location.href.split('#')[0]); });
    $('.omg-au-eye').addEventListener('click', function(){ var i = $('#omg-au-pass'), show = i.type === 'password'; i.type = show ? 'text' : 'password'; this.innerHTML = show ? eyeOff : eye; this.setAttribute('aria-pressed', show); this.setAttribute('aria-label', show ? 'Hide password' : 'Show password'); });
    var t = $('.omg-au-toggle'); if (t) t.addEventListener('click', function(){ setMode(mode === 'login' ? 'register' : 'login'); });
    $('.omg-au-form').addEventListener('submit', submit);
    document.addEventListener('keydown', function(e){
      if (ov.hidden) return;
      if (e.key === 'Escape') close();
      if (e.key === 'Tab') { var f = Array.prototype.filter.call(ov.querySelectorAll('a,button,input'), function(x){ return x.offsetParent !== null; }), a = f[0], z = f[f.length - 1]; if (e.shiftKey && document.activeElement === a) { e.preventDefault(); z.focus(); } else if (!e.shiftKey && document.activeElement === z) { e.preventDefault(); a.focus(); } }
    });
  }
  function setMode(m){
    mode = m; var reg = m === 'register';
    $('#omg-au-title').textContent = reg ? 'Create your account' : 'Log in or sign up';
    $('.omg-au-go').textContent = reg ? 'Create account' : 'Log in';
    $('.omg-au-lostrow').hidden = reg; $('.omg-au-hint').hidden = !reg;
    $('#omg-au-pass').setAttribute('autocomplete', reg ? 'new-password' : 'current-password');
    $('#omg-au-pass').placeholder = reg ? 'Create a password' : 'Your password';
    var t = $('.omg-au-toggle'); if (t) t.textContent = reg ? 'Already have an account? Log in' : 'New here? Create an account';
    err('');
  }
  function err(msg, field){
    var e = $('.omg-au-err'); e.textContent = msg; e.hidden = !msg;
    ['#omg-au-email', '#omg-au-pass'].forEach(function(s){ $(s).removeAttribute('aria-invalid'); });
    if (field) { var f = $(field === 'email' ? '#omg-au-email' : '#omg-au-pass'); f.setAttribute('aria-invalid', 'true'); f.focus(); }
  }
  function turnstile(){
    if (!C.turnstile || tsLoaded) return; tsLoaded = true;
    window.omgAuTs = function(){ tsId = window.turnstile.render($('.omg-au-ts'), {sitekey: C.turnstile, theme: 'dark'}); };
    var s = document.createElement('script'); s.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?onload=omgAuTs&render=explicit'; s.async = true; document.head.appendChild(s);
  }
  function post(data){
    var b = new URLSearchParams(); Object.keys(data).forEach(function(k){ b.append(k, data[k]); });
    return fetch(C.ajax, {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: b.toString()}).then(function(r){ return r.json(); });
  }
  function submit(e){
    e.preventDefault();
    var email = $('#omg-au-email').value.trim(), pass = $('#omg-au-pass').value;
    if (!email) return err('Enter your email.', 'email');
    if (mode === 'register' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) return err('Enter a valid email address.', 'email');
    if (!pass) return err('Enter your password.', 'password');
    if (mode === 'register' && pass.length < 8) return err('Use at least 8 characters for your password.', 'password');
    var btn = $('.omg-au-go'), label = btn.textContent; btn.disabled = true; btn.textContent = mode === 'register' ? 'Creating account…' : 'Logging in…'; err('');
    fetch(C.ajax + '?action=omg_auth_nonce', {credentials: 'same-origin'}).then(function(r){ return r.json(); }).then(function(n){
      var d = {action: 'omg_auth', nonce: n && n.data ? n.data.nonce : '', mode: mode, email: email, password: pass};
      var ts = ov.querySelector('[name="cf-turnstile-response"]'); if (ts) d['cf-turnstile-response'] = ts.value;
      return post(d);
    }).then(function(r){
      if (r && r.success) { btn.textContent = 'Done'; if (/\/my-account\/?/.test(location.pathname)) location.href = C.account; else location.reload(); return; }
      err(r && r.data && r.data.message ? r.data.message : 'Something went wrong. Please try again.', r && r.data ? r.data.field : '');
      if (tsId !== null && window.turnstile) window.turnstile.reset(tsId);
      btn.disabled = false; btn.textContent = label;
    }).catch(function(){ err('We could not reach the server. Check your connection and try again.'); btn.disabled = false; btn.textContent = label; });
  }
  function open(el, m){
    if (!ov) build(); opener = el || null; setMode(m || 'login'); ov.hidden = false; document.documentElement.classList.add('omg-au-lock'); turnstile();
    setTimeout(function(){ $('#omg-au-email').focus(); }, 30);
  }
  function close(){ if (!ov || ov.hidden) return; ov.hidden = true; document.documentElement.classList.remove('omg-au-lock'); if (opener) opener.focus(); }
  window.omgAuthOpen = open;
  document.addEventListener('click', function(e){
    var a = e.target.closest && e.target.closest('#omg-hdr-login,[data-omg-auth],a.omg-mob-login');
    if (!a || e.metaKey || e.ctrlKey || e.shiftKey) return;
    e.preventDefault(); open(a, a.getAttribute('data-omg-auth') === 'register' ? 'register' : 'login');
  });
  if (C.error) { var go = function(){ open(null); err(C.error); }; if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', go); else go(); }
})();
</script>
        <?php
    }
}

OMG_Auth_Modal::init();
