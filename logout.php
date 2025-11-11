<?php
define('IN_APP', true);
require_once 'utils/functions.php';

logout();
header('Location: login.php');
exit;