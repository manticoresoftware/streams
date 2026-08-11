<?php

define("URL_PATTERN", '#\bhttps?://[^\s()<>]+(?:\([\w\d]+\)|([^[:punct:]\s]|/))#');
$stage = getenv('STAGE');
if ($stage == 'dev') {
    $path = "../../dev-environment/rules-checker/";
} else {
    $path = '/storage/';
}

$rules           = [];
$envRules        = getenv('TRANSFORM_RULES');
$manticoreFields = getenv('MANTICORE_FIELDS');
$pipeline        = getenv('PIPELINE');

if (empty($pipeline)){
    die('You need to pass pipeline');
}

$envRules        = explode('|', $envRules);
$manticoreFields = explode('|', $manticoreFields);

$nodeTypes = [];
foreach ($manticoreFields as $field) {
    $field                = explode('=', $field);
    $nodeTypes[$field[1]] = $field[0];
}


$keys = [];
foreach ($envRules as $rule) {

    $rule = explode(' => ', $rule);


    $mergedRules = null;
    if (strpos($rule[0], "&&") !== false) {
        $mergedRules = explode("&&", $rule[0]);
    }

    if ($mergedRules) {
        foreach ($mergedRules as $k => $mergedRule) {
            if ($nodeTypes[$rule[1]] === "url") {
                $keys = array_merge($keys, urlizedNames($rule[1]));
            } else {
                $keys [$rule[1]] = '';
            }

            if (strpos($rule[0], '{') !== false) {
                $mergedRules[$k] = getRulePathByPlaceholder($mergedRule);
            } else {
                $mergedRules[$k] = explode('.', $mergedRule);
            }
        }

        $rules[] = ['from' => $mergedRules, 'to' => $rule[1], 'type' => $nodeTypes[$rule[1]]];


    } else {
        if ($nodeTypes[$rule[1]] === "url") {
            $keys = array_merge($keys, urlizedNames($rule[1]));
        } else {
            $keys [$rule[1]] = '';
        }

        if (strpos($rule[0], '{') !== false) {
            $rule[0] = getRulePathByPlaceholder($rule[0]);
            $rules[] = ['from' => [$rule[0]], 'to' => $rule[1], 'type' => $nodeTypes[$rule[1]]];
            continue;
        }

        $rules[] = ['from' => [explode('.', $rule[0])], 'to' => $rule[1], 'type' => $nodeTypes[$rule[1]]];
    }
}


$handle = fopen($path . 'messages.dat', "r");
if ($handle) {
    $transformed = [];
    while (($line = fgets($handle)) !== false) {
        $message            = json_decode($line, true);
        $transformedMessage = $keys;
        foreach ($rules as $rule) {
            foreach ($rule['from'] as $item) {
                $jsonData = getFromJson($item, $message);
                if ($jsonData) {
                    if ( empty($transformedMessage[$rule['to']])) {
                        $transformedMessage[$rule['to']] = $jsonData;
                    } else {
                        $transformedMessage[$rule['to']] .= "\n" . $jsonData;
                    }
                }
            }

        }

        foreach ($transformedMessage as $k => $v) {
            if ( ! empty($v) && isset($nodeTypes[$k]) && $nodeTypes[$k] === "url") {
                if (preg_match_all(URL_PATTERN, $v, $matches) !== false) {
                    foreach ($matches[0] as $matchedUrl) {
                        $hashedUrl = urlize($k, $matchedUrl);
                        foreach ($hashedUrl as $fieldName => $value) {
                            if (isset($transformedMessage[$fieldName])) {
                                $transformedMessage[$fieldName] = $value;
                            } else {
                                $transformedMessage[$fieldName] .= "\n" . $value;
                            }
                        }
                    }

                    unset($transformedMessage[$k]);
                }
            }
        }

        if ($transformedMessage != $keys) {
            $transformed[] = $transformedMessage;
        }
    }
    fclose($handle);
} else {
    die('Error opening file');
}

echo "Prepared ".count($transformed)." messages for insertion into Manticore\n";

if (empty($transformed)) {
    die('Input file are empty');
}

$connection = new Mysqli(getenv('MANTICORE_HOST') . ':' . getenv('MANTICORE_PORT'));

if ($connection->connect_error) {
    die('Can\'t connect to Manticore');
}

$connection->query('TRUNCATE TABLE '.$pipeline.'_cluster:tests');

$indexFields = array_keys($keys);

$insertRows = [];
foreach ($transformed as $row) {
    $row       = array_map(function ($n) {
        return escapeString($n);
    }, $row);

    $insertRows[] = "('" . mb_strtolower(implode("','", array_values($row))) . "')";
}

$i = 0;
foreach (array_chunk($insertRows, 500) as $data) {
    $query = "INSERT into ".$pipeline."_cluster:tests (`".mb_strtolower(implode('`,`', $indexFields))."`) ".
        "VALUES ".implode(",", $data);
    $connection->query($query);

    if ($connection->error) {
        echo "Error during insert rules to Manticore ".$connection->error."\n";
    } else {
        $i += count($data);
    }

    #echo "Inserted ".$i."\n";
}

if ($i > 0){
    echo "Inserted ".$i."\n";
}


$count = $connection->query("SELECT count(*) FROM tests");
if ( ! empty($count)) {
    $count = $count->fetch_row();
    $count = $count[0];
    file_put_contents($path . 'count.dat', $count);
}

function escapeString($string)
{
    $from = ['\\', '(', ')', '|', '!', '@', '~', '"', '&', '/', '^', '$', '=', '<', "'"];
    $to   = ['\\\\', '\(', '\)', '\|', '\!', '\@', '\~', '\"', '\&', '\/', '\^', '\$', '\=', '\<', "\'"];

    return str_replace($from, $to, $string);
}

function getFromJson($keys, $message)
{
    if ($keys[0] === 'whole_document') {
        return json_encode($message);
    }
    $jsonData = $message;
    foreach ($keys as $path) {

        if (is_array($path)) {
            $path = getFromJson($path, $message);
        }

        if (isset($jsonData[$path])) {
            $jsonData = $jsonData[$path];
        } else {
            return false;
        }
    }

    return $jsonData;
}

function getRulePathByPlaceholder($rule)
{
    preg_match('/{.*?}/usi', $rule, $matches);
    if ( ! empty($matches[0])) {
        $rule = str_replace($matches[0], '###', $rule);
    }
    $rule = explode('.', $rule);
    foreach ($rule as $k => $v) {
        if ($v == '###') {
            $matches[0] = substr($matches[0], 1, -1);
            $matches[0] = explode('.', $matches[0]);
            $rule[$k]   = $matches[0];
        }
    }

    return $rule;
}

function urlizedNames($fieldName)
{
    return [$fieldName . '_host_path' => '', $fieldName . '_query' => '', $fieldName . '_anchor' => ''];
}

function urlize($fieldName, $in): array
{
    $in  = trim($in);
    $out = [$fieldName . '_host_path' => [], $fieldName . '_query' => '', $fieldName . '_anchor' => ''];
    if ( ! parse_url($in, PHP_URL_SCHEME) and $in[0] != "/") {
        $in = "https://" . "$in";
    }
    $s = '';
    if ($host = parse_url($in, PHP_URL_HOST)) {
        $ar = preg_split('/(\.)/', $host);
        $ar = array_reverse($ar);
        foreach ($ar as $k => $v) {
            $s                                = $v . ($s ? ("." . $s) : '');
            $out[$fieldName . '_host_path'][] = md5($s);
        }
    }
    $s = '';
    if ($path = parse_url($in, PHP_URL_PATH)) {
        $ar = preg_split('/(\/)/', $path);
        foreach ($ar as $k => $v) {
            if ( ! $v) {
                continue;
            }
            $s                                .= "/" . $v;
            $out[$fieldName . '_host_path'][] = md5($s);
        }
    }
    $out[$fieldName . '_host_path'] = implode(' ', $out[$fieldName . '_host_path']);
    if ($query = parse_url($in, PHP_URL_QUERY)) {
        $out[$fieldName . '_query'] = md5(preg_replace('/&/', ' ', $query));
    }
    if ($anchor = parse_url($in, PHP_URL_FRAGMENT)) {
        $out[$fieldName . '_anchor'] = md5($anchor);
    }

    return $out;
}
