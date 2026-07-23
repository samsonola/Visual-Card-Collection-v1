<?php

/*
|--------------------------------------------------------------------------
| Export Worker Launcher
|--------------------------------------------------------------------------
|
| Starts the Export Worker.
| Intended to be executed from CLI or Supervisor.
|
*/

if (php_sapi_name() !== 'cli') {

    exit("This script must be run from CLI.\n");

}

require_once __DIR__ . '/../import-export-service/export_worker.php';