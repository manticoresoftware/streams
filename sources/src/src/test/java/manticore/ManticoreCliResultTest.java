package manticore;

import org.json.JSONArray;
import org.json.JSONObject;
import org.junit.jupiter.api.Test;

import static org.junit.jupiter.api.Assertions.*;

class ManticoreCliResultTest {

    @Test
    void testIsEmptyWhenNoResultSets() {
        ManticoreCliResult result = new ManticoreCliResult(new JSONArray());

        assertTrue(result.isEmpty());
        assertNull(result.firstRow());
        assertEquals(0, result.firstInt("cnt"));
    }

    @Test
    void testFirstIntAndHasRowWithValue() {
        JSONArray data = new JSONArray()
                .put(new JSONObject().put("cnt", 7).put("Table", "pq"))
                .put(new JSONObject().put("cnt", 9).put("Table", "metrics"));
        JSONArray resultSets = new JSONArray()
                .put(new JSONObject().put("data", data).put("error", ""));

        ManticoreCliResult result = new ManticoreCliResult(resultSets);

        assertFalse(result.isEmpty());
        assertEquals(7, result.firstInt("cnt"));
        assertTrue(result.hasRowWithValue("Table", "metrics"));
        assertFalse(result.hasRowWithValue("Table", "unknown"));
    }

    @Test
    void testThrowsWhenResultContainsError() {
        JSONArray resultSets = new JSONArray()
                .put(new JSONObject().put("data", new JSONArray()).put("error", "broken query"));

        RuntimeException exception = assertThrows(RuntimeException.class, () -> new ManticoreCliResult(resultSets));

        assertEquals("broken query", exception.getMessage());
    }
}
