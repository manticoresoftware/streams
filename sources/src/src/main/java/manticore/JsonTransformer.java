package manticore;

import java.net.URI;
import java.net.URISyntaxException;
import java.util.*;
import java.util.regex.Matcher;
import java.util.regex.Pattern;

import com.google.common.net.UrlEscapers;
import com.jayway.jsonpath.DocumentContext;
import com.jayway.jsonpath.JsonPath;
import com.jayway.jsonpath.PathNotFoundException;
import com.jsoniter.JsonIterator;
import com.jsoniter.any.Any;
import com.jsoniter.output.JsonStream;
import org.apache.commons.codec.digest.DigestUtils;
import net.minidev.json.JSONArray;
import org.json.JSONException;
import org.slf4j.LoggerFactory;

import ch.qos.logback.classic.Logger;

public class JsonTransformer {
    public static final String RULE_SIDE_DELIMITER = "=>";
    private static final Pattern urlPattern = Pattern.compile(
            "(?:^|[\\W])((ht|f)tp(s?):\\/\\/|www\\.)"
                    + "(([\\w\\-]+\\.){1,}?([\\w\\-.~]+\\/?)*"
                    + "[\\p{Alnum}.,%_=?&#\\-+()\\*$~@!:/{};']*)",
            Pattern.CASE_INSENSITIVE | Pattern.MULTILINE | Pattern.DOTALL);

    private final Boolean approvedNew;
    private final Boolean approvedOriginal;
    private final Boolean noTransform;
    private final Boolean unsetFromSource;
    private final List<String> rules;
    private final Logger logger;
    private final Map<String, String> fieldTypes;

    private Map<String, Any> outputResult;
    private DocumentContext outputJsonNode;

    public JsonTransformer(WorkerConfig config) {
        logger = (Logger) LoggerFactory.getLogger(Logger.ROOT_LOGGER_NAME);
        this.rules = config.getRules();
        this.fieldTypes = config.getManticoreFields();
        String outputDocsFormat = config.getOutputDocs();
        approvedNew = ("1".equals(String.valueOf(outputDocsFormat.charAt(0))));
        approvedOriginal = ("1".equals(String.valueOf(outputDocsFormat.charAt(1))));
        noTransform = ("1".equals(String.valueOf(outputDocsFormat.charAt(2))));

        unsetFromSource = approvedNew && noTransform;
    }

    public String transform(String json) {
        Map<String, Any> result = new HashMap<>();

        try {
            if (rules == null) {
                logger.debug("[JsonTransformer] No rules provided, returning original JSON");
                return json;
            }

            DocumentContext doc = JsonPath.parse(json);

            if (noTransform) {
                outputResult = JsonIterator.deserialize(json).asMap();
            } else {
                outputResult = new HashMap<>();
            }

            if (unsetFromSource) {
                outputJsonNode = JsonPath.parse(json);
            }

            for (String item : rules) {
                String[] splittedKeys = item.split(JsonTransformer.RULE_SIDE_DELIMITER);

                if (splittedKeys.length != 2) {
                    logger.error("[JsonTransformer] Invalid rule format: {}", item);
                    continue;
                }

                String inputPath = splittedKeys[0].trim();
                String outputPath = splittedKeys[1].trim();

                String[] inputPathArray;
                if (inputPath.contains("&&")) {
                    inputPathArray = inputPath.split("&&");
                } else {
                    inputPathArray = new String[]{inputPath};
                }
                //skip if we have duplication
                if (result.get(outputPath) != null) {
                    continue;
                }

                String value = "";
                StringBuilder sb = new StringBuilder();

                for (String inputPathNode : inputPathArray) {
                    if (inputPathNode.equals("whole_document")) {
                        result.put(outputPath, JsonIterator.deserialize(json));
                        continue;
                    }

                    if (inputPathNode.contains("{")) {
                        // Allow to substitute key
                        //
                        // connection.{type}.code returns 200
                        //
                        // {
                        //    "type": "http",
                        //    "connection": {
                        //        "http": {
                        //            "code": 200
                        //        },
                        //        "mysql": {
                        //            "code": "ok"
                        //        }
                        //    }
                        // }
                        //
                        int startPosition = inputPathNode.indexOf("{") + 1;
                        int endPosition = inputPathNode.indexOf("}");

                        String recursiveKey = inputPathNode.substring(startPosition, endPosition);
                        StringBuilder sbr = new StringBuilder();
                        sbr.append("$.").append(recursiveKey);

                        String recursiveValue;
                        try {
                            recursiveValue = doc.read(sbr.toString()).toString();
                        } catch (PathNotFoundException e) {
                            continue;
                        }

                        sbr = new StringBuilder();
                        sbr.append("{").append(recursiveKey).append("}");

                        inputPathNode = inputPathNode.replace(sbr.toString(), recursiveValue);
                    }

                    if (checkFieldType(outputPath).equals("url")) {
                        try {
                            String nodeValue = doc.read("$." + inputPathNode);
                            if (nodeValue == null) {
                                value = "";
                                continue;
                            }

                            value = nodeValue.toString();

                            List<String> urls = getUrls(value);

                            for (String url : urls) {
                                hashUrl(url, outputPath, result);
                            }


                        } catch (PathNotFoundException e) {
                            value = "";
                            continue;
                        }
                        value = "";
                        continue;
                    }
                    sb = new StringBuilder();
                    sb.append("$.").append(inputPathNode);
                    try {
                        String nodeValue;

                        if (inputPathNode.contains("[*]")) {
                            JSONArray nodes = doc.read(sb.toString());

                            StringBuilder nodeStringBuilder = new StringBuilder();

                            for (Object node : nodes) {
                                nodeStringBuilder.append(node).append("\n");
                            }

                            nodeValue = nodeStringBuilder.toString();

                        } else {
                            nodeValue = doc.read(sb.toString());
                        }

                        if (nodeValue == null) {
                            nodeValue = "null";
                        }

                        if (value.length() > 0) {
                            value = value + "\n" + nodeValue;
                        } else {
                            value = nodeValue;
                        }

                        if (unsetFromSource) {
                            outputJsonNode.delete(sb.toString());
                        }

                    } catch (PathNotFoundException | ClassCastException ignored) {
                    }

                }

                if (unsetFromSource) {
                    outputJsonNode.put("$", outputPath, value);
                } else {
                    if (approvedOriginal) {
                        sb.delete(0, 2);

                        Map<String, Any> lastJsonObject;
                        Map<String, Any> tmpJson = new HashMap<>();

                        String[] inputKeyParts = sb.toString().split("\\.");

                        Collections.reverse(Arrays.asList(inputKeyParts));
                        for (String keyTemp : inputKeyParts) {
                            if (tmpJson.isEmpty()) {
                                tmpJson.put(keyTemp, Any.wrap(value));
                                continue;
                            }

                            lastJsonObject = new HashMap<>(tmpJson);
                            tmpJson = new HashMap<>();
                            tmpJson.put(keyTemp, Any.wrap(lastJsonObject));
                        }

                        deepMerge(tmpJson, outputResult);
                    }
                }

                if (value.length() > 0) {
                    result.put(outputPath, Any.wrap(value));
                }
            }

            if (unsetFromSource) {
                outputResult = JsonIterator.deserialize(outputJsonNode.jsonString()).asMap();
            } else if (approvedNew) {
                outputResult = result;
            }

        } catch (Exception e) {
            logger.error("[JsonTransformer] Failed to transform JSON: {}", e.getMessage());
            logger.trace("[JsonTransformer] Exception:", e);
        }

        return JsonStream.serialize(result);
    }

    public String getOutputDocs() {
        return JsonStream.serialize(outputResult);
    }

    public void clean() {
        outputResult = null;
        outputJsonNode = null;
    }

    public static void deepMerge(Map<String, Any> source, Map<String, Any> target) throws JSONException {
        for (String key : source.keySet()) {
            Any value = source.get(key);
            if (target.get(key) == null) {
                // new value for "key":
                target.put(key, value);
            } else {
                // existing value for "key" - recursively deep merge:
                Map<String, Any> as = value.asMap();
                if (as != null) {
                    //JSONObject valueJson = (JSONObject) value;
                    deepMerge(value.asMap(), target.get(key).asMap());
                } else {
                    target.put(key, Any.wrap(value));
                }
            }
        }
    }

    private List<String> getUrls(String text) {
        ArrayList<String> results = new ArrayList<>();

        Matcher matcher = urlPattern.matcher(text);
        while (matcher.find()) {
            int matchStart = matcher.start(1);
            int matchEnd = matcher.end();
            results.add(text.substring(matchStart, matchEnd));
        }

        return results;
    }

    private void hashUrl(String url, String fieldName, Map<String, Any> result) {
        URI uri;
        try {
            uri = new URI(url);
        } catch (URISyntaxException e) {
            try {
                String encodedString = UrlEscapers.urlFragmentEscaper().escape(url);
                uri = new URI(encodedString);
            } catch (Exception es) {
                logger.warn("[JsonTransformer] Failed to parse URL {}: {}", url, es.getMessage());
                return;
            }
        }

        StringBuilder sb = new StringBuilder();

        if (uri.getHost() != null) {
            String[] matches = uri.getHost().split("\\.");
            List<String> reversedMatches = new ArrayList<>(Arrays.asList(matches));
            Collections.reverse(reversedMatches);

            for (String exploded : reversedMatches) {
                if (sb.toString().length() > 0) {
                    String existingString = sb.toString();
                    sb = new StringBuilder();
                    sb.append(exploded).append(".").append(existingString);
                } else {
                    sb.append(exploded);
                }

                Any hostPath = result.get(fieldName + "_host_path");
                if (hostPath != null) {
                    result.put(fieldName + "_host_path", Any.wrap(hostPath.toString() + " " + hashMD5(sb.toString())));
                } else {
                    result.put(fieldName + "_host_path", Any.wrap(hashMD5(sb.toString())));
                }
            }
        }

        sb = new StringBuilder();
        if (uri.getPath() != null) {
            String[] matches = uri.getPath().split("/");
            List<String> reversedMatches = new ArrayList<>(Arrays.asList(matches));

            for (String exploded : reversedMatches) {
                if (exploded.length() == 0) {
                    continue;
                }

                sb.append("/").append(exploded);

                Any hostPath = result.get(fieldName + "_host_path");
                if (hostPath != null) {
                    result.put(fieldName + "_host_path", Any.wrap(hostPath.toString() + " " + hashMD5(sb.toString())));
                } else {
                    result.put(fieldName + "_host_path", Any.wrap(hashMD5(sb.toString())));
                }
            }
        }

        if (uri.getQuery() != null) {
            Any query = result.get(fieldName + "_query");
            if (query != null) {
                result.put(fieldName + "_query", Any.wrap(query.toString() + " " + hashMD5(uri.getQuery().replace("&", " "))));
            } else {
                result.put(fieldName + "_query", Any.wrap(hashMD5(uri.getQuery().replace("&", " "))));
            }
        }

        if (uri.getFragment() != null) {
            Any anchor = result.get(fieldName + "_anchor");
            if (anchor != null) {
                result.put(fieldName + "_anchor", Any.wrap(anchor.toString() + " " + hashMD5(uri.getFragment())));
            } else {
                result.put(fieldName + "_anchor", Any.wrap(hashMD5(uri.getFragment())));
            }
        }
    }

    private String checkFieldType(String field) {
        return fieldTypes.get(field);
    }

    private String hashMD5(String url) {
        return DigestUtils.md5Hex(url).toUpperCase();
    }
}