<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

dashboard_auth_logout();
header('Location: /login.php');
exit;
