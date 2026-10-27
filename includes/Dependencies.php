<?php
namespace CrystalTap;
defined('ABSPATH') || exit;

final class Dependencies {
    /** Only booleans and safe configuration labels; no server paths or secret values. */
    public static function pushStatus(?string $directory = null): array {
        $directory ??= CTAI_DIR;
        $files = [
            'autoload' => 'vendor/autoload.php',
            'vapid' => 'vendor/minishlink/web-push/src/VAPID.php',
            'sender' => 'vendor/minishlink/web-push/src/WebPush.php',
        ];
        $missing = [];
        foreach ($files as $name => $file) {
            if (!is_file($directory . $file) || !is_readable($directory . $file)) { $missing[] = $file; }
        }
        $extensions = array_values(array_filter(['openssl', 'curl', 'mbstring'], static fn($ext) => !extension_loaded($ext)));
        $vapid = class_exists(\Minishlink\WebPush\VAPID::class);
        $sender = class_exists(\Minishlink\WebPush\WebPush::class);
        if ($missing) {
            $issue = 'Web Push dependency files are missing or unreadable: ' . implode(', ', $missing) . '. Replace the active plugin with the complete 0.1.2 or newer package.';
        } elseif ($extensions || !function_exists('openssl_pkey_new')) {
            $issue = 'Web Push requires PHP OpenSSL, cURL, mbstring and the openssl_pkey_new function. Ask your hosting provider to enable the missing or disabled requirements.';
        } elseif (!$vapid || !$sender) {
            $issue = 'Web Push files are present but their classes are not loading. Check for an older active CrystalTap copy or a PHP autoloader conflict. Share the Environment health details with support.';
        } else { $issue = 'Ready'; }
        return ['ready' => $issue === 'Ready', 'missing_files' => $missing, 'missing_extensions' => $extensions, 'vapid_class' => $vapid, 'sender_class' => $sender, 'issue' => $issue];
    }
}
