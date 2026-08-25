<?php

/**
 * HTTP entry point. Nothing but wiring lives here — anything that looks like
 * a decision belongs in config/ or src/.
 */

declare(strict_types=1);

use Slim\Factory\AppFactory;

$container = (require __DIR__ . '/../config/bootstrap.php')();

AppFactory::setContainer($container);
$app = AppFactory::create();

(require __DIR__ . '/../config/middleware.php')($app, $container);
(require __DIR__ . '/../config/routes.php')($app);

$app->run();
