<?php
namespace CrystalTap;
defined('ABSPATH') || exit;

final class Plugin {
    public static function activate(bool $network = false): void {
        if ($network || is_multisite()) { wp_die('CrystalTap AI currently supports single-site WordPress installations.'); }
        DB::migrate();
        self::roles();
        self::rewrite();
        flush_rewrite_rules();
        if (!wp_next_scheduled('ctai_tick')) { wp_schedule_event(time() + 60, 'ctai_minute', 'ctai_tick'); }
        if (!wp_next_scheduled('ctai_cleanup')) { wp_schedule_event(time() + 3600, 'daily', 'ctai_cleanup'); }
    }
    public static function deactivate(): void {
        wp_clear_scheduled_hook('ctai_tick'); wp_clear_scheduled_hook('ctai_cleanup'); flush_rewrite_rules();
    }
    public static function roles(): void {
        $agent = ['read' => true, 'ctai_agent' => true];
        add_role('ctai_agent', 'CrystalTap Support Agent', $agent);
        add_role('ctai_supervisor', 'CrystalTap Supervisor', $agent + ['ctai_supervise' => true]);
        add_role('ctai_administrator', 'CrystalTap Administrator', $agent + ['ctai_supervise' => true, 'ctai_manage' => true]);
        get_role('ctai_administrator')?->add_cap('upload_files');
        $admin = get_role('administrator');
        if ($admin) { foreach (['ctai_agent','ctai_supervise','ctai_manage'] as $cap) { $admin->add_cap($cap); } }
    }
    public static function boot(): void {
        add_filter('cron_schedules', static function ($s) { $s['ctai_minute'] = ['interval' => 60, 'display' => 'Every minute']; return $s; });
        add_action('init', [self::class, 'rewrite']);
        add_action('init', [self::class, 'ensureSchedule']);
        add_action('init', static function () { if (get_option('ctai_schema') !== CTAI_VERSION) { DB::migrate(); } });
        add_action('rest_api_init', [API::class, 'register']);
        add_filter('rest_post_dispatch', static function ($response, $server, $request) {
            if (str_starts_with($request->get_route(), '/crystaltap-ai/v1/')) { $response->header('Cache-Control', 'no-store, private, max-age=0'); $response->header('Vary', 'Cookie, Origin, Authorization'); }
            return $response;
        }, 10, 3);
        add_action('ctai_tick', [Notifications::class, 'tick']);
        add_action('shutdown', [Notifications::class, 'shutdown'], PHP_INT_MAX-1);
        add_action('shutdown', [Realtime::class, 'shutdown'], PHP_INT_MAX);
        add_action('ctai_cleanup', [Privacy::class, 'cleanup']);
        add_filter('authenticate', static function ($result, $username, $password) {
            if (!$username || !$password) { return $result; }
            $user = get_user_by('login', $username) ?: get_user_by('email', $username);
            if (!$user || !user_can($user, 'ctai_agent')) { return $result; }
            try { Auth::rate('agent_login_ip', 30, 900); Auth::rate('agent_login_account', 20, 900, (string) $user->ID); }
            catch (Fault $e) { return new \WP_Error('ctai_login_limited', 'Too many sign-in attempts. Please wait before trying again.'); }
            return $result;
        }, 99, 3);
        add_action('template_redirect', [self::class, 'mobile'], 0);
        add_action('admin_menu', [self::class, 'menus']);
        add_action('admin_enqueue_scripts', static function ($hook) { if (str_contains($hook, 'ctai')) { self::appAssets(); } });
        add_action('wp_enqueue_scripts', static function () {
            $s = Settings::get();
            if (!$s['widget_enabled'] || is_admin() || in_array((string) get_queried_object_id(), $s['disabled_pages'], true)) { return; }
            self::widgetAssets();
        });
        add_action('wp_footer', static function () {
            if (wp_script_is('ctai-widget', 'enqueued')) { echo '<div id="ctai-floating" data-ctai-mode="floating"></div>'; }
        });
        add_shortcode('crystaltap_ai_chat', static function ($atts) {
            $atts = shortcode_atts(['mode' => 'embedded'], $atts);
            self::widgetAssets();
            return '<div class="ctai-embed" data-ctai-mode="' . ($atts['mode'] === 'floating' ? 'floating' : 'embedded') . '"></div>';
        });
        add_action('wp_ajax_ctai_bootstrap', static function () {
            try { Auth::agent(); nocache_headers(); wp_send_json(self::bootstrap()); }
            catch (\Throwable $e) { wp_send_json(['message' => 'Sign in again'], 403); }
        });
        add_filter('wp_privacy_personal_data_exporters', [Privacy::class, 'exporter']);
        add_filter('wp_privacy_personal_data_erasers', [Privacy::class, 'eraser']);
        add_action('admin_init', static function () { if (function_exists('wp_add_privacy_policy_content')) { wp_add_privacy_policy_content('CrystalTap AI', wpautop(esc_html(Settings::get()['privacy_notice']))); } });
    }
    public static function ensureSchedule(): void {
        // Updates do not always run the activation hook. Repair missing events only.
        if (!wp_next_scheduled('ctai_tick')) { wp_schedule_event(time() + 60, 'ctai_minute', 'ctai_tick'); }
        if (!wp_next_scheduled('ctai_cleanup')) { wp_schedule_event(time() + 3600, 'daily', 'ctai_cleanup'); }
    }
    public static function rewrite(): void { add_rewrite_rule('^crystaltap-agent(?:/(.*))?/?$', 'index.php?ctai_app=1', 'top'); add_rewrite_tag('%ctai_app%', '([01])'); }
    public static function bootstrap(): array {
        return ['api' => rest_url('crystaltap-ai/v1/'), 'media' => rest_url('wp/v2/media'), 'nonce' => wp_create_nonce('wp_rest'), 'ajax' => admin_url('admin-ajax.php?action=ctai_bootstrap'), 'app' => home_url('/crystaltap-agent/'), 'assets' => plugins_url('assets/', CTAI_FILE), 'logout' => wp_logout_url(home_url('/crystaltap-agent/')), 'user' => ['id' => get_current_user_id(), 'name' => wp_get_current_user()->display_name, 'supervise' => current_user_can('ctai_supervise'), 'manage' => current_user_can('ctai_manage')], 'poll' => Settings::get()['poll_seconds']];
    }
    private static function appAssets(): void {
        wp_enqueue_style('ctai-app', plugins_url('assets/app.css', CTAI_FILE), [], CTAI_VERSION);
        wp_enqueue_script('ctai-realtime',plugins_url('assets/realtime.js',CTAI_FILE),[],CTAI_VERSION,true);
        wp_enqueue_script('ctai-chat-ui', plugins_url('assets/chat-ui.js', CTAI_FILE), [], CTAI_VERSION, true);
        wp_enqueue_script('ctai-app', plugins_url('assets/app.js', CTAI_FILE), ['ctai-chat-ui','ctai-realtime','ctai-realtime-settings'], CTAI_VERSION, true);
        wp_enqueue_script('ctai-realtime-settings',plugins_url('assets/realtime-settings.js',CTAI_FILE),[],CTAI_VERSION,true);
        wp_add_inline_script('ctai-app', 'window.CTAI_APP=' . wp_json_encode(self::bootstrap(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';', 'before');
    }
    private static function widgetAssets(): void {
        wp_enqueue_style('ctai-widget', plugins_url('assets/widget.css', CTAI_FILE), [], CTAI_VERSION);
        wp_enqueue_script('ctai-realtime',plugins_url('assets/realtime.js',CTAI_FILE),[],CTAI_VERSION,true);
        wp_enqueue_script('ctai-chat-ui', plugins_url('assets/chat-ui.js', CTAI_FILE), [], CTAI_VERSION, true);
        wp_enqueue_script('ctai-widget', plugins_url('assets/widget.js', CTAI_FILE), ['ctai-chat-ui','ctai-realtime'], CTAI_VERSION, true);
        wp_add_inline_script('ctai-widget', 'window.CTAI_WIDGET=' . wp_json_encode(['api' => rest_url('crystaltap-ai/v1/'), 'config' => Settings::publicConfig(), 'autoGreeting' => Settings::get()['auto_greeting']], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';', 'before');
    }
    public static function menus(): void {
        add_menu_page('CrystalTap AI', 'CrystalTap AI', 'ctai_agent', 'ctai', [self::class, 'admin'], 'dashicons-format-chat', 30);
        foreach (['dashboard' => 'Dashboard','conversations' => 'Conversations','agents' => 'Live Agents','leads' => 'Leads','products' => 'Products','knowledge' => 'Knowledge Base','ai' => 'AI Configuration','widget' => 'Chat Widget','notifications' => 'Notifications','analytics' => 'Analytics','business' => 'Business Information','settings' => 'Settings','logs' => 'Logs'] as $key => $label) {
            $cap = in_array($key, ['dashboard','conversations','leads','notifications'], true) ? 'ctai_agent' : (in_array($key, ['agents','analytics'], true) ? 'ctai_supervise' : 'ctai_manage');
            add_submenu_page('ctai', $label, $label, $cap, 'ctai-' . $key, [self::class, 'admin']);
        }
    }
    public static function admin(): void { echo '<div class="wrap"><div id="ctai-app" class="ctai-app"></div></div>'; }
    public static function mobile(): void {
        if (!get_query_var('ctai_app')) { return; }
        if (!is_ssl() && !in_array(wp_parse_url(home_url(), PHP_URL_HOST), ['localhost','127.0.0.1','::1'], true)) { wp_die('HTTPS is required for CrystalTap Agent.', 'HTTPS required', ['response' => 503]); }
        $path = trim((string) ($GLOBALS['wp']->request ?? ''), '/');
        $route = substr($path, strlen('crystaltap-agent'));
        if ($route === '/sw.js') {
            nocache_headers();header('Content-Type: application/javascript; charset=utf-8');readfile(CTAI_DIR.'mobile/sw.js');exit;
        }
        if ($route === '/manifest.webmanifest') {status_header(410);nocache_headers();header('Content-Type: application/json');echo wp_json_encode(['retired'=>true]);exit;}
        if (!is_user_logged_in()) { auth_redirect(); }
        if (!current_user_can('ctai_agent')) { wp_die('This account does not have CrystalTap Agent access.', 'Access denied', ['response' => 403]); }
        nocache_headers();
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: same-origin');
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="theme-color" content="#087f83"><title>CrystalTap Agent</title><link rel="stylesheet" href="' . esc_url(plugins_url('assets/app.css?ver=' . CTAI_VERSION, CTAI_FILE)) . '"></head><body><div id="ctai-app" class="ctai-app ctai-mobile"></div><script>window.CTAI_APP=' . wp_json_encode(self::bootstrap(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';</script><script src="' . esc_url(plugins_url('assets/chat-ui.js?ver=' . CTAI_VERSION, CTAI_FILE)) . '"></script><script src="' . esc_url(plugins_url('assets/realtime.js?ver=' . CTAI_VERSION, CTAI_FILE)) . '"></script><script src="' . esc_url(plugins_url('assets/realtime-settings.js?ver=' . CTAI_VERSION, CTAI_FILE)) . '"></script><script src="' . esc_url(plugins_url('assets/app.js?ver=' . CTAI_VERSION, CTAI_FILE)) . '"></script></body></html>';
        exit;
    }
}
