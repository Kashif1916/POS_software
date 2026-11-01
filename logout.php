<?php
require_once __DIR__ . '/includes/functions.php';
session_unset();
session_destroy();
flash('error', 'آپ کامیابی سے لاگ آؤٹ ہو گئے۔');
redirect('/login.php');
