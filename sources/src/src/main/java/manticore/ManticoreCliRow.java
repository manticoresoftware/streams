package manticore;

import org.json.JSONObject;

public class ManticoreCliRow {
    private final JSONObject data;

    public ManticoreCliRow(JSONObject data) {
        this.data = data;
    }

    public JSONObject raw() {
        return data;
    }

    public String optString(String key) {
        return data.optString(key, "");
    }

    public int optInt(String key) {
        return data.optInt(key, 0);
    }

    public long optLong(String key) {
        return data.optLong(key, 0L);
    }

    public boolean has(String key) {
        return data.has(key);
    }
}
