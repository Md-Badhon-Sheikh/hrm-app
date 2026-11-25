<?php

/**
 * Fallback front controller for when the document root is this repo folder
 * and mod_rewrite is unavailable. Hands the request to the Laravel app.
 */

require __DIR__.'/hrm-backend/public/index.php';
