<?php

namespace App\Models;


use App\Services\VariablesService;
use JsonSerializable;

class RuleTags implements JsonSerializable
{

    private string $tag = '';
    private string $inserted = '';
    private string $updated = '';
    private string $originalQuery = '';
    private string $externalQuery = '';
    /**
     * Store origin query to substitute on variable edit
     *
     * @var string
     */
    private string $ownQuery = '';
    /**
     * Store origin filters to substitute on variable edit
     *
     * @var string
     */
    private string $ownFilters = '';
    private bool $highlighting = false;
    private $variables = [];


    /**
     * @throws \JsonException
     */
    public function init($tags): void
    {
        try {
            $tags = json_decode($tags, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            $defaultTags['tag'] = $tags;
            $tags = $defaultTags;
            unset($defaultTags);
        }


        if (!isset($tags['tag'])) {
            $tags['tag'] = '';
        }

        if (!isset($tags['inserted'])) {
            $tags['inserted'] = '';
        }
        if (!isset($tags['updated'])) {
            $tags['updated'] = '';
        }
        if (!isset($tags['originalQuery'])) {
            $tags['originalQuery'] = '';
        }
        if (!isset($tags['externalQuery'])) {
            $tags['externalQuery'] = '';
        }
        if (!isset($tags['ownQuery'])) {
            $tags['ownQuery'] = '';
        }
        if (!isset($tags['ownFilters'])) {
            $tags['ownFilters'] = '';
        }
        if (!isset($tags['highlighting'])) {
            $tags['highlighting'] = '';
        }

        $this->setTag($tags['tag']);
        $this->setInserted($tags['inserted']);
        $this->setUpdated($tags['updated']);
        $this->setOriginalQuery($tags['originalQuery']);
        $this->setExternalQuery($tags['externalQuery']);
        $this->setOwnQuery($tags['ownQuery']);
        $this->setOwnFilters($tags['ownFilters']);
        $this->setHighlighting($tags['highlighting']);

        if (isset($tags['variables']) && $tags['variables'] !== '') {
            $this->initVariables(explode("|", $tags['variables']));
        }
    }

    private function initVariables(array $variables)
    {
        foreach ($variables as $variableName) {
            $variableName = substr($variableName, 1, -1);
            $name = trim($variableName);
            $var = VariablesService::getInstance()->getByName($name);
            if ($var !== null) {
                $this->addVariable($name, $var);
            }
        }
    }

    /**
     * @param  null  $tag
     */
    public function setTag(?string $tag): void
    {
        if ($tag === null) {
            $tag = '';
        }
        $this->tag = $tag;
    }

    /**
     * @param  null  $inserted
     */
    public function setInserted($inserted): void
    {
        $this->inserted = $inserted;
    }

    /**
     * @param  null  $updated
     */
    public function setUpdated($updated): void
    {
        $this->updated = $updated;
    }

    /**
     * @param  string|null  $originalQuery
     */
    public function setOriginalQuery(?string $originalQuery): void
    {
        if ($originalQuery === null) {
            $originalQuery = '';
        }
        $this->originalQuery = $originalQuery;
    }

    /**
     * @param  string|null  $externalQuery
     */
    public function setExternalQuery(?string $externalQuery): void
    {
        if ($externalQuery === null) {
            $externalQuery = '';
        }

        $this->externalQuery = $externalQuery;
    }

    /**
     * @param  bool  $highlighting
     */
    public function setHighlighting($highlighting): void
    {
        $this->highlighting = $highlighting;
    }

    /**
     * @param  array  $variables
     */
//    public function setVariables(array $variables): void
//    {
//        foreach ($variables as $variable) {
//            $this->addVariable($variable);
//        }
//    }

    /**
     * @return string
     */
    public function getTag(): string
    {
        return $this->tag;
    }

    /**
     * @return string
     */
    public function getInserted(): string
    {
        return $this->inserted;
    }

    /**
     * @return string
     */
    public function getUpdated(): string
    {
        return $this->updated;
    }

    /**
     * @return string
     */
    public function getOriginalQuery(): string
    {
        return $this->originalQuery;
    }

    /**
     * @return string
     */
    public function getExternalQuery(): string
    {
        return $this->externalQuery;
    }

    /**
     * @return string
     */
    public function getHighlighting(): bool
    {
        return $this->highlighting;
    }

    /**
     * @return array
     */
    public function getVariables(): array
    {
        return $this->variables;
    }

    /**
     * @return string
     */
    public function getOwnQuery(): string
    {
        return $this->ownQuery;
    }

    /**
     * @param  string  $ownQuery
     */
    public function setOwnQuery(string $ownQuery): void
    {
        $this->ownQuery = $ownQuery;
    }

    /**
     * @return string
     */
    public function getOwnFilters(): string
    {
        return $this->ownFilters;
    }

    /**
     * @param  string  $ownFilters
     */
    public function setOwnFilters(string $ownFilters): void
    {
        $this->ownFilters = $ownFilters;
    }

    public function addVariable($name, $text): void
    {
        $this->variables[$name] = $text;
    }

    public function removeVariable($name)
    {
        unset($this->variables[$name]);
    }

    public function jsonSerialize(): array
    {
        $variablesString = '';
        if (count($this->getVariables()) > 0) {
            $variablesString = '-'.implode('-|-', array_keys($this->variables)).'-';
        }

        return [
            'tag' => $this->tag,
            'inserted' => $this->inserted,
            'updated' => $this->updated,
            'originalQuery' => $this->originalQuery,
            'externalQuery' => $this->externalQuery,
            'ownQuery' => $this->ownQuery,
            'ownFilters' => $this->ownFilters,
            'highlighting' => $this->highlighting,
            'variables' => $variablesString,
        ];
    }
}
