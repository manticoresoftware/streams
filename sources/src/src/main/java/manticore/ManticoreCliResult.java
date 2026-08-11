package manticore;

import org.json.JSONArray;
import org.json.JSONObject;

import java.util.ArrayList;
import java.util.Collections;
import java.util.List;

public class ManticoreCliResult {
    private final JSONArray resultSets;
    private final List<ManticoreCliRow> rows;

    public ManticoreCliResult(JSONArray resultSets) {
        this.resultSets = resultSets;
        validate();
        this.rows = extractRows();
    }

    public boolean isEmpty() {
        return rows.isEmpty();
    }

    public List<ManticoreCliRow> rows() {
        return rows;
    }

    public ManticoreCliRow firstRow() {
        return rows.isEmpty() ? null : rows.get(0);
    }

    public JSONArray rawResultSets() {
        return resultSets;
    }

    public boolean hasRowWithValue(String key, String value) {
        for (ManticoreCliRow row : rows) {
            if (value.equals(row.optString(key))) {
                return true;
            }
        }
        return false;
    }

    public int firstInt(String key) {
        ManticoreCliRow row = firstRow();
        return row == null ? 0 : row.optInt(key);
    }

    private List<ManticoreCliRow> extractRows() {
        if (resultSets.isEmpty()) {
            return Collections.emptyList();
        }

        JSONObject resultSet = resultSets.optJSONObject(0);
        if (resultSet == null) {
            return Collections.emptyList();
        }

        JSONArray data = resultSet.optJSONArray("data");
        if (data == null || data.isEmpty()) {
            return Collections.emptyList();
        }

        List<ManticoreCliRow> parsedRows = new ArrayList<>(data.length());
        for (int i = 0; i < data.length(); i++) {
            JSONObject row = data.optJSONObject(i);
            if (row != null) {
                parsedRows.add(new ManticoreCliRow(row));
            }
        }
        return Collections.unmodifiableList(parsedRows);
    }

    private void validate() {
        for (int i = 0; i < resultSets.length(); i++) {
            JSONObject resultSet = resultSets.optJSONObject(i);
            if (resultSet != null) {
                String error = resultSet.optString("error", "");
                if (!error.isEmpty()) {
                    throw new RuntimeException(error);
                }
            }
        }
    }
}
