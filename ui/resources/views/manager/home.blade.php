@extends('layouts.dashboard')

@section('content')

    <style>

        table#rules-table td:nth-child(4) {
            word-break: break-all;
        }

        .jqstooltip {
            width: auto !important;
            height: auto !important;
        }

        .j-show-stats {
            cursor: pointer;
        }

        .twitter-typeahead {
            width: 100%;
        }

        .tt-menu {
            width: 100%;
            margin: 12px 0;
            padding: 8px 0;
            background-color: #fff;
            border: 1px solid #ccc;
            border: 1px solid rgba(0, 0, 0, 0.2);
            -webkit-border-radius: 8px;
            -moz-border-radius: 8px;
            border-radius: 8px;
            -webkit-box-shadow: 0 5px 10px rgba(0, 0, 0, .2);
            -moz-box-shadow: 0 5px 10px rgba(0, 0, 0, .2);
            box-shadow: 0 5px 10px rgba(0, 0, 0, .2);
        }

        .tt-suggestion {
            padding: 3px 20px;
            font-size: 18px;
            line-height: 24px;
        }

        .tt-suggestion:hover {
            cursor: pointer;
            color: #fff;
            background-color: #0097cf;
        }

        .tt-suggestion.tt-cursor {
            color: #fff;
            background-color: #0097cf;

        }

        .tt-suggestion p {
            margin: 0;
        }

    </style>
    <div id="rules_content">
        <div class="j-add-rules-form">
            <div class="form-row align-items-center">
                <div class="col-lg-9 col-sm-9">
                    <input id="new-rule-query" type="text" class="form-control typeahead" name="new-rule-query"
                           value="{{ old('new-rule-query') }}"
                           placeholder="Enter new rule query. You can use extended query syntax">
                    <span class="invalid-feedback j-invalid-rule-query hidden" role="alert"></span>
                </div>


                <div class="col-lg-3 col-sm-3 text-right">
                    <input class="hidden" type="file" name="file-import" id="customFile" accept="text/plain">
                    <button type="button" class="btn btn-success" id="import-rules"
                            data-toggle="popover" title="Syntax" data-trigger="hover"
                            data-content="One rule per line. Tab separated. Order: full-text query, additional filters, tags, external query, highlighting (0/1), duplication check(0/1)">
                        Import rules
                    </button>
                </div>
            </div>

            <div class="mt-2 form-row align-items-center">
                <div class="col-lg-4 col-sm-4">
                    <input type="text" class="form-control" id="new-rule-filters" name="new-rule-filters"
                           value="{{ old('new-rule-filters') }}" placeholder="New rule filters">
                </div>
                <div class="col-lg-3 col-sm-3">
                    <input type="text" class="form-control" id="new-rule-tags" name="new-rule-tags"
                           value="{{ old('new-rule-tags') }}" placeholder="New rule tags">
                </div>


                <div class="col-lg-2 col-sm-2">
                    <div class="form-check mb-0">
                        <input class="form-check-input" type="checkbox" id="new-rule-highlighting"
                               name="new-rule-highlighting" value="{{ old('new-rule-highlighting') }}">
                        <label class="form-check-label" for="new-rule-highlighting">Highlighting</label>
                    </div>
                </div>
                <div class="col-lg-3 col-sm-3 text-right">
                    <button type="button" class="btn btn-primary align-self-end" data-toggle="modal"
                            id="add-rule-modal">
                        Add rule
                    </button>
                </div>
            </div>

        </div>
        <hr>


        <div class="row">

            <table id="rules-table" class="display" style="width:100%">
                <thead>
                <tr>
                    <th scope="col">#</th>
                    <th scope="col">Query</th>
                    <th scope="col">Filters</th>
                    <th scope="col">Tags</th>
                    <th scope="col">Actions</th>
                    <th scope="col">Stats</th>
                </tr>
                </thead>
            </table>
        </div>
    </div>

    <script>
        const currentStream = {{$stream}};
        var table = null;

        $.fn.dataTable.ext.errMode = function (settings, helpPage, message) {
            toast("Can't get access to Manticore", false);
            $('#rules_content').hide();
        };
        $(document)
            .ready(function () {
                $('[data-toggle="popover"]').popover({
                    html: true
                });
                var substringMatcher = function (strs) {
                    return function findMatches(q, cb) {

                        let splitted = q.split(" ");
                        q = splitted[splitted.length - 1];
                        splitted.splice(-1, 1);

                        let prepend = splitted.join(" ");


                        var matches, substringRegex;

                        // an array that will be populated with substring matches
                        matches = [];

                        if (q[0] == '@') {


                            // regex used to determine if a string contains the substring `q`
                            substrRegex = new RegExp(q, 'i');

                            // iterate through the pool of strings and for any string that
                            // contains the substring `q`, add it to the `matches` array
                            $.each(strs, function (i, str) {
                                if (substrRegex.test(str)) {
                                    matches.push(prepend + " " + str);
                                }
                            });
                        }
                        cb(matches);
                    };
                };

                var states = ["{!! implode('", "', $fields) !!}"];

                $('#new-rule-query').typeahead({
                        hint: true,
                        highlight: true,
                        minLength: 1,
                    },
                    {
                        name: 'states',
                        source: substringMatcher(states)
                    });

                table = $('#rules-table').DataTable({
                    "processing": true,
                    "serverSide": true,
                    "ajax": {
                        url: "getRulesList", data: function (d) {
                            d.streamId = currentStream;
                        }
                    },
                    "order": [[0, "desc"]],
                    "drawCallback": function (settings) {
                        var api = this.api();

                        if (settings.json.message !== undefined) {
                            toast(settings.json.message, false, false);
                        }

                        var data = api.rows({page: 'current'}).data();


                        for (var i = 0; i < data.length; i++) {
                            var values = Object.values(data[i].statistic);
                            $("#sparkline_" + data[i].id).sparkline(values.reverse(), {
                                width: '100px',
                                height: '25px'
                            });
                        }
                    },
                    columns: [
                        {"data": 'id'},
                        {"data": 'query'},
                        {"data": 'filters'},
                        {
                            "data": function (tags) {

                                let parsedTags = JSON.parse(tags.tags);

                                let rows = [];

                                rows.push("<ul>");
                                if (parsedTags.tag.length > 0) {
                                    rows.push("<li><b>Tag:</b> " + parsedTags.tag + "</li>");
                                }

                                if (parsedTags.externalQuery.length > 0) {
                                    rows.push("<li><b>External query:</b> " + parsedTags.externalQuery + "</li>");
                                }

                                if (parsedTags.highlighting === true) {
                                    rows.push("<li><b>highlighting:</b> " + parsedTags.highlighting + "</li>");
                                }

                                if (parsedTags.originalQuery.length > 0) {
                                    rows.push("<li><b>Original query:</b> " + parsedTags.originalQuery + "</li>");
                                }

                                if (parsedTags.updated.length > 0) {
                                    rows.push("<li><small>Updated: " + parsedTags.updated + "</small></li>");
                                }

                                rows.push("</ul>");

                                return rows.join(' ');
                            }
                        },
                        {
                            data: null,
                            className: "center",
                            orderable: false,
                            searchable: false,
                            defaultContent: '<button type="button" class="btn btn-warning btn-sm j-delete-rule">Delete</button>'
                        },
                        {
                            "data": function (data) {
                                return "<span class='j-show-stats' id='sparkline_" + data.id + "'></span>";
                            }
                        },
                    ]
                });


                $('#rules-table tbody')
                    .on('click', '.j-delete-rule', function () {
                        var data = table.row($(this).parents('tr')).data();

                        $.ajax({
                            url: 'deleteRule/' + data.id + '/?streamId=' + currentStream,
                            type: 'GET',
                            success: function (data) {
                                if (data.message != null) {
                                    toast(data.message);
                                }
                                table.ajax.reload();
                            },

                            error: function(jqXHR, textStatus, errorThrown) {
                                if (jqXHR.responseJSON.message != null) {
                                    toast(jqXHR.responseJSON.message, true, false);
                                }
                            }
                        });
                    })
                    .on('click', '.j-show-stats', function () {
                        var data = table.row($(this).parents('tr')).data();
                        window.location.href = 'graphs/rule/' + data.id + '/?streamId=' + currentStream;
                    });


            })
            .on('click', '#add-rule-modal', function () {
                addSingleRule();
            })
            .on('click', '#import-rules', function () {
                $('#customFile').click();
            })
            .on('change', '#customFile', function () {
                var file_data = $("#customFile").prop("files")[0];

                var form_data = new FormData();
                form_data.append("import", file_data);
                form_data.append("streamId", currentStream);

                $.ajax({
                    url: "/manager/importRules",
                    dataType: 'script',
                    cache: false,
                    contentType: false,
                    processData: false,
                    data: form_data,
                    type: 'post',
                    success: function (data) {
                        data = JSON.parse(data);
                        if (data?.message?.errors != null) {
                            let messages = "";
                            $.each(data.message.errors, function (i, value) {
                                if (i > 0) {
                                    messages += "<hr>";
                                }
                                messages += "<h5>" + value.message + "</h5>" +
                                    "<ul>" +
                                    "<li>Query: <i>" + value.query + "</i></li>\n" +
                                    "<li>Filters: <i>" + value.filters + "</i></li>\n" +
                                    "<li>Tags: <i>" + value.tags + "</i></li>\n" +
                                    "</ul>"
                            });

                            if (messages !== "") {
                                toast(messages, false, false);
                            }
                        }
                        table.ajax.reload();
                    },

                    error: function(jqXHR, textStatus, errorThrown) {
                        data = JSON.parse(jqXHR.responseText)
                        if (data.message != null) {
                            if(data.message instanceof String){
                                toast(data.message, true, false);
                            }else{
                                let messages = "";
                                $.each(data.message.errors, function (i, value) {
                                    if (i > 0) {
                                        messages += "<hr>";
                                    }
                                    if(value.statusCode === 422){
                                        messages += "<h5>" + value.message + "</h5>";
                                    }else{
                                        messages += "<h5>" + value.message + "</h5>" +
                                            "<ul>" +
                                            "<li>Query: <i>" + value.query + "</i></li>\n" +
                                            "<li>Filters: <i>" + value.filters + "</i></li>\n" +
                                            "<li>Tags: <i>" + value.tags + "</i></li>\n" +
                                            "</ul>"
                                    }

                                });
                                toast(messages, true, false);
                            }
                        }
                    }
                });
            })
            .on('keypress', function (e) {
                if (e.which == 13 && $('#new-rule-query').is(':focus')) {
                    addSingleRule();
                }
            });

        function addSingleRule() {
            var ruleText = $('#new-rule-query'),
                ruleFilters = $('#new-rule-filters'),
                ruleTags = $('#new-rule-tags'),
                ruleExternal = $('#new-rule-external'),
                ruleHighlighting = $('#new-rule-highlighting');

            if (ruleText.val().length > 0 || ruleFilters.val().length > 0) {
                $.ajax({
                    url: 'addRule',
                    type: 'POST',
                    data: {
                        rule_text: ruleText.val(),
                        rule_filters: ruleFilters.val(),
                        rule_tags: ruleTags.val(),
                        rule_external: ruleExternal.val(),
                        rule_highlighting: "" + ruleHighlighting.prop("checked"),
                        streamId: currentStream,
                        _token: "{{csrf_token()}}"
                    },

                    success: function (data) {
                        $('.j-invalid-rule-query').text('').hide();
                        ruleText.removeClass('is-invalid').val('');
                        ruleFilters.val('');
                        ruleExternal.val('');
                        ruleTags.val('');
                        ruleHighlighting.prop("checked", false);

                        if (data.message != null) {
                            toast(data.message);
                        }
                        table.ajax.reload();
                    },
                    error: function (jqXHR, exception) {
                        if (jqXHR.responseJSON.message != null) {
                            toast(jqXHR.responseJSON.message, true, false);
                        }
                    }
                });
            } else {
                $('.j-invalid-rule-query').text('You must specify query or filters').show();
                ruleText.addClass('is-invalid');
            }
        }
    </script>
@stop
