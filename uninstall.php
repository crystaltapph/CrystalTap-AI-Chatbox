<?php
/** Data is deliberately retained. Use authenticated privacy tools before uninstalling. */
defined('WP_UNINSTALL_PLUGIN') || exit;
// Uninstall must not silently erase customer records or encryption material.
// Roles and capabilities are retained to preserve recoverable account assignments on reinstall.
