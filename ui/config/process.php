<?php

return [
    'kafka' => [
        'inputHost'   => 'dev.manticoresearch.com:9092',
        'outputHost'  => 'dev.manticoresearch.com:9092',
        'inputTopic'  => 'my-docs',
        'outputTopic' => 'my-results',
        'groupName'   => 'streams-manticore',
    ],


    'worker' => [
        'minThreads'           => 1,
        'maxThreads'           => 3,
        'maxBatchSize'         => 5000,
        'processedMeasureTime' => 60,
        'image'                => [
            'repository' => 'ghcr.io/manticoresoftware/streams/worker',
            'tag'        => '0.0.1',
        ],

        'service'              => [
            'port'       => 80,
            'targetPort' => 9000,
        ],
        'outputDocs'           => '1000',
        'jsltEnabled'          => false,
        'jsltConf'             => '',
        'transformRules'       => true,
        'handlerRules'         => "|
    text.status.text => text\n
    text.comment.status.text => parent_text\n
    text.status.user.screen_name => user_screen_name\n
    text.status.user.description => user_description\n
    text.comment.reply_comment.text => reply_comment_text\n
    text.status.retweeted_status.text => retweeted_status_text",
        'volumeClaimTemplates' => [
            'size' => '500Mi',
        ],
    ],


    'manticore'    => [
        'exposeOutside'        => false,
        'exposePort'           => 32080,
        'userId'               => 999,
        'image'                => [
            'repository' => 'ghcr.io/manticoresoftware/streams/manticore',
            'tag'        => '0.0.1',
        ],
        'searchd'              => [
            'blacklist-mode' => 0,
        ],
        'service'              => [
            'port' => getenv('MANTICORE_PORT'),
        ],
        'volumeClaimTemplates' => [
            'size' => '1Gi',
        ],
        'configAdditiveFields' => "",
        "include"              => [
            "searchd" => "",
            "rt"      => "",
        ],
    ],
    'columnar'   => [
        'enabled' => true,
    ],
    'rulesChecker' => [
        'enabled'           => false,
        'maxMatchedPercent' => 30,
        'image'             => [
            'repository' => 'ghcr.io/manticoresoftware/streams/rules_checker',
            'tag'        => '0.0.1',
        ],
        'service'           => [
            'port'       => 80,
            'targetPort' => 8080,
        ],
    ],
];
