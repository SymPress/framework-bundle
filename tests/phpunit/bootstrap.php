<?php

declare(strict_types=1);

$autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';

if (!is_file($autoload)) {
    $autoload = dirname(__DIR__, 4) . '/vendor/autoload.php';
}

if (is_file($autoload)) {
    require_once $autoload;
}
