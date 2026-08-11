<?php

namespace App\Services;


use App\Models\Rule;
use App\Services\Curl\CurlService;
use Carbon\Carbon;
use Exception;
use mysqli;


class ColumnarService extends BaseManticoreService
{

    protected string $streamId;

    public function __construct()
    {
        $this->connect();
    }

    public function setStream($streamId): void
    {
        if (in_array(app()->environment(), ['dev', 'testing'])) {
            $this->streamId = 'm';
        }else{
            $this->streamId = $streamId;
        }
        $this->connect();
    }

    public function connect(): bool
    {
        try {
            $this->connection = new mysqli(config('columnar.host'), '', '', '');
            $this->connection->set_charset("utf8");

            return true;
        } catch (Exception $exception) {
            $this->error = $exception->getMessage();

            return false;
        }
    }


    public function getRuleStats($ids): array
    {
        $dates = $this->getDateRange();

        $query = 'select sum(value) AS value_field, tag, (FLOOR(scraptime/3600)) as groupedTime '.
            'from metrics '.
            'WHERE tag IN ('.implode(',', $ids).')'.
            "    AND match('@metric_name ".$this->streamId."_handler_processed_rules') ".
            "    AND (scrapTime > ".$dates['start'].") ".
            "    AND (scrapTime < ".$dates['end'].") ".
            'GROUP BY groupedTime, tag '.
            'ORDER BY groupedTime ASC';

        $metrics = $this->query($query);

        $results = [];

        foreach ($ids as $id) {
            foreach ($dates['range'] as $date) {
                $results[$id][$date] = 0;
            }
        }

        if ($metrics) {
            $metrics = $metrics->fetch_all(MYSQLI_ASSOC);

            if ( ! empty($metrics)) {
                foreach ($metrics as $row) {
                    $results[$row['tag']][$row['groupedtime']] = (int)$row['value_field'];
                }
            }
        }

        return $results;
    }

    public function getProcessedSum($limitsString, $order): array
    {
        $dates = $this->getDateRange();

        $query = "SELECT sum(value) as sum, tag ".
            "FROM metrics ".
            "WHERE match('@metric_name ".$this->streamId."_handler_processed_rules') ".
            "AND scrapTime > ".$dates['start']." ".
            "AND scrapTime < ".$dates['end']." ".
            "GROUP BY tag ".
            "ORDER BY sum $order ".
            "$limitsString ";


        $rules = $this->query($query);
        if ($rules) {
            return $rules->fetch_all(MYSQLI_ASSOC);
        }

        return [];
    }

    public function getGraph($operator, $interval, $tag, $section, $dateFrom, $dateTo): array
    {
        $query = 'SELECT '.$operator.'(value) as value, (FLOOR(scraptime/'.$interval.')) as groupedTime, scraptime FROM metrics '.
            'WHERE tag='.$tag.' '.
            'AND match(\'@metric_name '.$section.'\') '.
            'AND scrapTime > '.$dateFrom.' '.
            'AND scrapTime < '.$dateTo.' '.
            'GROUP BY groupedTime ORDER BY groupedTime ASC';

        $metrics = $this->query($query);

        if ($metrics) {
            $metrics = $metrics->fetch_all(MYSQLI_ASSOC);

            $categories = [];
            $values     = [];

            foreach ($metrics as $k => $v) {
                $categories[] = date('Y-m-d H:i:s', $v['scraptime']);
                $values[]     = (int)$v['value'];
            }

            return ['categories' => $categories, 'values' => $values];
        }

        return ['categories' => [], 'values' => []];
    }

    private function getDateRange(): array
    {
        $startDate = Carbon::now()->subHours(6)->timestamp;
        $endDate   = Carbon::now()->timestamp;

        $dateRange = [];
        for ($i = 0; $i <= 6; $i++) {
            $dateRange[] = floor(Carbon::now()->minute(0)->second(0)->subHours($i)->timestamp / 3600);
        }

        return ['start' => $startDate, 'end' => $endDate, 'range' => $dateRange];
    }
}
