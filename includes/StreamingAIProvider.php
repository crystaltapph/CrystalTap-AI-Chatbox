<?php
namespace CrystalTap;
defined('ABSPATH') || exit;

interface StreamingAIProvider extends AIProvider { public function generateStream(array $body, int $timeout, callable $delta): array; }
