<?php
namespace CrystalTap;
defined('ABSPATH') || exit;

/** Public contact destinations only. No third-party SDKs, tokens or message forwarding. */
final class Channels {
    public static function phone(string $value, string $label): string {
        $value = trim($value);
        if ($value === '') { return ''; }
        if (!preg_match('/^\+?[0-9 ()-]+$/', $value)) { throw new Fault($label . ' must be an international number with country code.'); }
        $number = preg_replace('/[^0-9]/', '', $value);
        if (!preg_match('/^[1-9][0-9]{6,14}$/', $number)) { throw new Fault($label . ' must contain 7–15 digits including the country code, without a leading zero.'); }
        return $number;
    }
    public static function messenger(string $value): string {
        $value = trim($value);
        if ($value === '') { return ''; }
        $parts = wp_parse_url($value);
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || strtolower($parts['host'] ?? '') !== 'm.me' || isset($parts['pass']) || isset($parts['user']) || isset($parts['port']) || isset($parts['fragment']) || !preg_match('~^/([A-Za-z0-9.]{1,100})/?$~', $parts['path'] ?? '', $match)) { throw new Fault('Use your Messenger link in the form https://m.me/YourPage.'); }
        return 'https://m.me/' . $match[1];
    }
    public static function viber(string $value): string {
        $value = trim($value);
        if ($value === '') { return ''; }
        $parts = wp_parse_url($value);
        if (!$parts || strlen($value) > 1000 || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['fragment']) || preg_match('/[\x00-\x20]/', $value)) { throw new Fault('Use an official Viber share link or viber://chat?number= link.'); }
        if (($parts['scheme'] ?? '') === 'https' && in_array(strtolower($parts['host'] ?? ''), ['vb.me','invite.viber.com'], true)) {
            return esc_url_raw('https://' . strtolower($parts['host']) . ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : ''), ['https']);
        }
        if (($parts['scheme'] ?? '') === 'viber' && empty($parts['path'])) {
            parse_str($parts['query'] ?? '', $query);
            if (($parts['host'] ?? '') === 'chat' && count($query) === 1 && is_string($query['number'] ?? null)) { $number = self::phone($query['number'], 'Viber number'); if ($number) { return 'viber://chat?number=%2B' . $number; } }
            if (($parts['host'] ?? '') === 'pa' && count($query) === 1 && is_string($query['chatURI'] ?? null) && preg_match('/^[A-Za-z0-9_.-]{1,100}$/', $query['chatURI'])) { return 'viber://pa?chatURI=' . rawurlencode($query['chatURI']); }
        }
        throw new Fault('Use an official Viber share link or viber://chat?number= link.');
    }
    public static function validate(array $settings): array {
        $settings['whatsapp_number'] = self::phone((string) $settings['whatsapp_number'], 'WhatsApp number');
        $settings['messenger_url'] = self::messenger((string) $settings['messenger_url']);
        $settings['viber_number'] = self::phone((string) $settings['viber_number'], 'Viber number');
        $settings['viber_url'] = self::viber((string) $settings['viber_url']);
        return $settings;
    }
    public static function links(array $settings): array {
        $links = [];
        // Also validate on read: old options or filters must not publish unsafe destinations.
        foreach (['whatsapp','messenger','viber'] as $channel) {
            try {
                $url = match ($channel) {
                    'whatsapp' => ($number = self::phone((string) ($settings['whatsapp_number'] ?? ''), 'WhatsApp number')) ? 'https://wa.me/' . $number : '',
                    'messenger' => self::messenger((string) ($settings['messenger_url'] ?? '')),
                    'viber' => !empty($settings['viber_url']) ? self::viber((string) $settings['viber_url']) : (($number = self::phone((string) ($settings['viber_number'] ?? ''), 'Viber number')) ? 'viber://chat?number=%2B' . $number : ''),
                };
                if ($url) { $links[] = ['channel' => $channel, 'label' => ['whatsapp'=>'WhatsApp','messenger'=>'Messenger','viber'=>'Viber'][$channel], 'url' => $url]; }
            } catch (Fault $e) { /* An invalid destination is hidden; other channels remain usable. */ }
        }
        return $links;
    }
}
