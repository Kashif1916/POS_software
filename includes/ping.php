<?php
/**
 * ping.php – Called by the client-side idle timer via fetch() to keep the
 *            PHP session alive as long as the user is still active.
 */
require_once __DIR__ . '/../includes/functions.php';

// Only allow POST requests from logged-in users
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !current_user()) {
    http_response_code(204);
    exit;
}

// Refresh the last-activity timestamp so server-side check doesn't expire early
$_SESSION['last_activity'] = time();

http_response_code(204); // No Content – no body needed
exit;
