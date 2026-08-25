<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

// The test suite never reads .env — it would make results depend on whatever
// the developer happens to have configured locally. Everything the tests need
// is set explicitly in phpunit.xml.dist.
$_ENV['APP_ENV'] = 'test';

date_default_timezone_set('UTC');
