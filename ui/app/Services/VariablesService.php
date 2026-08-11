<?php

namespace App\Services;

use App\Models\Variable;


class VariablesService
{
    private static array $variables;
    protected static $instance;


    private function __construct()
    {
    }

    private function __clone()
    {
    }


    public static function getInstance()
    {
        if (is_null(self::$instance)) {
            self::$instance = new self;
            $user           = \Auth::user();
            if ($user === null) {
                throw new \RuntimeException("Unauthenticated");
            }
             $rows = Variable::where(['stream_id' => $user->process])->get();
            foreach ($rows as $variable){
                self::$variables[$variable->name] = $variable->text;
            }


        }

        return self::$instance;
    }


    public function getByName(string $name): ?string
    {
        if ( ! isset(self::$variables[$name])) {
            $variable = Variable::where(['name' => $name])->get()->first();

            if ($variable !== null) {
                $this->addVariable($variable);
            } else {
                return null;
            }
        }

        return self::$variables[$name];
    }


    public function addVariable(Variable $variable): void
    {
        self::$variables[$variable->name] = $variable->text;
    }

    public function clean(): void
    {
        self::$variables = [];
    }

}
