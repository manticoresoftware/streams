<?php

namespace App\Services;


use mysqli;


abstract class BaseManticoreService
{
    const MAX_QUERY_ATTEMPTS = 3;
    const FAILDED_QUERY_TIMEOUT = 5;


    /**
     * Connection to searchhd
     *
     * @var mysqli
     */
    protected $connection;

    /**
     * Error message
     *
     * @var string
     */
    protected $error;

    abstract public function connect();

    public function __wakeup()
    {
        $this->connect();
    }


    /**
     * Escape special chars
     *
     * @param $string
     *
     * @param  bool  $escapeBlank
     *
     * @param  bool  $extraSlashes
     *
     * @return mixed
     */


    protected function escapeStringRegex($string)
    {
        $from = ['{', '}', '[', ']', '(', ')', '|', '?', '^', '$', '+', '*', '.'];
        $to   = ['\{', '\}', '\[', '\]', '\(', '\)', '\|', '\?', '\^', '\$', '\+', '\*', '\.'];

        return str_replace($from, $to, $string);
    }

    protected function escapeString($string, $escapeBlank = true, $extraSlashes = false)
    {
        $from = ['\\', '(', ')', '|', '!', '@', '~', '"', '&', '/', '^', '$', '=', '<', "'"];
        $to   = ['\\\\', '\(', '\)', '\|', '\!', '\@', '\~', '\"', '\&', '\/', '\^', '\$', '\=', '\<', "\'"];

        if ($escapeBlank) {
            $from[] = '-';
            $to[]   = '\-';
        }

        $result = str_replace($from, $to, $string);

        if ($extraSlashes) {
            return str_replace("\\", "\\\\", $result);
        }

        return $result;
    }


    public function getError()
    {
        return $this->error;
    }

    protected function beginTransaction()
    {
        return $this->query("begin");
    }

    protected function commitTransaction()
    {
        return $this->query("commit");
    }

    protected function rollbackTransaction()
    {
        return $this->query("rollback");
    }

    public function getlastInsertId()
    {
        return $this->connection->insert_id;
    }

    protected function query($sql, $attempts = 1)
    {
        if ($this->connection === null) {
            $explodedClass = explode('\\', static::class);
            throw new \RuntimeException("Can't connect to " . array_pop($explodedClass));
        }
        $result = $this->connection->query($sql);

        if ($this->connection->errno !== 0 && $attempts !== -1) {
            if ($attempts >= self::MAX_QUERY_ATTEMPTS) {
                throw new \RuntimeException($this->connection->error);
            }
            sleep(self::FAILDED_QUERY_TIMEOUT);
            $attempts++;

            return $this->query($sql, $attempts);
        }

        return $result;
    }

}
