<?php

// TO apply new config you must
//
// 1) edit selected configmap
// 2) apply it running this file in ManticoreSearch container: php alter.php --table=pq --batch=500

use Core\Manticore\ManticoreAlterTable;
use Core\Manticore\ManticoreStreamsConnector;

require_once 'vendor/autoload.php';



$options = getopt("i::b::", ['table::', 'batch::']);

if (!isset($options['table'])) {
    $options['table'] = 'pq';
}

if (!isset($options['batch'])) {
    $options['batch'] = 500;
}

$port     = getenv("MANTICORE_PORT");
$pipeline = getenv('PIPELINE');
$fields   = getenv('MANTICORE_RULES');

if (empty($pipeline)) {
    die("Need to set manticore pipeline to environments\n");
}

$manticoreTableUpdater = new ManticoreAlterTable('localhost', $port, $pipeline, -1);
$manticoreTableUpdater->setFields($fields);

$newTable = $manticoreTableUpdater->createTable($options['table'].'_backup', ManticoreStreamsConnector::TABLE_TYPE_PERCOLATE);
if ($newTable) {
    $manticoreTableUpdater->copyData($options['table'], $options['table'].'_backup', $options['batch']);
    $manticoreTableUpdater->dropTable($options['table']);
    $newTable = $manticoreTableUpdater->createTable($options['table'], ManticoreStreamsConnector::TABLE_TYPE_PERCOLATE);

    if ($newTable &&
        $manticoreTableUpdater->addTableToCluster($options['table']) &&
        $manticoreTableUpdater->copyData($options['table'].'_backup', $options['table'], $options['batch'], true) &&
        $manticoreTableUpdater->dropTable($options['table'].'_backup', false)) {
        Analog::log("Table ".$options['table']." was altered successfully");
        exit(0);
    }
}

Analog::log("Table ".$options['table'].' was not altered. Error: '.$manticoreTableUpdater->getConnectionError());





