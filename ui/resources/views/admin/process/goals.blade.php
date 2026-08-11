<div class="col-10 align-items-center" style="width: 60%;">
    <form id="save-goals-form" method="post" style="width: 100%;">
        <h5 class="caption">Goals</h5>
        <div class="form-row m-2">
            <div class="col-3">
                <label for="process-name">Name</label>
            </div>
            <div class="col-9">
                <input class="form-control {{ $errors->has('name') ? ' is-invalid' : '' }}" type="text"
                       id="process-name" name="name" autocomplete="off" value="{{ old('name') }}">

                @if ($errors->has('name'))
                    <span class="invalid-feedback" role="alert">
                        <strong>{{ $errors->first('name') }}</strong>
                    </span>
                @endif
            </div>
        </div>

        <div class="form-row m-2">
            <div class="col-3">
                <label for="source">Source</label>
            </div>
            <div class="col-9">
                <select type="text" id="source" name="source"
                        class="form-control {{ $errors->has('source') ? ' is-invalid' : '' }}">
                    @foreach($sources as $source)
                        @if (old('source') == $source->id)
                            <option value="{{ $source->id }}" selected>{{ $source->name }}</option>
                        @else
                            <option value="{{ $source->id }}" selected>{{ $source->name }}</option>
                        @endif
                    @endforeach
                </select>

                @if ($errors->has('source'))
                    <span class="invalid-feedback" role="alert">
                        <strong>{{ $errors->first('source') }}</strong>
                    </span>
                @endif
            </div>
        </div>

        <div class="form-row m-2">
            <div class="col-3">
                <label for="destination">Destination</label>
            </div>
            <div class="col-9">
                <select type="text" id="destination" name="destination"
                        class="form-control {{ $errors->has('destination') ? ' is-invalid' : '' }}">
                    @foreach($destinations as $destination)
                        @if (old('destination') == $destination->id)
                            <option value="{{ $destination->id }}" selected>{{ $destination->name }}</option>
                        @else
                            <option value="{{ $destination->id }}">{{ $destination->name }}</option>
                        @endif
                    @endforeach
                </select>

                @if ($errors->has('destination'))
                    <span class="invalid-feedback" role="alert">
                        <strong>{{ $errors->first('destination') }}</strong>
                    </span>
                @endif
            </div>
        </div>

        <h5 class="caption">Output documents</h5>
        <div class="form-row m-2">
            <div class="col-3">
                <input type="radio" id="output-docs-no-transforms"
                       name="output_docs" value="output-docs-no-transforms" checked>
            </div>
            <div class="col-9">
                <label for="output-docs-no-transforms">No input JSON transformation</label>
            </div>
        </div>

        <div class="form-row m-2">
            <div class="col-3">
                <input type="radio" id="output-docs-original"
                       name="output_docs" value="output-docs-original">
            </div>
            <div class="col-9">
                <label for="output-docs-original">Leave only JSON nodes you will approve on the next step with their <b>original</b>
                    names</label>
            </div>
        </div>

        <div class="form-row m-2">
            <div class="col-3">
                <input type="radio" name="output_docs" id="output-docs-modified" value="output-docs-modified">
            </div>
            <div class="col-9">
                <label for="output-docs-modified">Leave only JSON nodes you will approve on the next step with their <b>new</b>
                    names</label>
            </div>
        </div>

        <div class="form-row m-2">
            <div class="col-3">
                <input type="radio" name="output_docs" id="output-docs-modified-no-transforms"
                       value="output-docs-modified-no-transforms">
            </div>
            <div class="col-9">
                <label for="output-docs-modified-no-transforms">Use new names for the JSON nodes you will approve on the
                    next step</label>
            </div>
        </div>

        <div class="form-row m-2">
            <div class="col-3">
                <input type="checkbox" name="output_matching_queries" id="output-matching_queries">
            </div>
            <div class="col-9">
                <label for="output-matching_queries">Include node about matching queries into each output
                    document</label>
            </div>
        </div>

        <h5 class="caption">Scaling</h5>
        <div class="form-row m-2">
            <div class="col-3">
                <label class="" for="max-batch-size">Default batch size</label><br>
            </div>
            <div class="col-9">
                <input type="number" class="form-control" name="max_batch_size" id="max-batch-size" value="5000">
            </div>
        </div>
        <div class="form-row m-2">
            <div class="col-3">
                <label class="" for="min-threads">Minimum workers (pods) per process</label><br>
            </div>
            <div class="col-9">
                <input type="number" class="form-control" name="min_threads" id="min-threads" value="1">
            </div>
        </div>
        <div class="form-row m-2">
            <div class="col-3">
                <label class="" for="max-threads">Maximum workers (pods) per process</label><br>
            </div>
            <div class="col-9">
                <input type="number" class="form-control" name="max_threads" id="max-threads" value="3">
            </div>
        </div>

        <h5 class="caption">Kafka Configuration</h5>
        <div class="form-row m-2">
            <div class="col-3">
                <label for="fetch-min-bytes">fetch.min.bytes</label>
            </div>
            <div class="col-9">
                <input type="number" class="form-control {{ $errors->has('fetch_min_bytes') ? ' is-invalid' : '' }}"
                       name="fetch_min_bytes" id="fetch-min-bytes" value="{{ old('fetch_min_bytes', 1) }}">
                @if ($errors->has('fetch_min_bytes'))
                    <span class="invalid-feedback" role="alert">
                        <strong>{{ $errors->first('fetch_min_bytes') }}</strong>
                    </span>
                @endif
            </div>
        </div>
        <div class="form-row m-2">
            <div class="col-3">
                <label for="fetch-max-wait-ms">fetch.max.wait.ms</label>
            </div>
            <div class="col-9">
                <input type="number" class="form-control {{ $errors->has('fetch_max_wait_ms') ? ' is-invalid' : '' }}"
                       name="fetch_max_wait_ms" id="fetch-max-wait-ms" value="{{ old('fetch_max_wait_ms', 500) }}">
                @if ($errors->has('fetch_max_wait_ms'))
                    <span class="invalid-feedback" role="alert">
                        <strong>{{ $errors->first('fetch_max_wait_ms') }}</strong>
                    </span>
                @endif
            </div>
        </div>
        <div class="form-row m-2">
            <div class="col-3">
                <label for="fetch-max-bytes">fetch.max.bytes</label>
            </div>
            <div class="col-9">
                <input type="number" class="form-control {{ $errors->has('fetch_max_bytes') ? ' is-invalid' : '' }}"
                       name="fetch_max_bytes" id="fetch-max-bytes" value="{{ old('fetch_max_bytes', 1048576) }}">
                @if ($errors->has('fetch_max_bytes'))
                    <span class="invalid-feedback" role="alert">
                        <strong>{{ $errors->first('fetch_max_bytes') }}</strong>
                    </span>
                @endif
            </div>
        </div>
        <div class="form-row m-2">
            <div class="col-3">
                <label for="max-poll-records">max.poll.records</label>
            </div>
            <div class="col-9">
                <input type="number" class="form-control {{ $errors->has('max_poll_records') ? ' is-invalid' : '' }}"
                       name="max_poll_records" id="max-poll-records" value="{{ old('max_poll_records', 500) }}">
                @if ($errors->has('max_poll_records'))
                    <span class="invalid-feedback" role="alert">
                        <strong>{{ $errors->first('max_poll_records') }}</strong>
                    </span>
                @endif
            </div>
        </div>

        <h5 class="caption">Query complexity validation</h5>
        <div class="form-row m-2">
            <div class="col-3">
                <label for="query-complexity-validation">Enabled</label>
            </div>
            <div class="col-9">
                <input type="checkbox" name="query-complexity-validation" id="query-complexity-validation">
            </div>
        </div>
        <div class="form-row m-2">
            <div class="col-3">
                <label class="j-max-matches-percent hidden" for="max-matches-percent">Max matches percent</label>
            </div>
            <div class="col-9">
                <input class="j-max-matches-percent custom-range hidden" type="range" min="1" max="100"
                       name="max-matches-percent" id="max-matches-percent" value="20">
                <span class="font-weight-bold text-primary ml-2 mt-1 hidden" id="j-max-matches-percent-value">20</span>
            </div>
        </div>

        <h5 class="caption">Searchd settings</h5>
        <div class="form-row m-2">
            <div class="col-3">
                <label for="searchd-blacklist-mode-support">Blacklist mode support<br><small>(NOT only operators
                        allowed)</small></label>
            </div>
            <div class="col-9">
                <input type="checkbox" name="searchd-blacklist-mode-support" id="searchd-blacklist-mode-support">
            </div>
        </div>

        <h5 class="caption">Advanced morphology</h5>
        <div class="form-row m-2">
            <div class="col-3">
                <label for="language">
                    <small>
                        By default all texts should be segmented properly into words unless the texts are in Chinese,
                        Japanese or Korean languages. For Chinese choose "Language: Chinese". Choosing the advanced
                        morphology also turns on stopwords, lemmatization and stemming when available.
                        You can choose multiple languages.
                    </small>
                </label>
            </div>
            <div class="col-9">
                <select multiple type="text" id="language" name="language"
                        class="form-control">
                    <option value="russian">Russian</option>
                    <option value="english">English</option>
                    <option value="deuch">German</option>
                    <option value="russian_all">Russian (index all word forms)</option>
                    <option value="english_all">English (index all word forms)</option>
                    <option value="deuch_all">German (index all word forms)</option>
                    <option value="english/russian">English/Russian</option>
                    <option value="arabic">Arabic</option>
                    <option value="basque">Basque</option>
                    <option value="catalan">Catalan</option>
                    <option value="сzech">Czech</option>
                    <option value="danish">Danish</option>
                    <option value="dutch">Dutch</option>
                    <option value="finnish">Finnish</option>
                    <option value="french">French</option>
                    <option value="greek">Greek</option>
                    <option value="hindi">Hindi</option>
                    <option value="hungarian">Hungarian</option>
                    <option value="indonesian">Indonesian</option>
                    <option value="irish">Irish</option>
                    <option value="italian">Italian</option>
                    <option value="lithuanian">Lithuanian</option>
                    <option value="nepali">Nepali</option>
                    <option value="norwegian">Norwegian</option>
                    <option value="portuguese">Portuguese</option>
                    <option value="romanian">Romanian</option>
                    <option value="spanish">Spanish</option>
                    <option value="swedish">Swedish</option>
                    <option value="tamil">Tamil</option>
                    <option value="turkish">Turkish</option>
                    <option value="soundex">Soundex</option>
                    <option value="metaphone">Metaphone</option>
                    <option value="chinese" selected>Chinese</option>
                    <option value="custom">Custom</option>
                </select>
            </div>
        </div>

        <div class="j-nlp-settings-block" style="display: none;">
            <h5 class="caption">NLP settings</h5>
            <div class="form-row m-2">
                <div class="col-3">
                    <label for="nlp_settings">
                        <small>
                            Settings will be applied to Manticore Search as is and index creation may fail in case of
                            improper settings.
                            Please consult with <a
                                href="https://manual.manticoresearch.com/Creating_an_index/Local_indexes/Plain_and_real-time_index_settings#Natural-language-processing-specific-settings"
                                target="_blank">Manticore Search documentation</a> for details
                        </small>
                    </label>
                </div>
                <div class="col-9">
                    <textarea class="form-control" name="nlp_settings" id="nlp_settings" rows=10 placeholder="charset_table= cjk, non_cjk, U+00E4, U+00C4->U+00E4
morphology=stem_en
stopwords=zh"></textarea>
                </div>
            </div>
            <div class="form-row m-2">
                <div class="col-3">
                    <label for="stopwords">Stopwords </label>
                </div>
                <div class="col-9">
                    <textarea class="form-control" name="stopwords" id="stopwords" rows=3></textarea>
                </div>
            </div>

            <div class="form-row m-2">
                <div class="col-3">
                    <label for="exceptions">Exceptions </label>
                </div>
                <div class="col-9">
                    <textarea class="form-control" name="exceptions" id="exceptions" rows=3></textarea>
                </div>
            </div>
        </div>

        <div class="form-row m-2">
            <button type="submit" class="btn btn-primary btn-lg btn-block">Next step</button>
        </div>
    </form>
</div>
