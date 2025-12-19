<?php
require_once 'includes/functions.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

// Destroy session
session_destroy();

// Redirect to login
header('Location: login.php');
exit;
