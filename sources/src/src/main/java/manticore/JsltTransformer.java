package manticore;

import com.fasterxml.jackson.databind.JsonNode;
import com.fasterxml.jackson.databind.ObjectMapper;
import com.schibsted.spt.data.jslt.Expression;
import com.schibsted.spt.data.jslt.Parser;

public class JsltTransformer {
    private final String config;

    public JsltTransformer(String config) {
        this.config = config;
    }

    public String transform(String content) {
        try {
            ObjectMapper mapper = new ObjectMapper();
            JsonNode input = mapper.readTree(content);
            Expression jslt = Parser.compileString(config);
            JsonNode output = jslt.apply(input);

            return output.toString();
        } catch (Exception e) {
            Worker.getLogger().error("[JsltTransformer] Failed to transform content: {}", e.getMessage());
            return null;
        }
    }
}