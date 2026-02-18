<?php
require_once __DIR__ . '/includes/auth.php';
logout_user();
flash('success', 'You have been logged out.');
redirect(base_url('index.php'));
