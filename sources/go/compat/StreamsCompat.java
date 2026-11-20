import com.fasterxml.jackson.databind.JsonNode;
import com.fasterxml.jackson.databind.ObjectMapper;
import com.fasterxml.jackson.databind.node.ObjectNode;
import com.schibsted.spt.data.jslt.Expression;
import com.schibsted.spt.data.jslt.Parser;
import manticore.JsonTransformer;
import manticore.LoggerProvider;
import manticore.WorkerConfig;

import java.io.*;
import java.nio.charset.StandardCharsets;
import java.util.*;

/** Line-oriented bridge for existing customer JSLT and Jayway JSONPath programs. */
public class StreamsCompat {
    private static final ObjectMapper JSON = new ObjectMapper();

    private interface Transform {
        String apply(String document) throws Exception;
    }

    private static Transform configure(String mode, String config) throws Exception {
        if (mode.equals("jslt")) {
            Expression expression = Parser.compileString(config);
            return document -> expression.apply(JSON.readTree(document)).toString();
        }
        JsonNode settings = JSON.readTree(config);
        List<String> rules = null;
        if (!settings.get("rules").isNull()) {
            rules = new ArrayList<>();
            for (JsonNode rule : settings.get("rules")) {
                rules.add(rule.asText());
            }
        }
        final List<String> mappingRules = rules;
        Map<String, String> fields = new HashMap<>();
        settings.get("fields").fields().forEachRemaining(e -> fields.put(e.getKey(), e.getValue().asText()));
        JsonTransformer mapper = new JsonTransformer(new WorkerConfig(new LoggerProvider()) {
            @Override public String getOutputDocs() { return settings.get("output").asText(); }
            @Override public List<String> getRules() { return mappingRules; }
            @Override public Map<String, String> getManticoreFields() { return fields; }
        });
        return document -> {
            try {
                ObjectNode result = JSON.createObjectNode();
                result.set("mapped", JSON.readTree(mapper.transform(document)));
                result.put("output", mapper.getOutputDocs());
                return result.toString();
            } finally {
                mapper.clean();
            }
        };
    }

    public static void main(String[] args) throws IOException {
        // Reserve stdout for the protocol, including when the existing worker logs.
        // Always use UTF-8 for the line protocol, even with an ASCII locale.
        PrintStream protocol = new PrintStream(new FileOutputStream(FileDescriptor.out), true, StandardCharsets.UTF_8);
        System.setOut(System.err);
        var in = new BufferedReader(new InputStreamReader(System.in, StandardCharsets.UTF_8));
        Transform transform = null;
        String line;
        while ((line = in.readLine()) != null) {
            ObjectNode reply = JSON.createObjectNode();
            try {
                String input = JSON.readValue(line, String.class);
                if (transform == null) {
                    transform = configure(args[0], input);
                    reply.put("data", "");
                } else {
                    reply.put("data", transform.apply(input));
                }
            } catch (Exception e) {
                reply.put("error", e.toString());
            }
            protocol.println(reply);
            protocol.flush();
        }
    }
}
