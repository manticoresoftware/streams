<?php

return [
    'host' => getenv('COLUMNAR_HOST') ?
        getenv('COLUMNAR_HOST').':'.getenv('COLUMNAR_PORT')
        : 'columnar:9306',
    'index' => 'metrics'
];
