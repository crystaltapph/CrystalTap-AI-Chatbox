<?php
namespace CrystalTap;
defined('ABSPATH') || exit;

interface AIProvider { public function generate(array $body, int $timeout): array; }
