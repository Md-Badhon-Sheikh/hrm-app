<?php

/**
 * Fallback front controller for hosts whose document root is this project
 * folder instead of /public (the root .htaccess normally rewrites into
 * /public before this file is ever reached). It simply hands the request to
 * the real Laravel entry point so the app behaves the same either way.
 */

require __DIR__.'/public/index.php';
