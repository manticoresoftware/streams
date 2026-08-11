<?php

return [
    'host' => (in_array(config('app.env'), ['production', 'cluster_testing']) ) ?
        getenv('COLUMNAR_HOST').':'.getenv('COLUMNAR_PORT')
        : 'columnar:9306',
    'index' => 'metrics'
];
