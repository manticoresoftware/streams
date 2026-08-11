<?php

namespace App\Services;

use App\Models\Rule;
use Symfony\Component\HttpFoundation\Response;

class TsvParser
{
    protected FileCacheService $fileCacheService;

    public function __construct(FileCacheService $fileCacheService)
    {
        $this->fileCacheService = $fileCacheService;
    }
    /**
     * Parse TSV content and process each row into Rules
     *
     * @param string $content TSV content
     * @param ManticoreService $manticoreService
     * @param int $userId User ID for cache increment
     *
     * @return array [processedRows, importErrors]
     * @throws \Exception
     */
    public function parse(string $content, ManticoreService $manticoreService, int $userId): array
    {
        $processedRows = 0;
        $importErrors = [];

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $content);
        rewind($stream);

        $rowNumber = 0;
        while (($row = fgetcsv($stream, 0, "\t")) !== false) {
            $rowNumber++;
            if (empty(array_filter($row))) {
                continue;
            }

            if (count($row) > 6) {
                $importErrors[] = [
                    'message' => "Row {$rowNumber} exceeds maximum of 6 columns",
                    'statusCode' => Response::HTTP_UNPROCESSABLE_ENTITY,
                    'line' => implode("\t", $row)
                ];
                continue;
            }

            $row = array_pad($row, 6, '');
            $row = array_map(function($value) {
                return $value === '' ? null : $value;
            }, $row);

            $ruleEntry = new Rule();
            $ruleEntry->setQuery($row[0] ?? '');
            $ruleEntry->setFilters($row[1] ?? '');
            $ruleEntry->getTags()->setTag($row[2] ?? '');
            $ruleEntry->getTags()->setExternalQuery($row[3] ?? '');
            $ruleEntry->getTags()->setHighlighting(isset($row[4]) && (bool) $row[4]);
            $duplicationCheck = isset($row[5]) && (bool) $row[5];

            // Add rule to Manticore and check status
            $result = $manticoreService->addRule($ruleEntry, null, $duplicationCheck);
            $statusCode = $manticoreService->getStatusCode();

            if ($statusCode !== Response::HTTP_OK) {
                $importErrors[] = [
                    'query' => $ruleEntry->getQuery(),
                    'filters' => $ruleEntry->getFilters(),
                    'tags' => $ruleEntry->getTags()->getTag(),
                    'message' => $result['message'] ?? 'Unknown error',
                    'statusCode' => $statusCode
                ];
            } else {
                $this->fileCacheService->increase($userId, FileCacheService::RULE_ADD);
                $processedRows++;
            }
        }

        fclose($stream);

        if ($processedRows === 0 && empty($importErrors)) {
            throw new \Exception('Empty TSV content');
        }

        return [$processedRows, $importErrors];
    }
}
