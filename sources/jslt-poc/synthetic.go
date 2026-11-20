package main

// Public, invented examples of the JSLT features used by production rules.
// Never copy customer expressions or input messages into this repository.
func syntheticCases() []parityCase {
	return []parityCase{
		{name: "nested fields and defaults", expression: "let a = .article {\"Id\": if ($a.id) $a.id else \"\", \"Subject\": if ($a.subject) $a.subject else \"\", \"MatchingQueries\": .matching_queries}", input: "{\"article\":{\"id\":\"post-1\",\"subject\":\"Hello\"},\"matching_queries\":[{\"queryid\":7}]}"},
		{name: "def and fallback", expression: "def getValue(data) if ($data.title) $data.title else \"untitled\" {\"title\": getValue(.entry)}", input: "{\"entry\":{\"id\":\"sample\"}}"},
		{name: "date ISO8601 offset", expression: "{\"ts\": round(parse-time(.date, \"yyyy-MM-dd'T'HH:mm:ssX\") * 1000)}", input: "{\"date\":\"2026-11-20T12:00:00Z\"}"},
		{name: "date ISO8601 no offset", expression: "{\"ts\": round(parse-time(.date, \"yyyy-MM-dd'T'HH:mm:ss\"))}", input: "{\"date\":\"2026-11-20T12:00:00\"}"},
		{name: "date SQL format", expression: "{\"ts\": parse-time(.date, \"yyyy-MM-dd HH:mm:ss\")}", input: "{\"date\":\"2026-11-20 12:00:00\"}"},
		{name: "date only", expression: "{\"ts\": parse-time(.date, \"yyyy-MM-dd\")}", input: "{\"date\":\"2026-11-20\"}"},
		{name: "date invalid fallback", expression: "{\"ts\": parse-time(.date, \"yyyy-MM-dd\", \"invalid\")}", input: "{\"date\":\"not-a-date\"}"},
		{name: "date format UTC", expression: "{\"date\": format-time(1795176000, \"yyyy-MM-dd HH:mm:ss\")}", input: "{}"},
		{name: "URL host and scheme", expression: "let u = parse-url(.link) {\"host\": $u.host, \"scheme\": $u.scheme, \"query\": $u.query}", input: "{\"link\":\"https://example.org/news?id=2\"}"},
		{name: "URL with port and fragment", expression: "{\"url\":parse-url(.link)}", input: "{\"link\":\"https://reader:pass@example.org:8443/a?q=one#part\"}"},
		{name: "URL without path", expression: "{\"url\": parse-url(.link)}", input: "{\"link\":\"https://example.org\"}"},
		{name: "CSV provider identifiers", expression: "def ids(p) [for (split($p, \",\")) let n = number(.) $n] {\"ids\": ids(.providers)}", input: "{\"providers\":\"12,34,56\"}"},
		{name: "dynamic rating keys", expression: "def ratings(obj) {for ($obj) \"Rating@name=\" + .key: number(.value, 3)} {\"ratings\": ratings(.ratings)}", input: "{\"ratings\":{\"taste\":\"4.125\",\"service\":\"3.5\"}}"},
		{name: "HTML cleanup", expression: "{\"clean\": replace(.content, \"<[^>]+>\", \"\")}", input: "{\"content\":\"Hello <b>world</b>!\"}"},
		{name: "regex capture group", expression: "{\"preview\": capture(.content, \"(?<text>.{0,5})\").text}", input: "{\"content\":\"some message\"}"},
		{name: "nested get-key", expression: "{\"id\":get-key(.fields, \"site.id\", \"\"), \"value\":get-key(.fields, \"missing\", \"none\")}", input: "{\"fields\":{\"site.id\":\"alpha\"}}"},
		{name: "lowercase and numeric casts", expression: "{\"type\":lowercase(.type),\"count\":string(.count),\"rate\":round(number(.rate) * 10)}", input: "{\"type\":\"HELLO\",\"count\":15,\"rate\":\"2.6\"}"},
		{name: "collections and conditions", expression: "{\"children\":[for (.items) if (.enabled) .id], \"hasAny\":any([.first,.second])}", input: "{\"items\":[{\"id\":\"a\",\"enabled\":true},{\"id\":\"b\",\"enabled\":false}],\"first\":null,\"second\":\"yes\"}"},
		{name: "Unicode size", expression: "{\"characters\":size(.value)}", input: "{\"value\":\"привет\"}"},
		{name: "empty/null filtering", expression: "{\"a\":.missing,\"b\":{},\"c\":[], \"d\":\"ok\"}", input: "{}"},
	}
}
