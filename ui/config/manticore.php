<?php

return [
    'host' => (in_array(config('app.env'), ['production', 'cluster_testing']) ) ?
        getenv('MANTICORE_HOST').':'.getenv('MANTICORE_PORT')
        : 'manticore:9306',
    'index' => 'pq'
];
