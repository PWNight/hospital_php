<?php
define('IN_APP', true);
require_once 'utils/functions.php';
header('Location: ' . (is_logged_in() ? 'profile.php' : 'login.php'));
exit;