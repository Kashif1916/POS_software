<?php
require_once __DIR__ . '/includes/functions.php';
if (current_user()) {
    redirect(current_user()['role'] === 'Admin' ? '/POS_software/admin/dashboard.php' : '/POS_software/operator/index.php');
}
redirect('/POS_software/login.php');
