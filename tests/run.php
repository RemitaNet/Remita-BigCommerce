<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$files = glob(__DIR__ . '/*Test.php') ?: [];
$total = $pass = $fail = 0;

foreach ($files as $file) {
    require_once $file;
    $class = basename($file, '.php');
    if (!class_exists($class)) {
        continue;
    }
    /** @var TestCase $t */
    $t = new $class();
    $t->run();
    $t->report();
    $total++;
}

echo "\nDone: {$total} test files\n";
