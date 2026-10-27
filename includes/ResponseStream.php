<?php
namespace CrystalTap;
defined('ABSPATH') || exit;

/** Incremental Responses API SSE decoder; accepts event and UTF-8 boundaries across network chunks. */
final class ResponseStream {
    private string $buffer = '';
    private string $text = '';
    public ?array $completed = null;
    public function feed(string $bytes, callable $delta): void {
        $this->buffer = str_replace("\r\n", "\n", $this->buffer . $bytes);
        if (strlen($this->buffer) > 1000000) { throw new Fault('AI stream exceeded the safe event size', 503); }
        while (($end = strpos($this->buffer, "\n\n")) !== false) {
            $event = substr($this->buffer, 0, $end); $this->buffer = substr($this->buffer, $end + 2);
            $data = [];
            foreach (explode("\n", $event) as $line) { if (str_starts_with($line, 'data:')) { $data[] = ltrim(substr($line, 5)); } }
            $parsed = json_decode(implode("\n", $data), true);
            if (($parsed['type'] ?? '') === 'response.output_text.delta') {
                $this->text .= $parsed['delta'];
                if (strlen($this->text) > 100000) { throw new Fault('AI output exceeded the safe size', 503); }
                if (preg_match('/"answer"\s*:\s*"((?:[^"\\\\]|\\\\.)*)/s', $this->text, $match)) {
                    $decoded = json_decode('"' . $match[1] . '"', true);
                    if (is_string($decoded)) { $delta($decoded); }
                }
            }
            if (($parsed['type'] ?? '') === 'response.completed') { $this->completed = $parsed['response']; }
        }
    }
}
