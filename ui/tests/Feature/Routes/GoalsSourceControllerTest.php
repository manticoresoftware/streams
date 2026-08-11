<?php

namespace Tests\Feature\Routes;

use App\Models\Source;

/**
 * @group application
 */
class GoalsSourceControllerTest extends Goals
{

    public function __construct($name = null, array $data = [], $dataName = '')
    {
        parent::__construct($name, $data, $dataName);
        $this->model   = Source::class;
        $this->section = 'source';
    }
}
