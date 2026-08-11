package manticore;

class PQRow {
    @SuppressWarnings("unused")
    private final Long UID;
    @SuppressWarnings("unused")
    private final String Query;
    @SuppressWarnings("unused")
    private final String Tags;
    @SuppressWarnings("unused")
    private final String Filters;
    @SuppressWarnings("unused")
    private Boolean Highlighted;

    PQRow(Long uid, String query, String tags, String filters) {
        this.UID = uid;
        this.Query = query;
        this.Tags = tags;
        this.Filters = filters;
        this.Highlighted = false;
    }

    public Boolean getHighlighted() {
        return Highlighted;
    }

    public void setHighlighted(Boolean highlighted) {
        Highlighted = highlighted;
    }

    public String getQuery() {
        return Query;
    }

    public String getFilters() {
        return Filters;
    }

    public String getTags() {
        return this.Tags;
    }

    public Long getUID() {
        return this.UID;
    }
}