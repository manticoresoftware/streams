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

class JsonTransformerOutputParametrizedTest {

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
        transformer.transform(inputJson.toString());
        assertEquals(expectedResult.toString(), transformer.getOutputDocs(), "Transformation result mismatch");
    }

    private static Stream<Object[]> provideTestData() {
        return Stream.of(
                generateTestCase0(),
                generateTestCase1(),
                generateTestCase2(),
                generateTestCase3(),
                generateTestCase4(),
                generateTestCase5(),
                generateTestCase6(),
                generateTestCase7(),
                generateEdgeCase_EmptyRules(),
                generateEdgeCase_UnexpectedFieldType()
        );
    }

    private static Object[] generateTestCase0() {
        JSONObject inputJson = new JSONObject();
        inputJson.put("my_key", "my_string_value");
        inputJson.put("my_key_2", 34);
        inputJson.put("my_key_array", new JSONArray("[7,54,\"string\"]"));
        inputJson.put("my_key_object", new JSONObject("{\"first_key\":\"abc\",\"second_key\":\"72\"}"));

        Map<String, String> manticoreFields = new HashMap<>();
        manticoreFields.put("result_string", "text");
        manticoreFields.put("result_object_key_int", "int");

        JSONObject expectedResult = new JSONObject();

        return new Object[] { inputJson, getRules(), manticoreFields, "0000", expectedResult };
    }

    private static Object[] generateTestCase1() {
        JSONObject inputJson = new JSONObject();
        inputJson.put("my_key", "my_string_value");
        inputJson.put("my_key_2", 34);
        inputJson.put("my_key_array", new JSONArray("[7,54,\"string\"]"));
        inputJson.put("my_key_object", new JSONObject("{\"first_key\":\"abc\",\"second_key\":\"72\"}"));

        List<String> rules = Arrays.asList("my_key => result_string", "my_key_object.second_key => result_object_key_int");

        Map<String, String> manticoreFields = new HashMap<>();
        manticoreFields.put("result_string", "text");
        manticoreFields.put("result_object_key_int", "int");

        JSONObject expectedResult = new JSONObject();
        expectedResult.put("result_string", "my_string_value");
        expectedResult.put("result_object_key_int", "72");

        return new Object[] { inputJson, rules, manticoreFields, "1000", expectedResult };
    }

    private static Object[] generateTestCase2() {
        JSONObject inputJson = new JSONObject();
        inputJson.put("my_key", "my_string_value");
        inputJson.put("my_key_2", 34);
        inputJson.put("my_key_array", new JSONArray("[7,54,\"string\"]"));
        inputJson.put("my_key_object", new JSONObject("{\"first_key\":\"abc\",\"second_key\":\"72\"}"));

        List<String> rules = Arrays.asList("my_key => result_string", "my_key_object.second_key => result_object_key_int");

        Map<String, String> manticoreFields = new HashMap<>();
        manticoreFields.put("result_string", "text");
        manticoreFields.put("result_object_key_int", "int");

        JSONObject expectedResult = new JSONObject("{\"my_key_object\":{\"second_key\":\"72\"},\"my_key\":\"my_string_value\"}");
        return new Object[] { inputJson, rules, manticoreFields, "0100", expectedResult };
    }

    private static Object[] generateTestCase3() {
        JSONObject inputJson = new JSONObject();
        inputJson.put("my_key", "my_string_value");
        inputJson.put("my_key_2", 34);
        inputJson.put("my_key_array", new JSONArray("[7,54,\"string\"]"));
        inputJson.put("my_key_object", new JSONObject("{\"first_key\":\"abc\",\"second_key\":\"72\"}"));

        Map<String, String> manticoreFields = new HashMap<>();
        manticoreFields.put("result_string", "text");
        manticoreFields.put("result_object_key_int", "int");

        JSONObject expectedResult = new JSONObject("{\"my_key_object\":{" +
                "\"first_key\":\"abc\",\"second_key\":\"72\"" +
                "},\"my_key\":\"my_string_value\",\"my_key_2\":34,\"my_key_array\":[7,54,\"string\"]}");

        return new Object[] { inputJson, getRules(), manticoreFields, "0010", expectedResult };
    }

    private static Object[] generateTestCase4() {
        JSONObject inputJson = new JSONObject();
        inputJson.put("my_key", "my_string_value");
        inputJson.put("my_key_2", 34);
        inputJson.put("my_key_array", new JSONArray("[7,54,\"string\"]"));
        inputJson.put("my_key_object", new JSONObject("{\"first_key\":\"abc\",\"second_key\":\"72\"}"));

        Map<String, String> manticoreFields = new HashMap<>();
        manticoreFields.put("result_string", "text");
        manticoreFields.put("result_object_key_int", "int");

        return new Object[] { inputJson, getRules(), manticoreFields, "0001", new JSONObject() };
    }

    private static Object[] generateTestCase5() {
        JSONObject inputJson = new JSONObject();
        inputJson.put("my_key", "Some text with URL https://manticoresearch.com");
        inputJson.put("my_key_2", 34);
        inputJson.put("my_key_array", new JSONArray("[7,54,\"string\"]"));
        inputJson.put("my_key_object", new JSONObject("{\"first_key\":\"abc\",\"second_key\":\"72\"}"));

        Map<String, String> manticoreFields = new HashMap<>();
        manticoreFields.put("result_string", "url");
        manticoreFields.put("result_object_key_int", "int");

        JSONObject expectedResult = new JSONObject(
                "{\"result_string_host_path\":\"4D236D9A2D102C5FE6AD1C50DA4BEC50 ED0BD936751E99C169B7F662E46CE192\"," +
                        "\"result_object_key_int\":\"72\"}"
        );

        return new Object[] { inputJson, getRules(), manticoreFields, "1000", expectedResult };
    }

    private static Object[] generateTestCase6() {
        JSONObject inputJson = new JSONObject();
        inputJson.put("my_key", "Some text with URL https://manticoresearch.com?d=");
        inputJson.put("my_key_2", 34);
        inputJson.put("my_key_array", new JSONArray("[7,54,\"string\"]"));
        inputJson.put("my_key_object", new JSONObject("{\"first_key\":\"abc\",\"second_key\":\"72\"}"));

        Map<String, String> manticoreFields = new HashMap<>();
        manticoreFields.put("result_string", "url");
        manticoreFields.put("result_object_key_int", "int");

        JSONObject expectedResult = new JSONObject(
                "{\"result_string_query\":\"2B1DAEFDDC0D56CBDFF55AC78BC602BC\"," +
                        "\"result_string_host_path\":\"4D236D9A2D102C5FE6AD1C50DA4BEC50 ED0BD936751E99C169B7F662E46CE192\"," +
                        "\"result_object_key_int\":\"72\"}"
        );

        return new Object[] { inputJson, getRules(), manticoreFields, "1000", expectedResult };
    }

    private static Object[] generateTestCase7() {
        JSONObject inputJson = new JSONObject();
        inputJson.put("my_key", "Some text with URL https://manticoresearch.com?d=\\uD83D\\uDD25");
        inputJson.put("my_key_2", 34);
        inputJson.put("my_key_array", new JSONArray("[7,54,\"string\"]"));
        inputJson.put("my_key_object", new JSONObject("{\"first_key\":\"abc\",\"second_key\":\"72\"}"));

        Map<String, String> manticoreFields = new HashMap<>();
        manticoreFields.put("result_string", "url");
        manticoreFields.put("result_object_key_int", "int");

        JSONObject expectedResult = new JSONObject(
                "{\"result_string_query\":\"2B1DAEFDDC0D56CBDFF55AC78BC602BC\"," +
                        "\"result_string_host_path\":\"4D236D9A2D102C5FE6AD1C50DA4BEC50 ED0BD936751E99C169B7F662E46CE192\"," +
                        "\"result_object_key_int\":\"72\"}"
        );

        return new Object[] { inputJson, getRules(), manticoreFields, "1000", expectedResult };
    }

    private static Object[] generateEdgeCase_EmptyRules() {
        JSONObject inputJson = new JSONObject("{\"my_key\":\"test_value\"}");
        return new Object[] { inputJson, List.of(), Map.of(), "1000", new JSONObject() };
    }

    private static Object[] generateEdgeCase_UnexpectedFieldType() {
        JSONObject inputJson = new JSONObject("{\"my_key\":12345}");
        List<String> rules = List.of("my_key => result_string");
        Map<String, String> manticoreFields = Map.of("result_string", "text");
        JSONObject expectedResult = new JSONObject("{\"my_key\":12345}");
        return new Object[] { inputJson, rules, manticoreFields, "0010", expectedResult };
    }

    private static List<String> getRules() {
        return List.of(
                "my_key => result_string",
                "my_key_object.second_key => result_object_key_int"
        );
    }
}