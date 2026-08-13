<?php

return [
    'host' => getenv('MANTICORE_HOST') ?
        getenv('MANTICORE_HOST').':'.getenv('MANTICORE_PORT')
        : 'manticore:9306',
    'index' => 'pq'
];
