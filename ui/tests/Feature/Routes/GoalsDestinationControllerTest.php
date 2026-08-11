<?php

namespace Tests\Feature\Routes;

/**
 * @group application
 */
class GoalsDestinationControllerTest extends Goals
{
    public function __construct($name = null, array $data = [], $dataName = '')
    {
        parent::__construct($name, $data, $dataName);
        $this->model   = \App\Models\Destination::class;
        $this->section = 'destination';
    }
}
