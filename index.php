<?php
require_once __DIR__ . '/includes/functions.php';
if (current_user()) {
    redirect(in_array(current_user()['role'], ['Admin', 'Master'], true) ? '/admin/dashboard.php' : '/operator/index.php');
}
redirect('/login.php');
