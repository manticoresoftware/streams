package manticore;

import org.junit.jupiter.api.Test;

import static org.junit.jupiter.api.Assertions.*;

class PQRowTest {

    @Test
    void checkPQRow() {
        Long uId = 5000L;
        String query = "Some query text";
        String tags = "Some tags";
        String filters = "some filters";
        PQRow pqRow = new PQRow(uId, query, tags, filters);

        assertSame(uId, pqRow.getUID());
        assertSame(query, pqRow.getQuery());
        assertSame(tags, pqRow.getTags());
        assertSame(filters, pqRow.getFilters());
        assertFalse(pqRow.getHighlighted());

        pqRow.setHighlighted(true);
        assertTrue(pqRow.getHighlighted());
    }
}