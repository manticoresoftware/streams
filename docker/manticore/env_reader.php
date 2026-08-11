<?php

$telegramToken  = getenv('TELEGRAM_TOKEN');
$telegramChatId = getenv('TELEGRAM_CHAT_ID');
$port           = getenv("MANTICORE_PORT");
$pipeline       = getenv('PIPELINE');
$hostAppend     = getenv('MANTICORE_SERVICE_PATH');
$dev            = getenv('STAGE');
$fields         = getenv('MANTICORE_RULES');
$instance       = getenv('INSTANCE_LABEL');

if (empty($instance)) {
    die("Need to pass instance label to environments\n");
}

if (empty($port)) {
    die("Need to set manticore port to environments\n");
}

if (empty($pipeline)) {
    die("Need to set manticore pipeline to environments\n");
}

if ($dev === 'dev') {
    define('DEV', true);
}


$clusterName = $pipeline.'_cluster';
$pipeline    = strtolower($pipeline);

$labels = [
    'app.kubernetes.io/component' => 'worker',
    'app.kubernetes.io/instance'  => $instance,
    'app.kubernetes.io/pipeline'  => $pipeline,
];
