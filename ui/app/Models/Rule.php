<?php

namespace App\Models;


use App\Services\VariablesService;
use JsonSerializable;

class Rule implements JsonSerializable
{

    private int $id = 0;
    private string $query = '';
    private RuleTags $tags;
    private string $filters = '';
    private array $statistic = [];

    public function __construct()
    {
        $this->setTags();
    }

    /**
     * Fill rule form Manticore response json
     *
     * @throws \JsonException
     */
    public function init($rule, $needRestore = false): Rule
    {
        if(isset($rule['id'])){
            $this->id      = $rule['id'];
        }

        if (isset($rule['query'])){
            $this->query   = $rule['query'];
        }

        if (isset($rule['filters'])){
            $this->filters = $rule['filters'];
        }
        if (isset($rule['tags'])){
            $this->tags->init($rule['tags']);

            if ($needRestore) {
                $this->restoreFromOrigin();
            }
        }


        return $this;
    }

    /**
     * @return int
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * @param  int  $id
     */
    public function setId(?int $id): void
    {
        if ($id === null) {
            $id = 0;
        }

        $this->id = $id;
    }

    /**
     * @return string
     */
    public function getQuery(): string
    {
        return $this->query;
    }

    /**
     * @return string
     */
    public function getQueryWithVariableSubstituted(): string
    {
        if ($this->query !== '') {
            return $this->replaceVariablesInString($this->query);
        }

        return $this->query;
    }

    /**
     * @param  string|null  $query
     */
    public function setQuery(?string $query): void
    {
        if ($query === null) {
            $query = '';
        }

        if ($this->query !== $query) {
            $this->query = $query;
            $this->tags->setOwnQuery($query);
            $this->checkAndGetVariables($query);
        }
    }

    private function checkAndGetVariables($text)
    {
        if ($this->hasPlaceholder($text)) {
            $this->getVariablesFromString($text);
        }
    }

    /**
     * @return RuleTags
     */
    public function getTags(): RuleTags
    {
        return $this->tags;
    }


    private function setTags(): void
    {
        $this->tags = new RuleTags();
    }

    /**
     * @throws \JsonException
     */
    public function getJsonTags(): string
    {
        return json_encode($this->tags, JSON_THROW_ON_ERROR);
    }

    /**
     * @return string
     */
    public function getFilters(): string
    {
        return $this->filters;
    }

    /**
     * @return string
     */
    public function getFiltersWithVariableSubstituted(): string
    {
        if ($this->filters !== '') {
            return $this->replaceVariablesInString($this->filters);
        }

        return $this->filters;
    }

    /**
     * @param  string|null  $filters
     */
    public function setFilters(?string $filters): void
    {
        if ($filters === null) {
            $filters = '';
        }

        if ($this->filters !== $filters) {
            $this->filters = $filters;
            $this->tags->setOwnFilters($filters);
            $this->checkAndGetVariables($filters);
        }
    }


    public function hasVariables(): bool
    {
        return count($this->getTags()->getVariables()) > 0;
    }

    public function setStatistic($statistic): void
    {
        $this->statistic = $statistic;
    }

    public function jsonSerialize()
    {
        return [
            'id'        => (string) $this->id,
            'query'     => $this->getQuery(),
            'tags'      => $this->getJsonTags(),
            'filters'   => $this->getFilters(),
            'statistic' => $this->statistic,
        ];
    }

    private function replaceVariablesInString($str)
    {
        foreach ($this->tags->getVariables() as $name => $text) {
            $str = str_replace("{{".$name."}}", $text, $str);
        }

        return $str;
    }

    private function hasPlaceholder($text): bool
    {
        return strpos($text, '{{') !== false && strpos($text, '}}') !== false;
    }

    private function hasEscaping($text): bool
    {
        return strpos($text, '\{\{') !== false && strpos($text, '\}\}') !== false;
    }


    private function getVariablePlaceholders($string)
    {
        preg_match_all('/{{([a-z0-9_]*?)}}/', $string, $matches);

        if (isset($matches[1])) {
            return $matches[1];
        }

        return false;
    }

    private function getVariablesFromString($string): void
    {
        $matches = $this->getVariablePlaceholders($string);

        if (isset($matches)) {
            foreach ($matches as $variableName) {
                $name     = trim($variableName);
                $variable = VariablesService::getInstance()->getByName($name);

                if ( ! $variable) {
                    throw new \RuntimeException("Variable non exist");
                }
                $this->tags->addVariable($name, $variable);
            }
        }
    }

    public function removeVariable(Variable $variable)
    {
        $name = $variable->name;
        $this->tags->setOwnQuery(str_replace('{{'.$name.'}}', '', $this->tags->getOwnQuery()));
        $this->tags->setOwnFilters(str_replace('{{'.$name.'}}', '', $this->tags->getOwnFilters()));
        $this->query   = $this->tags->getOwnQuery();
        $this->filters = $this->tags->getOwnFilters();
        $this->tags->removeVariable($variable->name);
    }

    public function replaceVariable(Variable $variable): void
    {
        $this->tags->addVariable($variable->name, $variable->text);
        $this->restoreFromOrigin();
    }


    public function restoreFromOrigin(): void
    {
        if ($this->hasVariables() || $this->hasEscaping($this->tags->getOwnQuery()) || $this->hasEscaping($this->tags->getOwnFilters())) {
            $this->query   = $this->tags->getOwnQuery();
            $this->filters = $this->tags->getOwnFilters();
        }
    }

    public function decodeEscaping()
    {
        if ($this->hasEscaping($this->tags->getOwnQuery())) {
            $this->query = str_replace(['\{\{', '\}\}'], ['{{', '}}'], $this->query);
        }

        if ($this->hasEscaping($this->tags->getOwnFilters())) {
            $this->filters = str_replace(['\{\{', '\}\}'], ['{{', '}}'], $this->filters);
        }
    }

}
