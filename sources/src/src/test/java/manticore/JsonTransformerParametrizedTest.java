package manticore;

import org.json.JSONArray;
import org.json.JSONObject;
import org.junit.jupiter.params.ParameterizedTest;
import org.junit.jupiter.params.provider.MethodSource;

import java.util.*;
import java.util.stream.Stream;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.mockito.Mockito.mock;
import static org.mockito.Mockito.when;

class JsonTransformerParametrizedTest {

    @ParameterizedTest(name = "{index} => outputDocs={3}")
    @MethodSource("provideTestData")
    void jsonTransformsData(JSONObject inputJson, List<String> rules, Map<String, String> manticoreFields,
                            String outputDocs, JSONObject expectedResult) {
        // Create a mock WorkerConfig for testing
        WorkerConfig mockConfig = mock(WorkerConfig.class);
        when(mockConfig.getOutputDocs()).thenReturn(outputDocs);
        when(mockConfig.getRules()).thenReturn(rules);
        when(mockConfig.getManticoreFields()).thenReturn(manticoreFields);

        JsonTransformer transformer = new JsonTransformer(mockConfig);
        String transformed = transformer.transform(inputJson.toString());
        assertEquals(expectedResult.toString(), transformed);
    }

    private static Stream<Object[]> provideTestData() {
        // Test Case #1
        JSONObject inputJson1 = new JSONObject();
        inputJson1.put("my_key", "my_string_value");
        inputJson1.put("my_key_2", 34);
        inputJson1.put("my_key_array", new JSONArray("[7,54,\"string\"]"));
        inputJson1.put("my_key_object", new JSONObject("{\"first_key\":\"abc\",\"second_key\":\"72\"}"));

        List<String> rules1 = new ArrayList<>();
        rules1.add("my_key => result_string");
        rules1.add("my_key_object.second_key => result_object_key_int");

        Map<String, String> manticoreFields1 = new HashMap<>();
        manticoreFields1.put("result_string", "text");
        manticoreFields1.put("result_object_key_int", "int");

        JSONObject expectedResult1 = new JSONObject();
        expectedResult1.put("result_string", "my_string_value");
        expectedResult1.put("result_object_key_int", "72");

        // Test Case #2
        JSONObject inputJson2 = new JSONObject();
        inputJson2.put("my_key", "my_string_value");
        inputJson2.put("my_key_2", 34);
        inputJson2.put("my_key_object", new JSONObject("{\"first_key\":\"abc\",\"second_key\":72}"));
        inputJson2.put("my_key_array", new JSONArray("[7,54,\"string\"]"));

        List<String> rules2 = new ArrayList<>();
        rules2.add("my_key => result_string");
        rules2.add("my_key_object.second_key => result_object_key_int");

        Map<String, String> manticoreFields2 = new HashMap<>();
        manticoreFields2.put("result_string", "text");
        manticoreFields2.put("result_object_key_int", "int");

        JSONObject expectedResult2 = new JSONObject();
        expectedResult2.put("result_string", "my_string_value");

        // Test Case #3
        JSONObject inputJson3 = new JSONObject();
        inputJson3.put("my_key", "my_string_value");
        inputJson3.put("my_key_2", 34);
        inputJson3.put("my_key_object", new JSONObject("{\"first_key\":\"abc\",\"second_key\":\"72\"}"));
        inputJson3.put("my_key_array", new JSONArray("[7,54,\"string\"]"));

        List<String> rules3 = new ArrayList<>();
        rules3.add("whole_document => json");
        rules3.add("my_key => result_string");
        rules3.add("my_key_object.second_key => result_object_key_int");

        Map<String, String> manticoreFields3 = new HashMap<>();
        manticoreFields3.put("result_string", "text");
        manticoreFields3.put("result_object_key_int", "int");
        manticoreFields3.put("json", "json");

        JSONObject expectedResult3 = new JSONObject();
        expectedResult3.put("result_string", "my_string_value");
        expectedResult3.put("json", inputJson3);
        expectedResult3.put("result_object_key_int", "72");

        // Test Case #4
        JSONObject inputJson4 = new JSONObject();
        inputJson4.put("my_key", "my_string_value");
        inputJson4.put("my_key_2", 34);
        inputJson4.put("my_key_object", new JSONObject("{\"first_key\":\"abc\",\"second_key\":\"72\"}"));
        inputJson4.put("my_key_array", new JSONArray("[7,54,\"string\"]"));
        inputJson4.put("url_field", "Has URL to https://manticoresearch.com/section/?uri=123#anchor");

        List<String> rules4 = new ArrayList<>();
        rules4.add("my_key => result_string");
        rules4.add("my_key_object.second_key => result_object_key_int");
        rules4.add("url_field => url_field");

        Map<String, String> manticoreFields4 = new HashMap<>();
        manticoreFields4.put("result_string", "text");
        manticoreFields4.put("result_object_key_int", "int");
        manticoreFields4.put("url_field", "url");

        JSONObject expectedResult4 = new JSONObject();
        expectedResult4.put("result_string", "my_string_value");
        expectedResult4.put("result_object_key_int", "72");
        expectedResult4.put("url_field_query", "18C3F9B988D0508B07E3DC6605D9D531");
        expectedResult4.put("url_field_host_path", "4D236D9A2D102C5FE6AD1C50DA4BEC50 ED0BD936751E99C169B7F662E46CE192 FA873ECD0A4D123077DD9156A2A0A18F");
        expectedResult4.put("url_field_anchor", "47AE9EC4C0978A1293D1030E30034B8A");

        // Test Case #5
        JSONArray jsa = new JSONArray();
        jsa.put(new JSONObject("{\"id\":\"1\",\"param\":\"some string\"}"));
        jsa.put(new JSONObject("{\"id\":\"2\",\"param\":\"some string\"}"));
        jsa.put(new JSONObject("{\"id\":\"3\",\"param\":\"some string\"}"));
        jsa.put(new JSONObject("{\"id\":\"4\",\"param\":\"some string\"}"));
        jsa.put(new JSONObject("{\"id\":\"5\",\"param\":\"some string\"}"));

        JSONObject inputJson5 = new JSONObject();
        inputJson5.put("my_key", "my_string_value");
        inputJson5.put("my_iterable_key_array", jsa);

        List<String> rules5 = new ArrayList<>();
        rules5.add("my_key => result_string");
        rules5.add("my_iterable_key_array[*].id => my_iterable_key_array");

        Map<String, String> manticoreFields5 = new HashMap<>();
        manticoreFields5.put("result_string", "text");
        manticoreFields5.put("result_object_key_int", "int");
        manticoreFields5.put("my_iterable_key_array", "json");

        JSONObject expectedResult5 = new JSONObject();
        expectedResult5.put("result_string", "my_string_value");
        expectedResult5.put("my_iterable_key_array", "1\n2\n3\n4\n5\n");

        // Test Case #6
        JSONObject inputJson6 = new JSONObject();
        inputJson6.put("my_key", "my_string_value");
        inputJson6.put("merged_key_1", "abc");
        inputJson6.put("merged_key_2", "def");

        List<String> rules6 = new ArrayList<>();
        rules6.add("my_key => result_string");
        rules6.add("merged_key_1&&merged_key_2 => my_merged_results");

        Map<String, String> manticoreFields6 = new HashMap<>();
        manticoreFields6.put("result_string", "text");
        manticoreFields6.put("my_merged_results", "text");

        JSONObject expectedResult6 = new JSONObject();
        expectedResult6.put("result_string", "my_string_value");
        expectedResult6.put("my_merged_results", "abc\ndef");

        // Test Case #7
        JSONObject inputJson7 = new JSONObject();
        inputJson7.put("my_key", "my_string_value");

        // Test Case #8
        JSONObject inputJson8 = new JSONObject();
        inputJson8.put("my_key", "my_string_value");
        inputJson8.put("merged_key_1", "abc");
        inputJson8.put("merged_key_2", "def");

        List<String> rules8 = new ArrayList<>();
        rules8.add("my_key => result_string");
        rules8.add("merged_key_1");

        Map<String, String> manticoreFields8 = new HashMap<>();
        manticoreFields8.put("result_string", "text");

        JSONObject expectedResult8 = new JSONObject();
        expectedResult8.put("result_string", "my_string_value");

        // Test Case #9
        JSONObject inputJson9 = new JSONObject();
        inputJson9.put("my_key", "my_string_value");

        List<String> rules9 = new ArrayList<>();
        rules9.add("my_key => result_string");
        rules9.add("my_key => result_string");

        Map<String, String> manticoreFields9 = new HashMap<>();
        manticoreFields9.put("result_string", "text");

        JSONObject expectedResult9 = new JSONObject();
        expectedResult9.put("result_string", "my_string_value");

        // Test Case #10
        JSONObject inputJson10 = new JSONObject("{\"type\":\"http\",\"connection\":{\"http\":{\"code\":\"200\"}," +
                "\"mysql\":{\"code\":\"ok\"}}}");

        List<String> rules10 = new ArrayList<>();
        rules10.add("connection.{type}.code => result_substituted");
        rules10.add("connection.{wrong_key}.code => result_substituted_wrong");

        Map<String, String> manticoreFields10 = new HashMap<>();
        manticoreFields10.put("result_substituted", "text");
        manticoreFields10.put("result_substituted_wrong", "text");

        JSONObject expectedResult10 = new JSONObject();
        expectedResult10.put("result_substituted", "200");

        // Test Case #11
        JSONObject inputJson11 = new JSONObject();
        inputJson11.put("my_key", "my_string_value");
        inputJson11.put("my_concat_key", "my_concat_string_value");
        inputJson11.put("my_iterable_key_array", jsa);

        List<String> rules11 = new ArrayList<>();
        rules11.add("my_key => result_string");
        rules11.add("my_concat_key&&my_iterable_key_array[*].id => my_iterable_key_array");

        Map<String, String> manticoreFields11 = new HashMap<>();
        manticoreFields11.put("result_string", "text");
        manticoreFields11.put("result_object_key_int", "int");
        manticoreFields11.put("my_iterable_key_array", "string");

        JSONObject expectedResult11 = new JSONObject();
        expectedResult11.put("result_string", "my_string_value");
        expectedResult11.put("my_iterable_key_array", "my_concat_string_value\n1\n2\n3\n4\n5\n");

        return Stream.of(
                new Object[] { inputJson1, rules1, manticoreFields1, "0011", expectedResult1 },
                new Object[] { inputJson2, rules2, manticoreFields2, "0011", expectedResult2 },
                new Object[] { inputJson3, rules3, manticoreFields3, "0011", expectedResult3 },
                new Object[] { inputJson4, rules4, manticoreFields4, "0011", expectedResult4 },
                new Object[] { inputJson5, rules5, manticoreFields5, "0011", expectedResult5 },
                new Object[] { inputJson6, rules6, manticoreFields6, "0011", expectedResult6 },
                new Object[] { inputJson7, null, null, "0011", inputJson7 },
                new Object[] { inputJson8, rules8, manticoreFields8, "0011", expectedResult8 },
                new Object[] { inputJson9, rules9, manticoreFields9, "0011", expectedResult9 },
                new Object[] { inputJson10, rules10, manticoreFields10, "0011", expectedResult10 },
                new Object[] { inputJson11, rules11, manticoreFields11, "0011", expectedResult11 }
        );
    }
}