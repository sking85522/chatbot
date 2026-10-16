<?php
/** 
 * Main Web Entry Point
 * Directs browser visits to the interactive tester in public/index.html
 */
if (file_exists(__DIR__ . '/public/index.html')) {
    include __DIR__ . '/public/index.html';
    exit;
}

header('Location: api.php');
exit;

