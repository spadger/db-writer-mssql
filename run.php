<?php

use Keboola\DbWriter\MSSQL\Application;
use Keboola\DbWriter\Exception\ApplicationException;
use Keboola\DbWriter\Exception\UserException;
use Keboola\DbWriter\Logger;
use Keboola\DbWriter\MSSQL\Configuration\ConfigDefinition;
use Monolog\Handler\NullHandler;

define('APP_NAME', 'wr-db-mssql');
define('ROOT_PATH', __DIR__);

require_once(dirname(__FILE__) . "/vendor/keboola/db-writer-common/bootstrap.php");

$logger = new Logger(APP_NAME);

$action = 'run';

try {
    $arguments = getopt("d::", ["data::"]);
    if (!isset($arguments["data"])) {
        throw new UserException('Data folder not set.');
    }
    $config = json_decode(file_get_contents($arguments["data"] . "/config.json"), true);
    $config['parameters']['data_dir'] = $arguments['data'];
    $config['parameters']['writer_class'] = 'MSSQL';

    $action = isset($config['action']) ? $config['action'] : $action;

    $app = new Application($config, $logger, new ConfigDefinition());

    if ($app['action'] !== 'run') {
        $app['logger']->setHandlers(array(new NullHandler(Logger::INFO)));
    }

    echo $app->run();
} catch (UserException $e) {
    $logger->log('error', $e->getMessage(), (array) $e->getData());

    if ($action !== 'run') {
        echo $e->getMessage();
    }

    exit(1);
} catch (ApplicationException $e) {
    $logger->log('error', $e->getMessage(), [
        'data' => $e->getData(),
        'errFile' => $e->getFile(),
        'errLine' => $e->getLine()
    ]);
    exit($e->getCode() > 1 ? $e->getCode(): 2);
} catch (\Exception $e) {
    $logger->log('error', $e->getMessage(), [
        'errFile' => $e->getFile(),
        'errLine' => $e->getLine(),
        //'trace' => $e->getTrace()
    ]);
    exit(2);
}
exit(0);
