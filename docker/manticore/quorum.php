<?php

use Analog\Analog;
use Analog\Handler\EchoConsole;
use Core\K8s\ApiClient;
use Core\K8s\Resources;
use Core\Manticore\ManticoreStreamsConnector;
use Core\Manticore\ManticoreStreamsJson;
use Core\Notifications\NotificationStub;
use Core\Notifications\Telegram;

require 'vendor/autoload.php';


Analog::handler(EchoConsole::init());


$labels       = null;
$notification = null;
$port         = null;
$clusterName  = null;
$pipeline     = null;

include("env_reader.php");

if ( ! empty($telegramChatId) && ! empty($telegramToken)) {
    $notification = new Telegram($telegramChatId, $telegramToken);
} else {
    $notification = new NotificationStub();
}

$api       = new ApiClient();
$resources = new Resources($api, $labels, $notification);


$manticore = new ManticoreStreamsConnector('localhost', $port, $pipeline, -1);
if ($manticore->checkClusterName() && ! $manticore->isClusterPrimary()
    && gethostname() === $resources->getMinReplicaName()
) {
    $manticoreJson = new ManticoreStreamsJson($clusterName, new NotificationStub());

    Analog::info("Check nodes");
    if ($manticoreJson->isAllNodesNonPrimary($resources, $port)) {
        Analog::info("Node on in cluster but hasn't primary status. Trying to fix it");
        $manticore->restoreCluster();
        Analog::info("Successfully fixed non primary cluster state");
    }
}

