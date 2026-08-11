<?php

namespace App\Services;

class KafkaConnection
{
    private $conf;
    private $consumer;

    public function __construct()
    {
        $this->conf = new \RdKafka\Conf();

        $this->conf->setRebalanceCb(function (\RdKafka\KafkaConsumer $kafka, $err, array $partitions = null) {
            switch ($err) {
                case RD_KAFKA_RESP_ERR__ASSIGN_PARTITIONS:
                    $kafka->assign($partitions);
                    break;

                case RD_KAFKA_RESP_ERR__REVOKE_PARTITIONS:
                    $kafka->assign(null);
                    break;

                default:
                    throw new \Exception($err);
            }
        });
    }

    public function connect($host, $topic, $group = null)
    {
        $this->conf->set('metadata.broker.list', $host);

        if ( ! empty($group)) {
            $this->conf->set('group.id', $group);
        }

        $this->conf->set('auto.offset.reset', 'smallest');

        $this->consumer = new \RdKafka\KafkaConsumer($this->conf);

        $this->consumer->subscribe([$topic]);
    }

    public function consume($time){
        return $this->consumer->consume($time);
    }
}
