<?php

/*
|--------------------------------------------------------------------------
| Import Worker Launcher
|--------------------------------------------------------------------------
|
| Starts the Import Worker.
| Intended to be executed from CLI or Supervisor.
|
*/

if (php_sapi_name() !== 'cli') {

    exit("This script must be run from CLI.\n");

}

require_once __DIR__ . '/../import-export-service/import_worker.php';