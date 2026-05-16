<?php
require_once __DIR__ . '/includes/auth.php';

if (is_logged_in()) {
    redirect(base_url('dashboard.php'));
} else {
    redirect(base_url('landing.php'));
}
