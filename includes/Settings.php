<?php
namespace CrystalTap;
defined('ABSPATH') || exit;

final class Settings {
    public static function defaults(): array {
        return ['business_name' => 'CrystalTap', 'bot_name' => 'CrystalTap Assistant', 'welcome' => 'Hello po! How can we help you today?', 'suggestions' => ['Help me choose a purifier', 'Delivery and installation', 'Talk to a person'], 'color' => '#087f83', 'position' => 'right', 'offset' => 20, 'mobile_offset' => 16, 'width' => 380, 'height' => 580, 'logo' => '', 'launcher_icon' => '', 'disabled_pages' => [], 'widget_enabled' => true, 'auto_greeting' => false, 'summarization_enabled' => true, 'streaming_enabled' => false, 'ai_enabled' => false, 'model' => 'gpt-4.1-mini', 'reasoning' => '', 'max_output_tokens' => 600, 'context_messages' => 16, 'retry_limit' => 1, 'timeout' => 35, 'daily_budget' => 5, 'monthly_budget' => 75, 'input_price' => 0.4, 'output_price' => 1.6, 'session_hours' => 72, 'retention_days' => 90, 'session_limit' => 100, 'poll_seconds' => 5, 'followup_minutes' => 5, 'support_hours' => '', 'contact' => '', 'instructions' => '', 'privacy_notice' => 'Messages are stored to provide customer support. Relevant messages may be sent to OpenAI when AI assistance is enabled. Contact us to request access or deletion. Please do not send payment details or sensitive personal information.', 'vapid_public' => '', 'vapid_subject' => '', 'quiet_start' => '', 'quiet_end' => '', 'whatsapp_number' => '', 'messenger_url' => '', 'viber_number' => '', 'viber_url' => ''];
    }
    public static function get(): array { return apply_filters('ctai_settings', array_replace(self::defaults(), (array) get_option('ctai_settings', []))); }
    public static function secret(string $name): string {
        $constant = 'CTAI_' . strtoupper($name);
        if (defined($constant)) { return (string) constant($constant); }
        $value = getenv($constant);
        if ($value !== false && $value !== '') { return $value; }
        $stored = (array) get_option('ctai_secret_store', []);
        if (empty($stored[$name])) { return ''; }
        try { return Push::decrypt($stored[$name]); } catch (\Throwable $e) { return ''; }
    }
    public static function storeSecret(string $name, string $value): void {
        if (!in_array($name, ['openai_key','vapid_private','firebase_service','firebase_android'], true)) { throw new Fault('Unsupported credential'); }
        $stored = (array) get_option('ctai_secret_store', []);
        if ($value === '') { unset($stored[$name]); } else { $stored[$name] = Push::encrypt($value); }
        update_option('ctai_secret_store', $stored, false);
        DB::audit('credential_updated');
    }
    public static function publicConfig(): array {
        $settings = self::get();
        return array_intersect_key($settings, array_flip(['business_name','bot_name','welcome','suggestions','color','position','offset','mobile_offset','width','height','logo','launcher_icon','privacy_notice','poll_seconds','streaming_enabled'])) + ['channels' => Channels::links($settings)];
    }
    public static function save(array $data): array {
        $settings = self::get();
        foreach (self::defaults() as $key => $old) {
            if (!array_key_exists($key, $data)) { continue; }
            if (is_bool($old)) { $settings[$key] = (bool) $data[$key]; }
            elseif (is_array($old)) { $settings[$key] = array_slice(array_map('sanitize_text_field', (array) $data[$key]), 0, 30); }
            elseif (is_numeric($old)) { $settings[$key] = max(0, (float) $data[$key]); }
            else { $settings[$key] = sanitize_textarea_field((string) $data[$key]); }
        }
        foreach (['max_output_tokens' => [64, 4096], 'context_messages' => [2, 30], 'retry_limit' => [0, 2], 'timeout' => [5, 90], 'session_hours' => [1, 720], 'retention_days' => [1, 3650], 'poll_seconds' => [3, 60], 'followup_minutes' => [1, 1440], 'session_limit' => [1, 1000], 'width' => [300, 600], 'height' => [350, 850]] as $key => [$min, $max]) { $settings[$key] = min($max, max($min, (int) $settings[$key])); }
        $settings['color'] = sanitize_hex_color($settings['color']) ?: '#087f83';
        $icon = esc_url_raw($settings['launcher_icon'], ['https','http']);
        $settings['launcher_icon'] = wp_parse_url($icon,PHP_URL_SCHEME)==='https' || in_array(wp_parse_url($icon,PHP_URL_HOST),['localhost','127.0.0.1','::1'],true) ? $icon : '';
        $settings['logo'] = esc_url_raw($settings['logo'], ['https']);
        $settings['position'] = $settings['position'] === 'left' ? 'left' : 'right';
        $settings = Channels::validate($settings);
        if (!preg_match('/^[a-zA-Z0-9._:-]{1,100}$/', $settings['model'])) { throw new \InvalidArgumentException('Invalid model identifier'); }
        if (!in_array($settings['reasoning'], ['', 'low', 'medium', 'high'], true)) { throw new \InvalidArgumentException('Unsupported reasoning setting'); }
        if ($settings['reasoning'] && !preg_match('/^(o[134]|gpt-5)/', $settings['model'])) { throw new \InvalidArgumentException('Reasoning is only available for configured reasoning model families'); }
        update_option('ctai_settings', $settings, false);
        DB::audit('settings_updated');
        return $settings;
    }
}
