<?php
namespace CrystalTap;
defined('ABSPATH') || exit;

final class Push {
    public static function encrypt(string $plain): string {
        $iv = random_bytes(12); $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', hash('sha256', wp_salt('secure_auth'), true), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) { throw new \RuntimeException('Encryption failed'); }
        return base64_encode($iv . $tag . $cipher);
    }
    public static function decrypt(string $encoded): string {
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < 28) { throw new \RuntimeException('Invalid subscription'); }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', hash('sha256', wp_salt('secure_auth'), true), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        if ($plain === false) { throw new \RuntimeException('Subscription key changed; register the device again'); }
        return $plain;
    }
    public static function deliver(int $notification): void { NativePush::deliver($notification); }
}
