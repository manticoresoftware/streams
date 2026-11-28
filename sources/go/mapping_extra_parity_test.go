package main

import (
    "encoding/json"
    "os"
    "testing"
)

// Check both the PQ mapping and Kafka output; URL rules have a distinct
// failure mode from text rules when a JSONPath returns a collection.
func TestLegacyURLSelectorParity(t *testing.T) {
    if os.Getenv("STREAMS_COMPAT_CLASSPATH") == "" {
        t.Skip("requires original Java mapper")
    }
    raw := `{"before":"first","after":"last","url.value":"https://example.com/a","link":"https://example.com/b","links":[{"url":"https://example.com/x","active":true},{"url":"https://example.com/y","active":false}]}`
    cases := []struct { name, path string }{
        {"quoted url field", "['url.value']"},
        {"indexed url field", "links[0].url"},
        {"quoted union url", "links[0]['url','active']"},
        {"numeric union url", "links[0,1].url"},
        {"filter url", "links[?(@.active == true)].url"},
        {"wildcard url", "links[*].url"},
        {"filter after wildcard", "links[*][?(@.active == true)].url"},
        {"array length url", "links.length()"},
        {"string length url", "link.length()"},
    }
    for _, mode := range []string{"1000","0100","1010","0010"} {
        for _, tc := range cases {
            t.Run(mode+"/"+tc.name,func(t *testing.T) {
                m:=newMapper([]string{"before => before",tc.path+" => url","after => after"},
                    map[string]string{"before":"text","url":"url","after":"text"},mode)
                defer closeMappingOracle(m)
                if m.hasUnsupportedRule {t.Fatalf("requires Java at startup: %q",tc.path)}
                mapped,out,err:=m.transform(raw)
                if mappingUnsupportedCount(m)!=0 {t.Fatalf("Java fallback for %q",tc.path)}
                wantMapped,wantOut,refErr:=m.referenceMap(raw)
                if (err!=nil)!=(refErr!=nil) {t.Fatalf("Go error %v; Java error %v",err,refErr)}
                if err!=nil {return}
                a,e1:=json.Marshal(mapped); b,e2:=json.Marshal(wantMapped)
                if e1!=nil||e2!=nil {t.Fatalf("JSON encoding: %v / %v",e1,e2)}
                if !sameFixtureJSON(string(a),string(b))||!sameFixtureJSON(out,wantOut) {
                    t.Fatalf("path %q mode %s: Go mapped=%s out=%q; Java mapped=%s out=%q",
                        tc.path,mode,a,out,b,wantOut)
                }
            })
        }
    }
}

// Java's wildcard switch is triggered by a raw "[*]" substring, even
// when the wildcard occurs inside a quoted property name.
func TestLegacyQuotedWildcardArrayParity(t *testing.T) {
    if os.Getenv("STREAMS_COMPAT_CLASSPATH") == "" {
        t.Skip("requires original Java mapper")
    }
    cases := []struct{name,raw string}{
        {"array", `{"foo[*]":["one","two"]}`},
        {"scalar", `{"foo[*]":"literal"}`},
        {"empty array", `{"foo[*]":[]}`},
    }
    for _,mode:=range []string{"1000","0100","1010","0010"}{
        for _,tc:=range cases{
            t.Run(mode+"/"+tc.name,func(t *testing.T){
                m:=newMapper([]string{"['foo[*]'] => selected"},map[string]string{"selected":"text"},mode)
                defer closeMappingOracle(m)
                if m.hasUnsupportedRule {t.Fatal("requires Java at startup")}
                mapped,out,err:=m.transform(tc.raw)
                if mappingUnsupportedCount(m)!=0 {t.Fatal("Java fallback")}
                wantMapped,wantOut,refErr:=m.referenceMap(tc.raw)
                if (err!=nil)!=(refErr!=nil) {t.Fatalf("Go %v / Java %v",err,refErr)}
                if err!=nil{return}
                a,e1:=json.Marshal(mapped);b,e2:=json.Marshal(wantMapped)
                if e1!=nil||e2!=nil {t.Fatal(e1,e2)}
                if !sameFixtureJSON(string(a),string(b))||!sameFixtureJSON(out,wantOut) {
                    t.Fatalf("mode %s: Go mapped=%s out=%q; Java mapped=%s out=%q",
                        mode,a,out,b,wantOut)
                }
            })
        }
    }
}
