<?php
namespace CrystalTap;
defined('ABSPATH') || exit;
final class Fault extends \RuntimeException {
    public function __construct(string $message, public int $status = 400, public string $slug = 'invalid_request') { parent::__construct($message); }
}
