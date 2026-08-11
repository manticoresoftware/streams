<?php

namespace App\Providers;

class ProcessCreationService
{

    public const STOPWORDS_MOUNT_PATH = '/etc/manticoresearch/conf_mount/stopwords.txt';
    public const EXCEPTIONS_MOUNT_PATH = '/etc/manticoresearch/conf_mount/stopwords.txt';

    private $pathByType = [
        'exceptions' => self::EXCEPTIONS_MOUNT_PATH,
        'stopwords' => self::STOPWORDS_MOUNT_PATH
    ];

    public function formatMorphology($language, $morphology): array
    {
        $langSection = ['morphology' => [], 'charset_table' => []];
        foreach ($language as $item) {
            if (!array_key_exists($item, $morphology)) {
                $item = 'chinese';
                $backupConf['language'] = $item;
            }

            $langSection['morphology'][] = $morphology[$item]['morphology'];

            $langSection['charset_table'] = array_merge(
                $langSection['charset_table'],
                $morphology[$item]['charset_table']
            );
            if (isset($morphology[$item]['stopwords'])) {
                $langSection['stopwords'][] = $morphology[$item]['stopwords'];
            }
        }

        $morphology = [];
        foreach ($langSection as $k => $v) {
            $v = array_unique($v);
            $morphology[] = $k." = '".implode(', ', $v)."'";
        }

        return $morphology;
    }

    public function formatExceptions($nlpSettings)
    {
        return $this->commonNLPFormat('exceptions', $nlpSettings);
    }

    public function formatStopWords($nlpSettings)
    {
        return $this->commonNLPFormat('stopwords', $nlpSettings);
    }

    public function decodeQuote($text){
        return str_replace('&quot','"', $text);
    }

    private function commonNLPFormat($section, $nlpSettings)
    {
        $mountPath = '/etc/manticoresearch/conf_mount/'.$section.'.txt';

        if (strpos($nlpSettings, $section) !== false) {
            $nlpSettings = preg_replace_callback(
                "/".$section."\s*=\s*'(.*?)'/usi",
                function ($matches) use ($mountPath) {
                    return str_replace($matches[1], $mountPath, $matches[0]);
                },
                $nlpSettings
            );
        } else {
            $nlpSettings .= " $section='$mountPath'";
        }

        return $nlpSettings;
    }
}
