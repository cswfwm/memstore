<?php
declare(strict_types=1);

require __DIR__ . '/lib/web.php';

web_logout();
header('Location: index.php');
exit;
