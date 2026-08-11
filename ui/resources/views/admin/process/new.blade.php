@extends('layouts.dashboard')

@include('admin.process.customrules')
@include('admin.process.jsltconf')
@include('admin.process.mergenode')
<style>

    @media (max-width: 992px) {
        .progress-block {
            height: 80px;
        }
    }

    .cancel-button {
        width: 18px;
        margin-left: 5px;
    }

    .j-scheme-type, .j-approved-type, .j-merge-badges {
        cursor: pointer;
    }

    .input-stub {
        display: inline-block;
        padding: .175rem .75rem;
        font-size: 1rem;
        line-height: 1.5;
        color: #495057;
        background-color: #fff;
        background-clip: padding-box;
        border: 1px solid #ced4da;
        border-radius: .25rem;
        transition: border-color .15s ease-in-out, box-shadow .15s ease-in-out;
    }

    #approve-section > div {
        margin-top: 10px;
    }

    #approve-section .badge {
        cursor: default;
    }

    #approve-section .badge.inverse {
        cursor: pointer;
    }

    .badge-green-often.inverse {
        border: 1px solid #49b51d;
    }

    .badge-green-rarely.inverse {
        border: 1px solid #98e877;
    }

    .badge-yellow-often.inverse {
        border: 1px solid #ffd300;
    }

    .badge-yellow-rarely.inverse {
        border: 1px solid #fdf980;
    }

    .badge-red-often.inverse {
        border: 1px solid #ff0000;
    }

    .badge-red-rarely.inverse {
        border: 1px solid #f19999;
    }

    .badge-green-often {
        color: #fff;
        background-color: #49b51d;
    }

    .badge-green-rarely {
        color: #fff;
        background-color: #98e877;
    }

    .badge-red-often {
        color: #fff;
        background-color: #ff0000;
    }

    .badge-red-rarely {
        color: #fff;
        background-color: #f19999;
    }

    .badge-yellow-often {
        color: #757575;
        background-color: #ffd300;
    }

    .badge-yellow-rarely {
        color: #757575;
        background-color: #fdf980;
    }

    .progress-block-caption {
        color: antiquewhite;
        font-size: larger;
        font-weight: bolder;
        border-radius: 10px 10px 0 0;
        background: #7d7d7d;
        padding: 5px;
    }

    .progress-block-background {
        border-radius: 0 0 10px 10px;
        padding: 15px;
    }

    .progress-block {
        margin: 10px;
        border: 2px solid #51d16f;
        border-radius: 5px;
        background: #caf4ce;
        font-weight: bold;
        color: #0b5923;
        padding: 5px;
        cursor: pointer;
    }

    .block-disabled {
        background: #fbecec;
        color: #a74a4a;
        border: 2px solid #e2a6a6;
    }

    .inverse {
        color: #757575;
        background-color: #fff;
    }

    .caption {
        background: #f0f0f0;
        padding: 10px;
        margin-bottom: 20px;
        border-radius: 10px;
        border-bottom: 2px solid #e0e0e0;
    }

    .invalid {
        border: 1px red solid;
    }

    #max-matches-percent {
        width: 85%;
    }

    #language {
        height: 100%;
    }

    /* Enhanced validation styles */
    .is-valid {
        border-color: #28a745;
        background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' width='8' height='8' viewBox='0 0 8 8'%3e%3cpath fill='%2328a745' d='m2.3 6.73 4.89-4.89c.39-.39 1.02-.39 1.41 0s.39 1.02 0 1.41L3.05 8.88c-.39.39-1.02.39-1.41 0L.29 6.34c-.39-.39-.39-1.02 0-1.41s1.02-.39 1.41 0l1.61 1.61z'/%3e%3c/svg%3e");
        background-repeat: no-repeat;
        background-position: right calc(0.375em + 0.1875rem) center;
        background-size: calc(0.75em + 0.375rem) calc(0.75em + 0.375rem);
    }

    .invalid-feedback {
        display: block;
        width: 100%;
        margin-top: 0.25rem;
        font-size: 0.875em;
        color: #dc3545;
    }

    .is-invalid {
        border-color: #dc3545;
        background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='none' stroke='%23dc3545' viewBox='0 0 12 12'%3e%3ccircle cx='6' cy='6' r='4.5'/%3e%3cpath d='m5.8 4.6 1.4 1.4'/%3e%3cpath d='m7.2 4.6-1.4 1.4'/%3e%3c/svg%3e");
        background-repeat: no-repeat;
        background-position: right calc(0.375em + 0.1875rem) center;
        background-size: calc(0.75em + 0.375rem) calc(0.75em + 0.375rem);
    }
</style>
@section('content')

    <div class="j-preloader d-none align-items-center">
        <strong>Loading...</strong>
        <div class="spinner-border ml-auto" role="status" aria-hidden="true"></div>
    </div>

    <div class="row progress-block-background">
        <div class="col-sm text-center progress-block j-progress-title d-flex align-items-center justify-content-center"
             data-title="goals">
            <i class="fas fa-cogs"></i>&nbsp;GENERAL
        </div>

        <div
            class="col-sm text-center progress-block block-disabled j-progress-title d-flex align-items-center justify-content-center"
            data-title="analyze">
            <i class="fab fa-searchengin"></i>&nbsp;ANALYSIS
        </div>

        <div
            class="col-sm text-center progress-block block-disabled j-progress-title d-flex align-items-center justify-content-center"
            data-title="schema">
            <i class="fas fa-project-diagram"></i>&nbsp;SCHEMA UPDATE
        </div>

        <div
            class="col-sm text-center progress-block block-disabled j-progress-title d-flex align-items-center justify-content-center"
            data-title="finish">
            <i class="fas fa-tasks"></i>&nbsp;REVIEW
        </div>
    </div>

    <div class="row justify-content-center" id="actions"
         style="margin-top: 35px">
    </div>

    <script>

        class GoalsModel {
            host = null;
            topic = null;
            group = null;

            constructor(id, host, topic, group) {
                this.id = id;
                this.host = host;
                this.topic = topic;
                this.group = group;
            }
        }

        class Node {
            id = null;
            path = null;
            allCount = null;
            types = null;
            selectedType = false;
            name = null;

            keyPercentageFrequency = 0;
            badgePercentageFrequency = 0;

            constructor(id, path, allCount, types) {
                this.id = id;
                this.path = path;
                this.allCount = allCount;
                this.types = types;
            }

            unsetSelection() {
                this.selectedType = false;
            }

            setSelected(type) {
                if (this.types[type] != null) {
                    this.selectedType = type;
                }
            }

            setPath(path) {
                if (path === null) {
                    return null;
                }
                this.path = this.normalizePath(path);
                return this.path;
            }

            setName(name) {
                if (name === null) {
                    return null;
                }
                this.name = this.normalizeName(name);
                return this.name;
            }

            getName() {
                return this.name;
            }

            normalizeName(name) {
                if (name == "id") {
                    name = "uid";
                }

                return name.replace(/<.*?>/, '').replace(/[^a-zA-Z0-9\-_\[\].]/g, '')
            }

            normalizePath(name) {
                return name.replace(/<.*?>/, '').replace(/[^a-zA-Z0-9\-_\[\]{}&;*.]/g, '')
            }

            getBadgeMainColor(Percentage) {
                if (Percentage >= 80) {
                    return 'green';
                }
                if (Percentage >= 30) {
                    return 'yellow';
                }

                return 'red';
            }

            getBadgeFrequencyColor(percentage, itemsCount) {
                let threshold = (100 / itemsCount);

                if (percentage >= threshold) {
                    return 'often';
                } else {
                    return 'rarely'
                }
            }

            getKeyFrequencyPercent(count) {
                let result = ((count / process.allDocsCount) * 100).toFixed(2);
                return result.replace('.00', '');
            }

            getBadgeFrequencyPercent(value, keysCount) {
                let result = ((value / keysCount) * 100).toFixed(2);
                return result.replace('.00', '');
            }

            getView() {
                let badges = [];
                let result;
                let keyPercentageFrequency = this.getKeyFrequencyPercent(this.allCount);
                let self = this;

                if (this.selectedType === false) {
                    let printedPath = this.path.split('.');
                    if (printedPath.length > 1) {
                        let lastElement = printedPath.pop();
                        printedPath = printedPath.join(".");
                        printedPath = "<span>" + "&nbsp;".repeat(printedPath.length) + "</span><div>." + lastElement;
                    } else {
                        printedPath = "<span></span><div>" + printedPath
                    }
                    result = "<div style='display: flex'>" + printedPath +
                        " <strong>" + keyPercentageFrequency + "%</strong> ";

                    $.each(this.types, function (type, item) {
                        badges.push(self.drawBadge(type, item, keyPercentageFrequency, Object.keys(self.types).length));
                    });
                } else {
                    result = "<div style='word-break: break-word'>"
                        + '<span class="j-approved-path input-stub" contenteditable="true">'
                        + this.path + '</span> -> <span class="j-approved-key input-stub" contenteditable="true">'
                        + this.name + '</span>&nbsp';

                    badges.push('<strong>' + keyPercentageFrequency + '%</strong> ');
                    badges.push(this.drawBadge(this.selectedType, this.types[this.selectedType],
                        keyPercentageFrequency, Object.keys(self.types).length, true));
                }

                result += badges.join(" ");
                result += "</div></div>";

                return result;
            }

            drawBadge(type, item, keyPercentageFrequency, typeSum) {
                let example = "";

                if (item.example != null) {
                    example = item.example.toString().replace(/"/g, "'");
                }

                let badgePercentageFrequency = this.getBadgeFrequencyPercent(item.count, this.allCount);
                let result = [];
                result.push('<span class="badge badge-' + this.getBadgeMainColor(keyPercentageFrequency) + '-' +
                    this.getBadgeFrequencyColor(badgePercentageFrequency, typeSum) + ' ');

                if (this.selectedType) {
                    result.push("j-approved-type");
                } else {
                    result.push("j-scheme-type");
                }

                let finders;
                if (this instanceof MergedNode) {
                    finders = '" data-type="' + type + '" data-index="' + this.path + '" ' +
                        'data-id="' + this.id + '" data-source="merged"';
                } else {
                    finders = '" data-type="' + type + '" data-index="' + this.path + '" ' +
                        'data-id="' + this.id + '" data-source="origin"';
                }

                result.push(finders);

                if (example.length > 0) {
                    result.push('data-trigger="hover" data-toggle="popover" ' +
                        'data-content="Example: ' + example + '"');
                }
                result.push('>' + type + ' (' + badgePercentageFrequency + '%)</span>');

                if (this.selectedType) {
                    if (this.selectedType !== "json" && this.selectedType !== "json[]") {
                        if (this.mergedFrom === undefined) {
                            result.push("<i class=\"far fa-object-ungroup text-success j-merge-badges\"></i>");
                        } else {
                            result.push("<i class=\"fas fa-object-ungroup text-success\"></i>");
                        }
                    }

                    result.push("<img class=\"cancel-button j-approved-type " + finders + " src='/img/cancel.png'>");
                }

                return result.join(" ");
            }
        }

        class MergedNode extends Node {
            mergedFrom = []

            setMerged(nodeList) {
                this.mergedFrom = nodeList
            }

            setPath(path) {
                if (path === null) {
                    return null;
                } else {
                    path = path.replace(/<br>/gi, "[br]")
                }
                this.path = this.normalizePath(path);
                return this.path;
            }

            normalizeName(name) {
                if (name == "id") {
                    name = "uid";
                }

                return name.replace(/<.*?>/, '').replace(/[^a-zA-Z0-9\-_\[\].]/g, '')
            }

            normalizePath(name) {
                return name.replace(/<.*?>/, '').replace(/[^a-zA-Z0-9\-_\[\]{}&;*.]/g, '').replace(/\[br\]/gi, "<br>")
            }
        }

        class Process {
            id = null;
            currentSection = 0;
            source = null;
            destination = null;
            language = [];
            progress = 0;
            attrs = {};
            selectedAttrs = {};
            selectedMergedAttrs = {};
            groupedAttrs = [];
            badgesCount = 0;
            queryComplexityValidation = 0;
            searchdSettings = 0;
            nlpSettings = 0;
            stopwords = 0;
            receivedSchema = false;
            exceptions = 0;
            maxMatchesPercent = 0;
            allDocsCount = 1;
            processName = "";
            maxBatchSize = 5000;
            minThreads = 1;
            maxThreads = 3;
            jsltConfig = "";
            kafkaConfig = {
                'fetch.min.bytes': 1,
                'fetch.max.wait.ms': 500,
                'fetch.max.bytes': 1048576,
                'max.poll.records': 500
            };
            outputDocs = {
                noTransforms: 0,
                original: 0,
                modified: 0,
                matchingQueries: 0
            };

            drawFinish() {
                if (this.source == null) {
                    alert('You didn\'t select any source!');
                } else if (this.destination == null) {
                    alert('You didn\'t select any destination!');
                } else {
                    let result = '<form id="add-process-form" method="post" action="/admin/process/add" style="width: 60%;">' +
                        '    <div class="row" style="width: 100%">' +
                        '<div class="col">' +
                        '<h5>Name</h5>' +
                        '  <ul>' +
                        '    <li><strong>' + this.processName + '</strong></li>' +
                        '  </ul>' +
                        '<h5>Source</h5>' +
                        '  <ul>' +
                        '    <li>Host: ' + this.source.host + '</li>' +
                        '    <li>Topic: ' + this.source.topic + '</li>' +
                        '    <li>Group: ' + this.source.group + '</li>' +
                        '  </ul>' +
                        '</div>' +
                        '<div class="col">' +
                        '<h5>Destination</h5>' +
                        '  <ul>' +
                        '    <li>Host: ' + this.destination.host + '</li>' +
                        '    <li>Topic: ' + this.destination.topic + '</li>' +
                        '  </ul>' +
                        '</div></div>' +
                        '<div class="row" style="width: 100%">' +
                        '  <div class="col">' +
                        '    <h5>Schema</h5><ul>';

                    $.each(this.getSelectedAttrs(), function (index, item) {
                        result += '<li>' + item.path.replace(/&&/gi, "<br>") + ' -> ' + item.name + ' (' + item.type + ')</li>'
                    });

                    result += '</ul>' +
                        '  </div>' +
                        '</div>' +
                        '<div class="row" style="width: 100%">' +
                        '  <div class="col">' +
                        '    <h5>Output documents</h5>' +
                        '    <ul>';

                    if (this.outputDocs.modified && this.outputDocs.noTransforms) {
                        result += '<li>Use new names for the JSON nodes which you approved</li>';
                    } else if (this.outputDocs.noTransforms) {
                        result += '<li>No input JSON transformation</li>';
                    } else if (this.outputDocs.original) {
                        result += '<li>Leave only JSON nodes which you approved with their <b>original</b> names</li>';
                    } else if (this.outputDocs.modified) {
                        result += '<li>Leave only JSON nodes which you will approved with their <b>new</b> names</li>';
                    }

                    if (this.outputDocs.matchingQueries) {
                        result += '<li>Matching queries</li>'
                    }

                    result += '    </ul>' +
                        '  </div>' +
                        '</div>' +
                        '<div class="row" style="width: 100%">' +
                        '  <div class="col">' +
                        '    <h5>Scaling</h5>' +
                        '    <ul>' +
                        '    <li>Min threads: ' + this.minThreads + '</li>' +
                        '    <li>Max threads: ' + this.maxThreads + '</li>' +
                        '    <li>Max batch size: ' + this.maxBatchSize + '</li>' +
                        '    </ul>' +
                        '  </div>' +
                        '</div>' +
                        '<div class="row" style="width: 100%">' +
                        '  <div class="col">' +
                        '    <h5>Kafka Configuration</h5>' +
                        '    <ul>' +
                        '    <li>fetch.min.bytes: ' + this.kafkaConfig['fetch.min.bytes'] + '</li>' +
                        '    <li>fetch.max.wait.ms: ' + this.kafkaConfig['fetch.max.wait.ms'] + '</li>' +
                        '    <li>fetch.max.bytes: ' + this.kafkaConfig['fetch.max.bytes'] + '</li>' +
                        '    <li>max.poll.records: ' + this.kafkaConfig['max.poll.records'] + '</li>' +
                        '    </ul>' +
                        '  </div>' +
                        '</div>';

                    if (this.jsltConfig != null && this.jsltConfig.length > 0) {
                        result += '<div class="row" style="width: 100%">' +
                            '    <div class="col"><h5>Additional transformation rules of output docs</h5>' +
                            '        <p class="h6">' +
                            this.jsltConfig +
                            '        </p>' +
                            '    </div>' +
                            '</div>';
                    }

                    result += '<div class="row" style="width: 100%">' +
                        '  <div class="col">' +
                        '    <h5>Morphology</h5>' +
                        '    <ul>';

                    $.each(this.language, function (index, item) {
                        result += "<li>" + item + "</li>";
                    });

                    result += '    </ul>' +
                        '  </div>' +
                        '</div>';

                    if (this.queryComplexityValidation) {
                        result += '<div class="row" style="width: 100%">' +
                            '  <div class="col">' +
                            '    <h5>Query Complexity Validation</h5>' +
                            '    <ul>' +
                            '        <li>Max matches percent: ' + this.maxMatchesPercent + '</li>' +
                            '    </ul>' +
                            '  </div>' +
                            '</div>';
                    }

                    if (this.searchdSettings) {
                        result += '<div class="row" style="width: 100%">' +
                            '  <div class="col">' +
                            '    <h5>Searchd settings</h5>' +
                            '    <ul>' +
                            '        <li>Blacklist mode support</li>' +
                            '    </ul>' +
                            '  </div>' +
                            '</div>';
                    }

                    if (this.nlpSettings && this.language.indexOf("custom") !== -1) {
                        result += '<div class="row" style="width: 100%">' +
                            '  <div class="col">' +
                            '    <h5>NLP settings</h5>' +
                            '    <ul>' +
                            '        <li>NLP settings: ' + this.nlpSettings + '</li>';

                        if (this.stopwords && this.language.indexOf("custom") !== -1) {
                            result += '<li>Stopwords: ' + this.stopwords + '</li>';
                        }

                        if (this.exceptions && this.language.indexOf("custom") !== -1) {
                            result += '<li>Exceptions: ' + this.exceptions + '</li>';
                        }

                        result += '    </ul>' +
                            '  </div>' +
                            '</div>';
                    }

                    result += '<div class="row">' +
                        '  <button type="submit" class="btn btn-primary btn-lg btn-block">Finish</button>' +
                        '</div>' +
                        (this.id !== null ? '<input type="hidden" name="id" value="' + this.id + '">' : '') +
                        '<input type="hidden" name="name" value="' + this.processName + '">' +
                        '<input type="hidden" name="source_id" value="' + this.source.id + '">' +
                        '<input type="hidden" name="destination_id" value="' + this.destination.id + '">' +
                        '<input type="hidden" name="language" value="' + this.language + '">' +
                        '<input type="hidden" name="query_complexity_validation" value="' + this.queryComplexityValidation + '">' +
                        '<input type="hidden" name="max_batch_size" value="' + this.maxBatchSize + '">' +
                        '<input type="hidden" name="min_threads" value="' + this.minThreads + '">' +
                        '<input type="hidden" name="max_threads" value="' + this.maxThreads + '">' +
                        '<input type="hidden" name="searchd_settings" value="' + encodeURIComponent(JSON.stringify(this.searchdSettings)) + '">' +
                        '<input type="hidden" name="max_matches_percent" value="' + this.maxMatchesPercent + '">' +
                        '<input type="hidden" name="attrs" value="' + encodeURIComponent(JSON.stringify(this.getSelectedAttrs())) + '">' +
                        '<input type="hidden" name="kafka_config" value="' + encodeURIComponent(JSON.stringify(this.kafkaConfig)) + '">' +
                        (this.getJSLTConf().length > 0 ? '<input type="hidden" name="jslt_conf" value="' + encodeURIComponent(this.getJSLTConf()) + '">' : '') +
                        ((this.nlpSettings && this.language.indexOf("custom") !== -1) ? '<input type="hidden" name="nlp_settings" value="' + this.nlpSettings.replaceAll('"', '&quot') + '">' : '') +
                        ((this.stopwords && this.language.indexOf("custom") !== -1) ? '<input type="hidden" name="stopwords" value="' + this.stopwords.replaceAll('"', '&quot') + '">' : '') +
                        ((this.exceptions && this.language.indexOf("custom") !== -1) ? '<input type="hidden" name="exceptions" value="' + this.exceptions.replaceAll('"', '&quot') + '">' : '') +
                        '<input type="hidden" name="output_docs" value="' + this.outputDocs.modified +
                        this.outputDocs.original + this.outputDocs.noTransforms + this.outputDocs.matchingQueries + '">' +
                        '</form>';

                    $('#actions').html(result);
                }
            }

            drawGoals(callback) {
                $.ajax({
                    url: '/admin/process/goals',
                    type: 'get',
                    success: function (data) {
                        $('#actions').html(data);
                        callback();
                    },

                    error: function(jqXHR, textStatus, errorThrown) {
                        if (jqXHR.responseJSON.message != null) {
                            toast(jqXHR.responseJSON.message, true, false);
                        }
                    }
                });
            }

            async drawParse() {
                let self = this;

                await $.ajax({
                    url: '/admin/process/progress',
                    type: 'get',
                    success: function (data) {
                        $('#actions').html(data);
                    },

                    error: function(jqXHR, textStatus, errorThrown) {
                        if (jqXHR.responseJSON.message != null) {
                            toast(jqXHR.responseJSON.message, true, false);
                        }
                    }
                });

                if (jQuery.isEmptyObject(this.selectedAttrs)) {
                    if (this.source.host == null) {
                        alert('Please specify host');
                    } else if (this.source.group == null) {
                        alert('Please specify group');
                    } else if (this.source.topic == null) {
                        alert('Please specify topic');
                    } else {
                        $.ajax({
                            url: '/admin/process/parseSchema',
                            type: 'post',
                            data: {
                                host: this.source.host,
                                group: this.source.group,
                                topic: this.source.topic
                            },
                            success: function (data) {
                                self.receivedSchema = true;
                            },

                            error: function(jqXHR, textStatus, errorThrown) {
                                if (jqXHR.responseJSON.message != null) {
                                    toast(jqXHR.responseJSON.message, true, false);
                                }
                            }
                        });

                        let interval = setInterval(function () {
                            self.progress += 1.666666667;
                            if (self.progress >= 100) {
                                self.progress = 100;
                                clearTimeout(interval);
                                $('#next-button').show();
                            }
                            $("#progress").width(self.progress + "%").attr({'aria-valuenow': self.progress});
                        }, 1000)
                    }
                } else {
                    setTimeout(function () {
                        self.progress = 100
                        $("#progress").width(self.progress + "%").attr({'aria-valuenow': self.progress});
                        $('#next-button').show();
                    }, 1000)
                }
            }

            setJSLTConfig(config) {
                this.jsltConfig = config.trim();
            }

            getJSLTConf() {
                return this.jsltConfig;
            }

            setId(id) {
                this.id = id;
            }

            mergeNodes(nodeList) {
                let mergedNode = Object.assign(new MergedNode(), this.attrs[nodeList["from"]["id"]]);
                mergedNode.setName("merged");

                let idList = [],
                    mergedPath = [];

                for (let nodeName in nodeList) {
                    let id = nodeList[nodeName]["id"];
                    if (nodeList[nodeName]["source"] === "origin") {
                        $('.j-approved-type[data-id="' + id + '"][data-source="origin"]').parent().remove()
                        idList.push(nodeList[nodeName]["id"]);
                        mergedPath.push(this.attrs[id].path)
                        delete this.selectedAttrs[id];
                    } else {
                        $('.j-approved-type[data-id="' + id + '"][data-source="merged"]').parent().remove()
                        let node = this.selectedMergedAttrs[id];
                        node.mergedFrom.forEach(id => idList.push(id));
                        mergedPath.push(node.path)
                        delete this.selectedMergedAttrs[id];
                    }
                }

                mergedNode.setMerged(idList);
                mergedNode.setPath(mergedPath.join("[br]"));

                this.selectedMergedAttrs[mergedNode.id] = mergedNode;
                let name = this.selectedMergedAttrs[mergedNode.id].normalizeName("merged");

                if (!this.issetName(name)) {
                    this.selectedMergedAttrs[mergedNode.id].setName(name);
                } else {
                    for (let i = 0; i < 256; i++) {
                        if (!this.issetName(name + i)) {
                            this.selectedMergedAttrs[mergedNode.id].setName(name + i);
                            break;
                        }
                    }
                }

                return mergedNode;
            }

            removeMergedNode() {
            }

            drawSchema() {
                $('#actions').html(
                    '<div class="row" style="width: 100%; margin-bottom: 10px;">' +
                    '    <div class="col">' +
                    '        <button id="next-button" type="submit" class="btn btn-success btn-lg btn-block">Next step</button>' +
                    '    </div>' +
                    '</div>' +
                    '<div class="row" style="width: 100%">' +
                    '    <div id="schema-section" class="col-6">' +
                    '        <h4>Found in your documents' +
                    (!this.receivedSchema ? '<button id="refresh-schema" type="button" ' +
                        'class="btn btn-primary btn-sm">Refresh</button>' : "") +
                    '        </h4>' +
                    '    </div>' +
                    '    <div id="approve-section" class="col-6">' +
                    '        <h4>Approved <button id="add-badges-button" type="button" class="btn btn-primary btn-sm">Add custom node</button>' +
                    (this.jsltConfig.length > 0
                            ? '<button id="add-jslt-conf" type="button" class="btn btn-success btn-sm"><span class="badge">&check;</span>Advanced output JSON transformation</button>'
                            : '<button id="add-jslt-conf" type="button" class="btn btn-primary btn-sm"><span class="badge">&cross;</span>Advanced output JSON transformation</button>'
                    ) +
                    '        </h4>' +
                    '    </div>' +
                    '</div>');

                let self = this;

                if (!$.isEmptyObject(this.selectedAttrs)) {
                    $.each(this.attrs, function (key, item) {
                        let clonedItem = Object.assign(new Node(), item);
                        clonedItem.selectedType = false;

                        let ruleView = $(clonedItem.getView());
                        $('#schema-section').append(ruleView);

                        if (item.selectedType) {
                            ruleView.hide();
                            $('#approve-section').append(item.getView());
                        }
                    });
                } else {
                    $.ajax({
                        url: '/admin/process/getSchema',
                        type: 'get',
                        success: function (data) {
                            if (data.allCount > 0) {
                                self.allDocsCount = data.allCount;
                            }

                            data.schema = Object.assign({
                                whole_document: {
                                    count: self.allDocsCount,
                                    types: {
                                        json: {
                                            count: self.allDocsCount,
                                            example: ""
                                        }
                                    }
                                }
                            }, data.schema);

                            $.each(data.schema, function (key, item) {
                                let rule = new Node(self.badgesCount, key, item.count, item.types);
                                self.attrs[self.badgesCount] = rule;
                                let types = Object.keys(rule.types);
                                if (types[0] == 'json') {
                                    self.groupedAttrs.push(key);
                                }
                                self.badgesCount++;
                                $('#schema-section').append(rule.getView());
                            });

                            $('[data-toggle="popover"]').popover();
                        },

                        error: function(jqXHR, textStatus, errorThrown) {
                            if (jqXHR.responseJSON.message != null) {
                                toast(jqXHR.responseJSON.message, true, false);
                            }
                        }
                    });
                }
            }

            setGoals(goals) {
                var self = this;

                // Clear language array before processing to prevent duplicates
                self.language = [];

                goals.forEach(function (item) {
                    switch (item.name) {
                        case 'source':
                            self.source = item.value;
                            break;
                        case 'destination':
                            self.destination = item.value;
                            break;
                        case 'name':
                            self.processName = item.value;
                            break;
                        case 'output_docs':
                            if (item.value === 'output-docs-no-transforms') {
                                self.outputDocs.noTransforms = 1;
                            }

                            if (item.value === 'output-docs-original') {
                                self.outputDocs.original = 1;
                            }

                            if (item.value === 'output-docs-modified') {
                                self.outputDocs.modified = 1;
                            }

                            if (item.value === 'output-docs-modified-no-transforms') {
                                self.outputDocs.modified = 1;
                                self.outputDocs.noTransforms = 1;
                                self.outputDocs.original = 0;
                            }

                            break;

                        case "searchd-blacklist-mode-support":
                            if (item.value === 'on') {
                                self.searchdSettings = {'blacklist-mode': 1};
                            }
                            break;
                        case 'query-complexity-validation':
                            if (item.value === 'on') {
                                self.queryComplexityValidation = 1;
                            }
                            break;
                        case 'max-matches-percent':
                            self.maxMatchesPercent = item.value;
                            break;
                        case 'output_matching_queries':
                            if (item.value === 'on') {
                                self.outputDocs.matchingQueries = 1;
                            }
                            break;
                        case 'language':
                            // Add deduplication as safety net
                            if (!self.language.includes(item.value)) {
                                self.language.push(item.value);
                            }
                            break;
                        case 'max_batch_size':
                            self.maxBatchSize = item.value;
                            break;
                        case 'min_threads':
                            self.minThreads = item.value;
                            break;
                        case 'max_threads':
                            self.maxThreads = item.value;
                            break;
                        case 'nlp_settings':
                            self.nlpSettings = item.value;
                            break;
                        case 'stopwords':
                            self.stopwords = item.value;
                            break;
                        case 'exceptions':
                            self.exceptions = item.value;
                            break;
                        case 'fetch_min_bytes':
                            self.kafkaConfig['fetch.min.bytes'] = parseInt(item.value);
                            break;
                        case 'fetch_max_wait_ms':
                            self.kafkaConfig['fetch.max.wait.ms'] = parseInt(item.value);
                            break;
                        case 'fetch_max_bytes':
                            self.kafkaConfig['fetch.max.bytes'] = parseInt(item.value);
                            break;
                        case 'max_poll_records':
                            self.kafkaConfig['max.poll.records'] = parseInt(item.value);
                            break;
                    }
                });

                if (self.processName == null || self.processName.length == 0) {
                    // Show validation error for name field
                    const nameField = $('#process-name');
                    nameField.addClass('is-invalid');
                    nameField.after('<span class="invalid-feedback d-block" role="alert"><strong>Name cannot be empty</strong></span>');

                    // Scroll to the name field
                    nameField[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
                    return; // Stop execution
                } else if (this.source == null) {
                    // Show validation error for source field
                    const sourceField = $('#source');
                    sourceField.addClass('is-invalid');
                    sourceField.after('<span class="invalid-feedback d-block" role="alert"><strong>You must select a source</strong></span>');

                    // Scroll to the source field
                    sourceField[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
                    return; // Stop execution
                } else if (this.destination == null) {
                    // Show validation error for destination field
                    const destField = $('#destination');
                    destField.addClass('is-invalid');
                    destField.after('<span class="invalid-feedback d-block" role="alert"><strong>You must select a destination</strong></span>');

                    // Scroll to the destination field
                    destField[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
                    return; // Stop execution
                } else {
                    $.ajax({
                        url: '/admin/process/resolveGoals',
                        type: 'post',
                        data: {
                            source: this.source,
                            destination: this.destination
                        },
                        success: function (data) {
                            self.source = data.source;
                            self.destination = data.destination;
                            next();
                        },

                        error: function(jqXHR, textStatus, errorThrown) {
                            if (jqXHR.responseJSON.message != null) {
                                toast(jqXHR.responseJSON.message, true, false);
                            }
                        }
                    });
                }
            }

            getAttrData(id) {
                return this.attrs[id];
            }

            getSelectedAttrs() {
                let result = [];

                $.each(this.selectedAttrs, function (key, item) {
                    result.push({
                        name: item.name,
                        path: item.path,
                        type: item.selectedType
                    });
                });

                $.each(this.selectedMergedAttrs, function (key, item) {
                    result.push({
                        name: item.name,
                        path: item.path.replace(/<br>/gi, "&&"),
                        type: item.selectedType
                    });
                });

                return result
            }

            removeMergedAttr(id) {
                let idList = [];
                this.selectedMergedAttrs[id].mergedFrom.forEach(id => {
                    this.attrs[id].unsetSelection();
                    this.attrs[id].setName(null);
                    idList.push(id)
                });

                delete this.selectedMergedAttrs[id];
                return idList
            }

            removeSelectedAttr(id) {
                this.attrs[id].unsetSelection();
                this.attrs[id].setName(null);

                delete this.selectedAttrs[id];
                return this.attrs[id];
            }

            selectAttr(id, type) {
                this.attrs[id].setSelected(type);
                var fullName = this.attrs[id].path;
                var explodedPath = fullName.split('.');
                var name = explodedPath[explodedPath.length - 1];

                this.selectedAttrs[id] = this.attrs[id];
                name = this.selectedAttrs[id].normalizeName(name);

                if (!this.issetName(name)) {
                    this.selectedAttrs[id].setName(name);
                } else {
                    this.selectedAttrs[id].setName(fullName);
                }

                return this.attrs[id];
            }

            issetName(name, stored = false) {
                let isset = false;
                let counter = 0;

                $.each(this.selectedAttrs, function (key, item) {
                    if (item.name != null && item.name == name) {
                        counter++;
                        if (stored) {
                            if (counter > 1) {
                                isset = true;
                            }
                        } else {
                            isset = true;
                        }
                    }
                });

                return isset;
            }
        }

        class ProcessFiller {
            setName(name) {
                $('#process-name').val(name)
            }

            setSource(id) {
                $('#source').val(id)
            }

            setDestination(id) {
                $('#destination').val(id)
            }

            setOutputDocs(hash) {
                if (hash.charAt(3) === "1") {
                    $('#output-matching_queries').prop({'checked': true});
                }

                if (hash.charAt(0) === "1" && hash.charAt(1) === "1" && hash.charAt(2) === "1") {
                    $('#output-docs-modified-no-transforms').prop({'checked': true});
                }

                if (hash.charAt(0) === "1") {
                    $('#output-docs-modified').prop({'checked': true});
                } else if (hash.charAt(1) === "1") {
                    $('#output-docs-original').prop({'checked': true});
                } else if (hash.charAt(2) === "1") {
                    $('#output-docs-no-transforms').prop({'checked': true});
                }
            }

            setBatch(batch) {
                $('#max-batch-size').val(batch)
            }

            setMinWorkers(threads) {
                $('#min-threads').val(threads)
            }

            setMaxWorkers(threads) {
                $('#max-threads').val(threads)
            }

            setQueryValidation(enabled, value) {
                if (enabled === "1") {
                    $('#query-complexity-validation').click();
                    $("#max-matches-percent").val(value)
                    $("#j-max-matches-percent-value").text(value)
                }
            }

            setBlacklistSupport(enabled) {
                if (enabled) {
                    $("#searchd-blacklist-mode-support").prop({'checked': true});
                }
            }

            setMorphology(morphSelection, nlp_settings, exceptions, stopwords) {
                let morphSelectionArray = morphSelection.split(',')
                $('#language').val(morphSelectionArray)

                if (morphSelectionArray[0] === 'custom') {
                    $('.j-nlp-settings-block').show();

                    $('#nlp_settings').val(nlp_settings.replaceAll('&quot', '"'))
                    $('#stopwords').val(stopwords.replaceAll('&quot', '"'))
                    $('#exceptions').val(exceptions.replaceAll('&quot', '"'))
                }
            }

            setKafkaConfig(kafkaConfig) {
                $('#fetch-min-bytes').val(kafkaConfig['fetch.min.bytes']);
                $('#fetch-max-wait-ms').val(kafkaConfig['fetch.max.wait.ms']);
                $('#fetch-max-bytes').val(kafkaConfig['fetch.max.bytes']);
                $('#max-poll-records').val(kafkaConfig['max.poll.records']);
            }

            showPreloader() {
                $('.j-preloader').addClass('d-flex').removeClass('d-none')
            }

            hidePreloader() {
                $('.j-preloader').addClass('d-none').removeClass('d-flex');
            }

            setNodes(nodes, process) {
                for (let node of nodes) {
                    var item = {
                        name: node.name,
                        types: {}
                    };

                    item['types'][node.type] = {
                        id: process.badgesCount,
                        example: "",
                        count: process.allDocsCount
                    };

                    let rule = new Node(process.badgesCount, node.path, process.allDocsCount, item.types);
                    process.attrs[process.badgesCount] = rule;

                    let view = $(rule.getView()).hide();
                    $('#schema-section').append(view);

                    rule = process.selectAttr(process.badgesCount, node.type);
                    rule.setName(node.name);
                    $('#approve-section').append(rule.getView());
                    process.badgesCount++;
                }

                $('[data-toggle="popover"]').popover();
            }

            setJSLT(jsltConfig, process) {
                $("#jslt-config").val(jsltConfig)
                process.setJSLTConfig(jsltConfig)
            }

            setKafkaConfig(kafkaConfig) {
                if (kafkaConfig) {
                    $('#fetch-min-bytes').val(kafkaConfig['fetch.min.bytes'] || 1);
                    $('#fetch-max-wait-ms').val(kafkaConfig['fetch.max.wait.ms'] || 500);
                    $('#fetch-max-bytes').val(kafkaConfig['fetch.max.bytes'] || 1048576);
                    $('#max-poll-records').val(kafkaConfig['max.poll.records'] || 500);
                }
            }
        }

        var process = new Process();

        // Define global callback accessible to all handlers
        let goalsCallback;
        @if(!empty($process))
            goalsCallback = function () {
            let filler = new ProcessFiller();
            filler.showPreloader();

            process.setId({{$process['id']}})
            filler.setName("{{$process['name']}}");
            filler.setSource("{{$process['source']['id']}}");
            filler.setDestination("{{$process['destination']['id']}}");
            filler.setQueryValidation("{{$process['values']['user_request']['query_complexity_validation']['enabled'] ?? 0 }}",
                {{$process['values']['user_request']['query_complexity_validation']['max_matches_percent'] ?? 0 }})
            filler.setMorphology("{{$process['values']['user_request']['language']}}",
                "{!! str_replace(["\n","\r",'"'], ['\n', '','&quot'], $process['values']['user_request']['nlp_settings'] ?? "") !!}",
                "{!! str_replace(["\n","\r",'"'], ['\n', '','&quot'], $process['values']['user_request']['exceptions'] ?? "")  !!}",
                "{!! str_replace(["\n","\r",'"'], ['\n', '','&quot'], $process['values']['user_request']['stopwords'] ?? "")  !!}"
            )

            filler.setOutputDocs("{{$process['values']['user_request']['output_docs']}}")
            filler.setBatch("{{$process['values']['user_request']['max_batch_size']}}")
            filler.setMinWorkers("{{ $process['values']['user_request']['min_threads'] ?? 1 }}")
            filler.setMaxWorkers("{{$process['values']['user_request']['max_threads']}}")
            filler.setBlacklistSupport({{!empty($process['values']['user_request']['searchd_settings'])? true : false}})
                 filler.setNodes({!! $process['values']['user_request']['attrs'] !!}, process)
                 filler.setJSLT("{!! str_replace(["\n","\r","\""], ['\n', '',"\\\""], urldecode($process['values']['user_request']['jslt_conf'] ?? "")) !!}", process)
                 filler.setKafkaConfig({!! json_encode($process['values']['user_request']['kafka_config'] ?? null) !!})
                 filler.hidePreloader();
        }
        @else
            goalsCallback = function () {}
        @endif

        $(document).ready(function () {
            process.drawGoals(goalsCallback);

        }).on('click', '.j-edit-process', function (element) {
            var id = $(this).attr('data-id');
            document.location.href = "/admin/process/new?process_id=" + id;
        }).on('click', '#refresh-schema', function () {
            process.progress = 0;
            process.selectedAttrs = {};
            process.currentSection = 1;
            process.drawParse();
        }).on('click', '.j-scheme-type', function () {
            let element = $(this);
            let id = element.attr('data-id');
            let type = element.attr('data-type');
            let node = process.selectAttr(id, type);
            $('#approve-section').append(node.getView());
            element.parent().parent().hide();
            $('[data-toggle="popover"]').popover();
        }).on('click', '.j-approved-type', function () {
            var element = $(this);
            var id = element.attr('data-id');
            let source = element.attr('data-source');
            $(this).parent().remove();

            if (source === "merged") {
                let removedList = process.removeMergedAttr(id);
                removedList.forEach(id => {
                    $('#schema-section [data-id="' + id + '"]').parent().parent().show();
                });
            } else {
                process.removeSelectedAttr(id);
                $('#schema-section [data-id="' + id + '"]').parent().parent().show();
            }

            $('.popover').popover('hide');
        }).on('submit', '#save-goals-form', function (e) {
            e.preventDefault();
            var data = $(this).serializeArray();
            process.setGoals(data);
        }).on('click', '.j-progress-title', function () {
            var element = $('.j-progress-title');
            var clickedIndex = element.index(this);
            process.currentSection = clickedIndex;

            element.each(function (index) {
                $(this).addClass('block-disabled');
                if (index <= clickedIndex) {
                    $(this).removeClass('block-disabled');
                }
            });

            switch ($(this).attr('data-title')) {
                case 'goals':
                    process.drawGoals(goalsCallback);
                    break;
                case 'analyze':
                    process.drawParse();
                    break;
                case 'schema':
                    process.drawSchema();
                    break;
                case 'finish':
                    process.drawFinish();
                    break;
                default:
                    process.drawGoals(goalsCallback);
                    break;
            }
        }).on("blur", '.j-approved-path', function () {
            var id = $(this).next().next().next().data('id');
            var text = $(this).html();

            if (process.selectedAttrs[id] != null) {
                text = process.selectedAttrs[id].setPath(text);
            } else {
                text = process.selectedMergedAttrs[id].setPath(text);
            }

            $(this).html(text);
        }).on("blur", '.j-approved-key', function () {
            var id = $(this).next().next().data('id');
            var text = $(this).html();
            $(this).removeClass("invalid");

            if (process.selectedAttrs[id] != null) {
                text = process.selectedAttrs[id].setName(text);
            } else {
                text = process.selectedMergedAttrs[id].setName(text);
            }

            if (process.issetName(text, true)) {
                $(this).popover({
                    placement: 'bottom',
                    html: true,
                    trigger: 'hover',
                    content: "In case multiple original fields mapped to one occur in a document <strong>the first value will be used</strong>"
                }).addClass("invalid");
            } else {
                $(this).popover('dispose')
            }

            $(this).html(text);
        }).on("change", '#query-complexity-validation', function () {
            $('.j-max-matches-percent').show();
            $('#j-max-matches-percent-value').show();
        }).on('click', '#next-button', function () {
            next();
        }).on('click', '#add-badges-button', function () {
            $('#custom-rules-modal').modal('show');
        }).on('click', '#add-jslt-conf', function () {
            $('#jslt-conf-modal').modal('show');
        }).on('click', '#add-custom-rule', function () {
            var pathInput = $('#custom-rule-path');
            var typeInput = $('#custom-rule-type');

            var path = pathInput.val();
            var regex = /^(([a-zA-Z0-9\*\-_\[\]{}])+\.?)+$/;

            if (path.length <= 0) {
                alert('Path can\'t be empty');
            } else if (!regex.test(path)) {
                alert('Wrong path');
            } else {
                var item = {
                    types: {}
                };

                item['types'][typeInput.val()] = {
                    id: process.badgesCount,
                    example: "",
                    count: process.allDocsCount
                };

                let rule = new Node(process.badgesCount, path, process.allDocsCount, item.types);
                process.attrs[process.badgesCount] = rule;

                let view = $(rule.getView()).hide();
                $('#schema-section').append(view);

                rule = process.selectAttr(process.badgesCount, typeInput.val());
                $('#approve-section').append(rule.getView());
                process.badgesCount++;

                pathInput.val('');
                typeInput.val('string');
                $('#custom-rules-modal').modal('hide');
                $('[data-toggle="popover"]').popover();
            }
        }).on('click', '#add-jslt-config', function () {
            process.setJSLTConfig($('#jslt-config').val());
            $('#jslt-conf-modal').modal('hide');
        }).on('submit', '#add-process-form', function (e) {
            e.preventDefault();
            var data = $(this).serializeArray();

            $.ajax({
                url: '/admin/process/add',
                type: 'post',
                data: data,
                success: function () {
                    document.location.href = '/admin/process';
                },
                error: function (error) {
                    // Clear previous errors
                    $('.is-invalid').removeClass('is-invalid');
                    $('.invalid-feedback').remove();

                    if (error.responseJSON && error.responseJSON.errors) {
                        let textError = '';

                        for (let key in error.responseJSON.errors) {
                            // Highlight the specific field
                            let field = $('[name="' + key + '"]');
                            if (field.length > 0) {
                                field.addClass('is-invalid');

                                // Add error message below the field
                                let errorMsg = '<span class="invalid-feedback d-block" role="alert">' +
                                    '<strong>' + error.responseJSON.errors[key].join(', ') + '</strong></span>';
                                field.after(errorMsg);
                            }

                            // Still show toast for overall feedback
                            textError += '<b>' + key + '</b>' + '<ul><li>' + error.responseJSON.errors[key].join("</li><li>") + '</li></ul>';
                        }

                        toast(textError, true, false);

                        // Scroll to first error field
                        let firstError = $('.is-invalid').first();
                        if (firstError.length > 0) {
                            firstError[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
                        }
                    }
                }
            });
        }).on('change', '#max-matches-percent', () => {
            $('#j-max-matches-percent-value').text($('#max-matches-percent').val());
        }).on('click', '.j-merge-badges', function () {
            let id = $(this).next().attr('data-id'),
                source = $(this).next().attr('data-source'),
                select = $('#select-merge-node');

            select.empty()

            for (let selectedIndex in process.selectedMergedAttrs) {
                let selected = process.selectedMergedAttrs[selectedIndex];
                if (selected.id == id && source === "merged") {
                    continue;
                }
                if (selected.selectedType !== "json" && selected.selectedType !== "json[]") {
                    let option = new Option(selected.path, selected.id);
                    $(option).attr("data-source", "merged");
                    select.append(option);
                }
            }

            for (let selectedIndex in process.selectedAttrs) {
                let selected = process.selectedAttrs[selectedIndex];
                if (selected.id == id && source === "origin") {
                    continue;
                }
                if (selected.selectedType !== "json" && selected.selectedType !== "json[]") {
                    let option = new Option(selected.path, selected.id);
                    $(option).attr("data-source", "origin");
                    select.append(option);
                }
            }

            $('#merge-nodes').attr({"data-merge-id": id})
            $('#merge-modal').modal('show');
        }).on('click', '#merge-nodes', function () {
            let mergedNode = process.mergeNodes({
                from: {
                    id: $(this).attr('data-merge-id'),
                    source: $(this).attr('data-source')
                },
                to: {
                    id: parseInt($('#select-merge-node :selected').val()),
                    source: $('#select-merge-node :selected').attr('data-source')
                }
            });
            $('#approve-section').append(mergedNode.getView());
            $('#merge-modal').modal('hide');
        }).on("change", "#language", function (e) {
            let languageSelectedValues = $(this).val();
            let nlpSettingsNode = $('.j-nlp-settings-block');

            nlpSettingsNode.hide();

            if (languageSelectedValues && languageSelectedValues.includes("custom")) {
                // If custom is selected, deselect all others and show NLP settings
                $('#language option').prop('selected', false);
                $('#language option[value="custom"]').prop('selected', true);
                nlpSettingsNode.show();
            }
        }).on('input', '#process-name', function() {
            const name = $(this).val();
            const nameField = $(this);

            // Clear previous validation
            nameField.removeClass('is-invalid is-valid');
            nameField.next('.invalid-feedback').remove();

            if (name.length > 0) {
                if (name.trim().length >= 1) {
                    nameField.addClass('is-valid');
                } else {
                    nameField.addClass('is-invalid');
                    nameField.after('<span class="invalid-feedback d-block">Name cannot be empty</span>');
                }
            }
        }).on('focus', '#process-name, #source, #destination', function() {
            // Clear all validation errors when user starts interacting with form
            clearValidationErrors();
        }).on('change', '#source, #destination', function() {
            const field = $(this);
            const value = field.val();

            // Clear previous validation
            field.removeClass('is-invalid is-valid');
            field.next('.invalid-feedback').remove();

            if (value && value !== '') {
                field.addClass('is-valid');
            } else {
                field.addClass('is-invalid');
                const fieldName = field.attr('id') === 'source' ? 'source' : 'destination';
                field.after('<span class="invalid-feedback d-block">You must select a ' + fieldName + '</span>');
            }
        }).on('input', '#custom-rule-path', function() {
            const path = $(this).val();
            const validation = validateCustomRulePath(path);

            $(this).removeClass('is-invalid is-valid');
            $(this).next('.invalid-feedback').remove();

            if (path.length > 0) {
                if (validation.valid) {
                    $(this).addClass('is-valid');
                } else {
                    $(this).addClass('is-invalid');
                    $(this).after('<span class="invalid-feedback d-block">' + validation.message + '</span>');
                }
            }
        }).on('click', '#add-custom-rule', function() {
            const pathInput = $('#custom-rule-path');
            const path = pathInput.val();
            const validation = validateCustomRulePath(path);

            if (!validation.valid) {
                pathInput.addClass('is-invalid');
                pathInput.after('<span class="invalid-feedback d-block">' + validation.message + '</span>');
                return;
            }

            // ... rest of existing code will continue to execute if validation passes
        }).on('input', '#fetch-min-bytes, #fetch-max-wait-ms, #fetch-max-bytes, #max-poll-records', function() {
            const fieldName = $(this).attr('name');
            const value = $(this).val();
            const validation = validateKafkaField(fieldName, value);

            $(this).removeClass('is-invalid is-valid');
            $(this).next('.invalid-feedback').remove();

            if (value.length > 0) {
                if (validation.valid) {
                    $(this).addClass('is-valid');
                } else {
                    $(this).addClass('is-invalid');
                    $(this).after('<span class="invalid-feedback d-block">' + validation.message + '</span>');
                }
            }
        }).on('input', '#jslt-config', function() {
            const config = $(this).val();
            const validation = validateJSLTConfig(config);

            $(this).removeClass('is-invalid is-valid');
            $(this).next('.invalid-feedback').remove();

            if (config.length > 0 && !validation.valid) {
                $(this).addClass('is-invalid');
                $(this).after('<span class="invalid-feedback d-block">' + validation.message + '</span>');
            } else if (config.length > 0) {
                $(this).addClass('is-valid');
            }
        });

        function next() {
            $('.j-progress-title:eq( ' + (process.currentSection + 1) + ' )').click();
        }

        // Client-side validation functions
        function validateCustomRulePath(path) {
            if (path.length <= 0) {
                return { valid: false, message: 'Path cannot be empty' };
            }

            const regex = /^(([a-zA-Z0-9\*\-_\[\]{}])+\.?)+$/;
            if (!regex.test(path)) {
                return { valid: false, message: 'Invalid path format. Use only letters, numbers, dots, hyphens, underscores, brackets, and asterisks' };
            }

            return { valid: true };
        }

        function validateKafkaField(fieldName, value) {
            const numValue = parseInt(value);

            switch(fieldName) {
                case 'fetch_min_bytes':
                    return numValue >= 1 ? { valid: true } : { valid: false, message: 'Must be at least 1' };
                case 'fetch_max_wait_ms':
                    return numValue >= 0 ? { valid: true } : { valid: false, message: 'Must be at least 0' };
                case 'fetch_max_bytes':
                    return numValue >= 1 ? { valid: true } : { valid: false, message: 'Must be at least 1' };
                case 'max_poll_records':
                    return numValue >= 1 ? { valid: true } : { valid: false, message: 'Must be at least 1' };
                default:
                    return { valid: true };
            }
        }

        function validateJSLTConfig(config) {
            if (!config || config.trim().length === 0) {
                return { valid: true }; // Empty is valid
            }

            try {
                // Basic JSON structure validation
                const trimmed = config.trim();
                if ((trimmed.startsWith('{') && trimmed.endsWith('}')) ||
                    (trimmed.startsWith('[') && trimmed.endsWith(']'))) {
                    JSON.parse(trimmed);
                    return { valid: true };
                }

                // If not JSON, treat as valid JSLT expression
                return { valid: true };
            } catch (e) {
                return { valid: false, message: 'Invalid JSON format' };
            }
        }

        function clearValidationErrors() {
            $('.is-invalid').removeClass('is-invalid');
            $('.is-valid').removeClass('is-valid');
            $('.invalid-feedback').remove();
        }

    </script>
@stop
