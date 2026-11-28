package main

import (
	"golang.org/x/text/cases"
	"golang.org/x/text/language"
)

// Use Unicode full lowercase mappings and contextual rules, including Greek
// final sigma and multi-rune mappings. Instantiate a caser for each batch;
// a Caser is stateful and must not be shared across concurrent calls.
func lowerBatch(text string) (string, error) {
	return cases.Lower(language.Und).String(text), nil
}
