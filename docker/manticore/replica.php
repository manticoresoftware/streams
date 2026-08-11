<?php

use Analog\Analog;
use Analog\Handler\EchoConsole;
use Core\K8s\ApiClient;
use Core\K8s\Resources;
use Core\Manticore\ManticoreStreamsJson;
use Core\Manticore\ManticoreStreamsConnector;
use Core\Notifications\NotificationStub;
use Core\Notifications\Telegram;

require 'vendor/autoload.php';

Analog::handler(EchoConsole::init());

//Analog::handler (function ($info) {
//    vprintf (\Analog\Analog::$format, $info);
//
//    static $f = null;
//
//    if ($f == null) {
//        $f = fopen ('/var/lib/manticore/searchd.log', 'a+');
//
//        if (! $f) {
//            throw new \LogicException ('Could not open file for writing');
//        }
//
//        register_shutdown_function (function () use ($f) {
//            if ($f != null) {
//                fclose ($f);
//                $f = null;
//            }
//        });
//    }
//
//    if (! flock ($f, LOCK_EX)) {
//        throw new \RuntimeException ('Could not lock file');
//    }
//
//    fwrite ($f, vsprintf (\Analog\Analog::$format, $info));
//    flock ($f, LOCK_UN);
//});

$telegramToken  = null;
$telegramChatId = null;
$port           = null;
$pipeline       = null;
$hostAppend     = null;
$dev            = null;
$fields         = null;
$instance       = null;
$clusterName    = null;
$labels         = null;

include('env_reader.php');

$configmapAppendPath = '/etc/manticoresearch/conf_mount/';
$filesCheckHash      = ['rt_include.conf', 'stopwords.txt', 'exceptions.txt'];

$oldSHA1     = '';
$oldSHA1Path = '/var/lib/manticore/sha1.txt';
if (file_exists($oldSHA1Path)) {
    $oldSHA1 = file_get_contents($oldSHA1Path);
}

$mixedContent = $fields;
foreach ($filesCheckHash as $filename) {
    $fullPath = $configmapAppendPath.$filename;
    if (file_exists($fullPath)) {
        $mixedContent .= file_get_contents($fullPath);
    }
}

$needAlter = false;
if ($mixedContent !== $fields) {
    $newSHA1 = sha1($mixedContent);
    if ($newSHA1 !== $oldSHA1) {
        $needAlter = true;
        file_put_contents($oldSHA1Path, $newSHA1);
    }
}

function runAlter($needAlter)
{
    if ($needAlter) {
        Analog::log("SHA mismatch. Run alter");
        include_once 'alter.php';
    }
}


if ( ! empty($telegramChatId) && ! empty($telegramToken)) {
    $notification = new Telegram($telegramChatId, $telegramToken);
} else {
    $notification = new NotificationStub();
}

$api           = new ApiClient();
$resources     = new Resources($api, $labels, $notification);
$manticoreJson = new ManticoreStreamsJson($clusterName, $notification);


$replica  = $resources->getCurrentReplica();
$hostname = gethostname();

Analog::log("Replica: $replica");
if ($replica === 0) {
    if ($manticoreJson->hasCluster()) {
        Analog::log("Has cluster");
        $manticoreJson->checkNodesAvailability($resources, $port, $pipeline, 5);
        Analog::log("Checked nodes availability ".json_encode($manticoreJson->getConf()));
        $manticoreJson->startManticore();
        Analog::log("Start manticore");
        $manticoreConnection = new ManticoreStreamsConnector('localhost', $port, $pipeline, -1);
        $manticoreConnection->setMaxAttempts(60);
        $manticoreConnection->setFields($fields);

        if ($manticoreConnection->connectAndCreate()) {
            runAlter($needAlter);
            Analog::log("Replication connection success");
            exit(0);
        }

        $notification->sendMessage('Error #1 in zero  node ('.$hostname.'). Can\'t create cluster cause '
            .$manticoreConnection->getConnectionError());
    } else {
        Analog::log("No cluster");
        $manticoreJson->startManticore();
        Analog::log("Start manticore");
        $manticoreConnection = new ManticoreStreamsConnector('localhost', $port, $pipeline, -1);
        $manticoreConnection->setMaxAttempts(60);
        $manticoreConnection->setFields($fields);

        if ($manticoreConnection->isTableExist('pq')) {
            Analog::log('Table exists');
            if ($manticoreConnection->createCluster()
                && $manticoreConnection->addTableToCluster('pq')
                && $manticoreConnection->addTableToCluster('tests')
            ) {
                runAlter($needAlter);
                Analog::log("Replication connection success");
                exit(0);
            }
            $notification->sendMessage('Error #2 in zero node ('.$hostname.'). Can\'t create cluster cause '
                .$manticoreConnection->getConnectionError());
        } else {
            Analog::log('Table not exists');
            $nodes = $resources->getPodsHostnames();
            Analog::log("Get pods hostname: ".json_encode($nodes));

            if ($nodes !== [] && $nodes !== [gethostname()]) {
                foreach ($nodes as $node) {
                    // Skip current node
                    if ($node === $hostname) {
                        continue;
                    }

                    try {
                        $remoteNodeConnection = new ManticoreStreamsConnector($node.$hostAppend, $port, $pipeline, 5);
                        if ( ! $remoteNodeConnection->checkClusterName()) {
                            $notification->sendMessage("Cluster name mismatch at $node\n");
                            Analog::log("Cluster name mismatch at $node");
                            continue;
                        }

                        if ($manticoreConnection->joinCluster($node.$hostAppend)) {
                            runAlter($needAlter);
                            Analog::log("Replication connection success");
                            exit(0);
                        }

                        $notification->sendMessage('Error #3 in zero node ('.$hostname.'). Cant join to '.$node.' Cause '
                            .$manticoreConnection->getConnectionError());
                    } catch (RuntimeException $exception) {
                        $notification->sendMessage('Error #4 in zero node ('.$hostname.'). Join at '.$node.' RuntimeException  '
                            .$exception->getMessage());
                    }
                }

                $notification->sendMessage('Error #5 in zero node ('.$hostname.'). Here no any available nodes to join. Nodes:'
                    .json_encode($nodes));
            } elseif ($manticoreConnection->connectAndCreate()) {
                runAlter($needAlter);
                Analog::log("Replication connection success");
                exit(0);
            }
        }
    }

    $notification->sendMessage('Error #6 in zero node ('.$hostname.'). Create or join error: '.$manticoreConnection->getConnectionError());
    exit(1);
} else {
    $zeroReplicaName = $resources->getMinReplicaName();

    if ($manticoreJson->hasCluster()) {
        Analog::log("Has cluster");
        $manticoreJson->checkNodesAvailability($resources, $port, $pipeline, 5);
        Analog::log("Checked nodes availability ".json_encode($manticoreJson->getConf()));
        $manticoreJson->startManticore();
        Analog::log("Start manticore");

        $manticoreConnection = new ManticoreStreamsConnector('localhost', $port, $pipeline, -1);
        $manticoreConnection->setMaxAttempts(60);

        if ($manticoreConnection->checkClusterName()) {
            runAlter($needAlter);
            Analog::log("Replication connection success");
            exit(0);
        } else {
            $zeroNodeConnection = new ManticoreStreamsConnector($zeroReplicaName.$hostAppend, $port, $pipeline, -1);

            if ($zeroNodeConnection->isClusterPrimary()) {
                Analog::log("Cluster at $zeroReplicaName in primary state");

                if ($manticoreConnection->joinCluster($zeroReplicaName.$hostAppend)) {
                    runAlter($needAlter);
                    Analog::log("Replication connection success");
                    exit(0);
                }

                $notification->sendMessage('Error #7 in node ('.$hostname.'). Can\'t join cluster to '
                    .$manticoreConnection->getConnectionError());
            } else {
                Analog::log("Cluster at $zeroReplicaName in NOT primary state");
                $zeroNodeConnection->setMaxAttempts(60);
                $zeroNodeConnection->restoreCluster();
                Analog::log("Restore cluster at $zeroReplicaName");

                if ($manticoreConnection->joinCluster($zeroReplicaName.$hostAppend)) {
                    Analog::log("Run alter");
                    runAlter($needAlter);
                    Analog::log("Replication connection success");
                    exit(0);
                }

                $notification->sendMessage('Error #8 in node ('.$hostname.'). Can\'t join cluster to '
                    .$manticoreConnection->getConnectionError());
            }
        }
    } else {
        Analog::log("No cluster");
        $manticoreJson->startManticore();
        Analog::log("Start manticore");
        $manticoreConnection = new ManticoreStreamsConnector('localhost', $port, $pipeline, -1);


        Analog::log("Join to $zeroReplicaName$hostAppend");
        if ($manticoreConnection->joinCluster($zeroReplicaName.$hostAppend)) {
            Analog::log("Run alter");
            runAlter($needAlter);
            Analog::log("Replication connection success");
            exit(0);
        }

        $notification->sendMessage('Error #8 in node ('.$hostname.'). Can\'t join cluster to '.$manticoreConnection->getConnectionError());
    }

    $notification->sendMessage('Error #9 in node ('.$hostname.'). Create or join error '.$manticoreConnection->getConnectionError());
    exit(1);
}
?>
