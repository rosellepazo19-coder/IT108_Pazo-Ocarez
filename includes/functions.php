<?php
// Shared helper functions for the system

/**
 * Sanitize output for HTML contexts.
 */
function h($value) {
	return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Validate and normalize integer from request.
 */
function req_int($key, $default = null) {
	if (!isset($_REQUEST[$key])) return $default;
	return filter_var($_REQUEST[$key], FILTER_VALIDATE_INT, ['options' => ['default' => $default]]);
}

/**
 * Validate and trim string from request.
 */
function req_str($key, $default = '') {
	if (!isset($_REQUEST[$key])) return $default;
	return trim((string)$_REQUEST[$key]);
}

/**
 * CSRF token utilities using session.
 */
function csrf_token() {
	if (session_status() !== PHP_SESSION_ACTIVE) session_start();
	if (empty($_SESSION['csrf_token'])) {
		$_SESSION['csrf_token'] = bin2hex(random_bytes(16));
	}
	return $_SESSION['csrf_token'];
}

function csrf_check($token) {
	if (session_status() !== PHP_SESSION_ACTIVE) session_start();
	return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], (string)$token);
}

/**
 * Simple paginator bounds.
 */
function paginate($totalRows, $perPage = 10) {
	$page = max(1, (int)req_int('page', 1));
	$pages = max(1, (int)ceil($totalRows / max(1, $perPage)));
	$page = min($page, $pages);
	$offset = ($page - 1) * $perPage;
	return [$page, $pages, $offset, $perPage];
}

/**
 * Return a human friendly status badge HTML.
 */
function status_badge($status) {
	$cls = 'badge-gray';
	switch ($status) {
		case 'available': $cls = 'badge-green'; break;
		case 'unavailable': $cls = 'badge-red'; break;
		case 'maintenance': $cls = 'badge-orange'; break;
		case 'reserved': $cls = 'badge-blue'; break;
	}
	return '<span class="badge ' . $cls . '">' . h($status) . '</span>';
}

/**
 * Session user helpers and role guard.
 */
function current_user() {
	if (session_status() !== PHP_SESSION_ACTIVE) session_start();
	return $_SESSION['user'] ?? null; // expected: ['user_id'=>..,'Fname'=>..,'Lname'=>..,'role'=>..,'mail'=>..]
}

function current_role() {
	$u = current_user();
	return $u['role'] ?? null; // 'admin'|'staff'|'borrower'
}

function current_user_name() {
	$u = current_user();
	if (!$u) return 'Guest';
	return trim($u['Fname'] . ' ' . $u['Lname']);
}

function require_login() {
	if (session_status() !== PHP_SESSION_ACTIVE) session_start();
	if (!current_user()) {
		header('Location: login.php');
		exit;
	}
}

function require_roles(array $allowedRoles) {
	require_login(); // Ensure user is logged in first
	$r = current_role();
	if (!in_array($r, $allowedRoles, true)) {
		http_response_code(403);
		die('Forbidden: insufficient permissions.');
	}
}

?>


