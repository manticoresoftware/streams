<?php

namespace App\Services;

use App\Models\Streams;
use App\Services\Curl\CurlService;

class GraphBuilderService
{
    public const SECTION_MATCHING_DOCS = 'matching-docs';
    public const SECTION_PROCESSED_DOCS = 'processed-docs';
    public const SECTION_RULES = 'rules';
    public const SECTION_PROCESSED_RULES = 'processed-rules';
    public const SECTION_PROCESSING_LAG = 'processing-lag';

    private string $streamId = '';
    private array $sections = [];
    private ColumnarService $columnar;
    private int $interval = 1;

    public function __construct($streamId, ColumnarService $columnarService)
    {
        if (in_array(app()->environment(), ['dev', 'testing'])) {
            $this->setStreamId(null);
        }else{
            $this->setStreamId($streamId);
        }

        $this->columnar = $columnarService;
        $this->columnar->setStream($streamId);
    }

    public function getColumnarErrors(){
        return $this->columnar->getError();
    }

    public function setStreamId($streamId)
    {
        if ( ! empty($streamId)) {
            $this->streamId = "m".$streamId;
        }


        $this->sections = [
            self::SECTION_MATCHING_DOCS   => [
                'query'     => $this->streamId.'_handler_matched_docs',
                'title'     => '',
                'axisTitle' => 'Matching docs'
            ],
            self::SECTION_PROCESSED_DOCS  => [
                'query'     => $this->streamId.'_handler_processed_docs',
                'title'     => '',
                'axisTitle' => 'Docs',
            ],
            self::SECTION_RULES           => [
                'query'     => $this->streamId.'_manticore_rules',
                'title'     => '',
                'axisTitle' => 'Rules',
            ],
            self::SECTION_PROCESSED_RULES => [
                'query'     => $this->streamId.'_handler_processed_rules',
                'title'     => 'Rule processing',
                'axisTitle' => 'Docs count',
            ],
            self::SECTION_PROCESSING_LAG  => [
                'query'     => $this->streamId.'_consumer_lag',
                'title'     => '',
                'axisTitle' => 'Lag, documents'
            ],
        ];
    }

    public function getRuleData($ruleID, $from, $to): array
    {
        return $this->prepareData(
            $this->getResults(
                $this->sections[self::SECTION_PROCESSED_RULES]['query'],
                $ruleID,
                $from,
                $to
            ),
            $this->sections[self::SECTION_PROCESSED_RULES]['axisTitle']
        );
    }


    public function getData($section, $tag, $from, $to): array
    {
        return $this->prepareData(
            $this->getResults($this->sections[$section]['query'], $tag, $from, $to),
            $this->sections[$section]['axisTitle']
        );
    }

    public function getResults($section, $tag, $dateFrom, $dateTo): array
    {
        $this->interval = (int) (($dateTo - $dateFrom) / 1000);

        if ($this->interval < 1) {
            $this->interval = 1;
        }

        if ($section === $this->streamId.''.$this->sections['rules']['query']) {
            $operator = 'avg';
        } else {
            $operator = 'sum';
        }

        return $this->columnar->getGraph($operator, $this->interval, $tag, $section, $dateFrom, $dateTo);
    }

    private function prepareData($data, $axisTitle): array
    {
        if ($data['values'] === [] && $data['categories'] === []) {
            return [];
        }
        $avgValue = 0;
        foreach ($data['values'] as $v) {
            $avgValue += $v;
        }

        $sum      = $avgValue;
        $avgValue /= count($data['values']);

        $avgValue = round($avgValue / $this->interval, 2);

        foreach ($data['categories'] as $row) {
            $data['average'][] = $avgValue;
        }

        return [
            "type"    => 'line',
            "data"    => [
                "labels"   => $data['categories'],
                "datasets" => [
                    [
                        "label"                => "(avg)".$axisTitle,
                        "data"                 => $data['average'],
                        "pointBackgroundColor" => '#ffadad',
                        "backgroundColor"      => [
                            '#ffadad',
                        ],
                        "borderColor"          => [
                            '#ffadad',
                        ],
                        "borderWidth"          => 2,
                        "fill"                 => false,
                        "tension"              => 0,
                    ],
                    [
                        "label"                => $axisTitle,
                        "data"                 => $data['values'],
                        "pointBackgroundColor" => '#007bff',
                        "backgroundColor"      => [
                            '#007bff',
                        ],
                        "borderColor"          => [
                            '#007bff',
                        ],
                        "borderWidth"          => 2,
                        "fill"                 => false,
                        "tension"              => 0,
                    ],
                ],
            ],
            "options" => [
                "responsive"          => true,
                "maintainAspectRatio" => false,
                "tooltips"            => [
                    "intersect" => false,
                ],
                "elements"            => [
                    "point" => [
                        "radius" => 0,
                    ],
                ],

                "animation" => [
                    "duration" => 0,
                ],
                "legend"    => [
                    "display" => false,
                ],
                "scales"    => [
                    "yAxes" => [
                        [
                            "ticks"      => [
                                "beginAtZero" => true,
                            ],
                            "display"    => true,
                            "scaleLabel" => [
                                "display"     => true,
                                "labelString" => $axisTitle,
                            ],
                        ],
                    ],
                    "xAxes" => [
                        [
                            "gridLines" => [
                                "display" => false,
                            ],
                        ],
                    ],
                ],
            ],
            'append'  => [
                'average' => $avgValue,
                'sum'     => $sum,
            ],
        ];
    }
}
