<?php
namespace CrystalTap;
defined('ABSPATH') || exit;

final class ResponsesProvider implements StreamingAIProvider {
    public function generateStream(array $body, int $timeout, callable $delta): array {
        if (!function_exists('curl_setopt')) { return $this->generate($body, $timeout); }
        $decoder = new ResponseStream();
        $hook = static function ($handle, $args, $url) use ($decoder, $delta) {
            if ($url !== 'https://api.openai.com/v1/responses') { return; }
            curl_setopt($handle, CURLOPT_WRITEFUNCTION, static function ($curl, $bytes) use ($decoder, $delta) {
                $decoder->feed($bytes, $delta);
                return strlen($bytes);
            });
        };
        add_action('http_api_curl', $hook, 10, 3);
        try {
            $body['stream'] = true;
            $this->generate($body, $timeout);
        } catch (Fault $e) {
            // The cURL body is consumed by the SSE parser, so normal JSON parsing is intentionally empty.
            if (!$decoder->completed) { throw $e; }
        } finally { remove_action('http_api_curl', $hook, 10); }
        if (!is_array($decoder->completed) || ($decoder->completed['status'] ?? '') !== 'completed') { throw new Fault('AI stream interrupted', 503, 'ai_incomplete'); }
        return $decoder->completed;
    }
    public function generate(array $body, int $timeout, int $attempt = 0): array {
        $key = Settings::secret('openai_key');
        if (!$key) { throw new Fault('AI credentials are not configured', 503, 'ai_unavailable'); }
        $trust = getenv('CTAI_CA_BUNDLE') ?: ABSPATH . WPINC . '/certificates/ca-bundle.crt';
        $result = wp_remote_post('https://api.openai.com/v1/responses', ['timeout' => $timeout, 'redirection' => 0, 'headers' => ['Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json'], 'body' => wp_json_encode($body), 'sslverify' => true, 'sslcertificates' => $trust]);
        if (is_wp_error($result)) { throw new Fault('AI request could not be completed', 503, 'ai_network'); }
        $status = wp_remote_retrieve_response_code($result);
        $data = json_decode(wp_remote_retrieve_body($result), true);
        if ($status === 429) {
            $error = is_array($data['error'] ?? null) ? $data['error'] : [];
            // Classify only documented codes. Provider messages can contain credential fragments.
            $quotaCodes = ['insufficient_quota', 'billing_hard_limit_reached', 'billing_not_active'];
            if (in_array($error['code'] ?? null, $quotaCodes, true) || in_array($error['type'] ?? null, $quotaCodes, true)) {
                throw new Fault('OpenAI API quota is unavailable or exhausted. Check API billing, available credits, and project limits. A ChatGPT subscription does not include API usage.', 503, 'ai_quota');
            }
        }
        if (in_array($status, [429,503], true) && $attempt < (int) Settings::get()['retry_limit']) {
            usleep(500000);
            return $this->generate($body, $timeout, $attempt + 1);
        }
        if ($status === 429) {
            if (($error['code'] ?? null) === 'rate_limit_exceeded' || ($error['type'] ?? null) === 'rate_limit_exceeded') {
                throw new Fault('OpenAI temporarily rate-limited this request. Wait before trying again. If it continues, check the selected model\'s rate limits in your OpenAI project.', 429, 'ai_rate_limit');
            }
            throw new Fault('OpenAI returned HTTP 429. Check API billing and credits, as well as the selected model\'s rate limits. Wait before trying again.', 429, 'ai_provider_limit');
        }
        if ($status < 200 || $status >= 300) { throw new Fault('AI provider returned HTTP ' . (int) $status, 503, 'ai_provider'); }
        if (!is_array($data) || ($data['status'] ?? '') !== 'completed') { throw new Fault('AI response was incomplete', 503, 'ai_incomplete'); }
        return $data;
    }
}
