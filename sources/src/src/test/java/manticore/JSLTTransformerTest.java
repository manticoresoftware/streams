package manticore;

import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.Test;

import static org.junit.jupiter.api.Assertions.assertEquals;

class JSLTTransformerTest {

    private JsltTransformer transformer;

    @BeforeEach
    void setUp() {
        // No Mockito needed here, just initializing the transformer in each test
        String jsltConf = "let idparts = split(.id, \"-\")\n" +
                "let xxx = [for ($idparts) \"x\" * size(.)]\n" +
                "\n" +
                "{\n" +
                "  \"id\" : join($xxx, \"-\"),\n" +
                "  \"type\" : \"Anonymized-View\",\n" +
                "  * : .\n" +
                "}";
        transformer = new JsltTransformer(jsltConf);
    }

    @Test
    void jsltTransformsData() {
        String transformData = "{\n" +
                "  \"id\" : \"w23q7ca1-8729-24923-922b-1c0517ddffjf1\",\n" +
                "  \"type\" : \"View\"\n" +
                "}\n";

        String expectedTransformation = "{\"id\":\"xxxxxxxx-xxxx-xxxxx-xxxx-xxxxxxxxxxxxx\",\"type\":\"Anonymized-View\"}";

        assertEquals(expectedTransformation, transformer.transform(transformData));
    }
}