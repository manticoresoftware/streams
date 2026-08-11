<?php

$stage = getenv('STAGE');
if ($stage == 'dev') {
    $_GET['check'] = 1;
    $_GET['query'] = '小';
    $path          = "/work/kafka-ui/dev-environment/rules-checker/";
} else {
    $path = '/storage/';
}

if ( ! empty($_GET['check'])) {
//    ini_set('display_errors', 1);
//    ini_set('display_startup_errors', 1);
//    error_reporting(E_ALL);


    $startTime = microtime(true);

    $docsCount = file_get_contents($path . 'count.dat');

    if (empty($docsCount)) {
        die(json_encode([
            'status'  => 'error',
            'message' => 'Can\'t check rule cause messages file are empty. Try again later'
        ]));
    }
    $connection = new Mysqli(getenv('MANTICORE_HOST') . ':' . getenv('MANTICORE_PORT'));

    if ($connection->connect_error) {
        die(json_encode(['status' => 'error', 'message' => 'Can\'t connect to Manticore']));
    }

    $query = "SELECT count(*) FROM tests WHERE match('" . mb_strtolower($_GET['query']) . "') ";
    if ( ! empty($_GET['filters'])) {
        $query .= 'and ' . mb_strtolower($_GET['filters']);
    }
    $results   = $connection->query($query);
    $queryTime = round((microtime(true) - $startTime), 6);


    if ( ! $connection->error) {
        $startTime = microtime(true);
        $counter   = $results->fetch_row();
        $counter   = $counter[0];

        $matchedPercent = ($counter / $docsCount) * 100;

        if ($matchedPercent >= getenv('MAX_MATCHED_PERCENT')) {
            die(json_encode(['status' => 'error', 'message' => 'Rule has to much matches']));
        }

        die(json_encode([
            'status'   => 'success',
            'message'  => 'Rule added successfully. Matched rules ' . round($matchedPercent, 2) . '%',
            'measures' => ['queryTime' => $queryTime]
        ]));
    }

    die(json_encode([
        'status'   => 'error',
        'message'  => 'Query error:' . $connection->error,
        'measures' => ['queryTime' => $queryTime]
    ]));

}

echo "ok";


