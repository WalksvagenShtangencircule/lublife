({
    _overview: null,

    init: function () {
        if (AVAIL("mediaserver", "streams", "GET")) {
            leftSide("fas fa-fw fa-tower-broadcast", i18n("mediaserver.mediaserver"), "?#mediaserver", "households");
        }
        moduleLoaded("mediaserver", this);
    },

    unwrap: function (r, key) {
        if (!r) {
            return null;
        }
        if (r["200"] && typeof r["200"] === "object" && r["200"][key] !== undefined) {
            return r["200"][key];
        }
        if (r[key] !== undefined) {
            return r[key];
        }
        return null;
    },

    stateLabel: function (s) {
        if (s === "on") {
            return i18n("mediaserver.stateOn");
        }
        if (s === "error") {
            return i18n("mediaserver.stateError");
        }
        if (s === "off") {
            return i18n("mediaserver.stateOff");
        }
        return i18n("mediaserver.stateUnknown");
    },

    stateClass: function (s) {
        if (s === "on") {
            return "badge badge-success";
        }
        if (s === "error") {
            return "badge badge-danger";
        }
        if (s === "off") {
            return "badge badge-secondary";
        }
        return "badge badge-light";
    },

    copyText: function (text) {
        let t = String(text || "");
        if (!t) {
            return;
        }
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(t).then(function () {
                message(i18n("mediaserver.copied"));
            }).catch(function () {
                warning(i18n("mediaserver.copyFail"));
            });
        } else {
            let ta = $("<textarea>").css({ position: "fixed", left: "-9999px" }).appendTo("body").val(t);
            ta.select();
            try {
                document.execCommand("copy");
                message(i18n("mediaserver.copied"));
            } catch (e) {
                warning(i18n("mediaserver.copyFail"));
            }
            ta.remove();
        }
    },

    loadOverview: function (doneFn) {
        QUERY("mediaserver", "streams", {}, true).
        done(r => {
            let w = modules.mediaserver.unwrap(r, "mediaserverStreams");
            modules.mediaserver._overview = w || { streams: [], serverTitle: "", apiError: "" };
        }).
        fail(FAIL).
        always(() => {
            if (typeof doneFn === "function") {
                doneFn();
            }
        });
    },

    loadAudit: function (doneFn) {
        if (!AVAIL("mediaserver", "audit", "GET")) {
            if (typeof doneFn === "function") {
                doneFn([]);
            }
            return;
        }
        QUERY("mediaserver", "audit", { limit: 150 }, true).
        done(r => {
            let a = modules.mediaserver.unwrap(r, "mediaserverAudit");
            let entries = (a && a.entries) ? a.entries : [];
            if (typeof doneFn === "function") {
                doneFn(entries);
            }
        }).
        fail(() => {
            if (typeof doneFn === "function") {
                doneFn([]);
            }
        });
    },

    formatTime: function (unixSec) {
        let d = new Date((unixSec || 0) * 1000);
        function z(n) { return n < 10 ? "0" + n : String(n); }
        return d.getFullYear() + "-" + z(d.getMonth() + 1) + "-" + z(d.getDate()) + " " + z(d.getHours()) + ":" + z(d.getMinutes()) + ":" + z(d.getSeconds());
    },

    showAddStream: function () {
        cardForm({
            title: i18n("mediaserver.addStreamTitle"),
            footer: true,
            borderless: true,
            topApply: true,
            apply: i18n("mediaserver.save"),
            fields: [
                { id: "name", type: "text", title: i18n("mediaserver.streamName"), placeholder: i18n("mediaserver.streamNamePlaceholder"), required: true },
                { id: "configJson", type: "area", title: i18n("mediaserver.optionalConfigJson"), placeholder: "{}" },
            ],
            callback: values => {
                let cfg = {};
                let raw = $.trim(values.configJson || "");
                if (raw) {
                    try {
                        cfg = JSON.parse(raw);
                    } catch (e) {
                        error(i18n("errors.badRequest"));
                        return;
                    }
                }
                loadingStart();
                POST("mediaserver", "stream", false, { name: $.trim(values.name), config: cfg }).
                done(resp => {
                    if (resp && resp["502"]) {
                        error(i18n("mediaserver.flussonicError"));
                        return;
                    }
                    message(i18n("mediaserver.save"));
                    modules.mediaserver.route({});
                }).
                fail(FAIL).
                always(loadingDone);
            },
        }).show();
    },

    deleteStream: function (name) {
        if (!confirm(sprintf(i18n("mediaserver.confirmDelete"), name))) {
            return;
        }
        loadingStart();
        DELETE("mediaserver", "stream", name).
        done(resp => {
            if (resp && resp["502"]) {
                error(i18n("mediaserver.flussonicError"));
                return;
            }
            modules.mediaserver.route({});
        }).
        fail(FAIL).
        always(loadingDone);
    },

    applyToCamera: function (cameraId, hlsUrl, embedUrl) {
        if (!cameraId) {
            return;
        }
        loadingStart();
        POST("mediaserver", "applyCamera", false, {
            cameraId: cameraId,
            hlsUrl: hlsUrl,
            embedUrl: embedUrl,
        }).
        done(() => {
            message(i18n("mediaserver.applyCamera"));
        }).
        fail(FAIL).
        always(loadingDone);
    },

    renderAudit: function ($target, entries) {
        $target.empty();
        if (!entries || !entries.length) {
            $target.html("<p class=\"text-muted\">—</p>");
            return;
        }
        cardTable({
            target: $target,
            title: { caption: i18n("mediaserver.auditTitle") },
            columns: [
                { title: i18n("mediaserver.auditWhen") },
                { title: i18n("mediaserver.auditWho") },
                { title: i18n("mediaserver.auditAction") },
                { title: i18n("mediaserver.auditStream") },
                { title: i18n("mediaserver.auditDetails"), fullWidth: true },
            ],
            rows: () => entries.map(function (row) {
                let det = row.details;
                let detStr = typeof det === "object" ? JSON.stringify(det) : String(det || "");
                if (detStr.length > 200) {
                    detStr = detStr.substring(0, 200) + "…";
                }
                return {
                    uid: "a-" + row.id,
                    cols: [
                        { data: modules.mediaserver.formatTime(row.createdAt), nowrap: true },
                        { data: row.login || "—", nowrap: true },
                        { data: row.action || "—", nowrap: true },
                        { data: row.streamName || "—", nowrap: true },
                        { data: $("<span>").text(detStr).html(), nowrap: false },
                    ],
                };
            }),
        }).show();
    },

    render: function () {
        let o = modules.mediaserver._overview || { streams: [], apiError: "", serverTitle: "" };
        $("#altForm").hide();
        subTop();

        let warn = "";
        if (o.apiError) {
            warn = "<div class=\"alert alert-warning\">" + i18n("mediaserver.apiError") + ": " + $("<span>").text(o.apiError).html() + "</div>";
        }
        let serverLine = (o.serverTitle ? ("<p class=\"text-muted small mb-2\">" + i18n("mediaserver.server") + ": " + $("<span>").text(o.serverTitle).html() + "</p>") : "");
        $("#mainForm").html(warn + serverLine + "<div id=\"ms-streams\"></div><div id=\"ms-audit\"></div>");

        let canManage = AVAIL("mediaserver", "stream", "POST");
        let rows = (o.streams || []).map(s => {
            let menu = [];
            if (canManage) {
                menu.push({
                    title: i18n("mediaserver.deleteStream"),
                    icon: "far fa-fw fa-trash-alt",
                    click: () => modules.mediaserver.deleteStream(s.streamName),
                });
            }
            if (canManage && s.cameraId) {
                menu.push({
                    title: i18n("mediaserver.applyCamera"),
                    icon: "fas fa-fw fa-video",
                    click: () => modules.mediaserver.applyToCamera(s.cameraId, s.hlsUrl, s.embedUrl),
                });
            }
            return {
                uid: s.streamName,
                cols: [
                    { data: s.streamName, nowrap: true },
                    {
                        data: "<span class=\"" + modules.mediaserver.stateClass(s.state) + "\">" + modules.mediaserver.stateLabel(s.state) + "</span>",
                        nowrap: true,
                    },
                    {
                        data: s.cameraName ? ($("<span>").text(s.cameraName).html() + (s.cameraId ? " (#" + s.cameraId + ")" : "")) : "—",
                    },
                    {
                        data: "<button type=\"button\" class=\"btn btn-sm btn-outline-primary rbt-ms-copy-hls\" data-url=\"" + encodeURIComponent(s.hlsUrl) + "\">" + i18n("mediaserver.copyHls") + "</button>",
                        nowrap: true,
                    },
                    {
                        data: "<a class=\"btn btn-sm btn-outline-secondary\" href=\"" + s.embedUrl.replace(/"/g, "&quot;") + "\" target=\"_blank\" rel=\"noopener noreferrer\">" + i18n("mediaserver.openEmbed") + "</a>",
                        nowrap: true,
                    },
                ],
                dropDown: menu.length ? { items: menu } : undefined,
            };
        });

        cardTable({
            target: "#ms-streams",
            id: "rbt-mediaserver-streams",
            title: {
                caption: i18n("mediaserver.streamsTitle"),
                button: canManage ? {
                    caption: i18n("mediaserver.addStream"),
                    click: modules.mediaserver.showAddStream,
                } : undefined,
                altButton: {
                    caption: i18n("mediaserver.refresh"),
                    icon: "fas fa-fw fa-sync-alt",
                    click: () => {
                        loadingStart();
                        modules.mediaserver.loadOverview(() => {
                            modules.mediaserver.render();
                            loadingDone();
                        });
                    },
                },
            },
            columns: [
                { title: i18n("mediaserver.streamName") },
                { title: i18n("mediaserver.state") },
                { title: i18n("mediaserver.camera"), fullWidth: true },
                { title: i18n("mediaserver.hlsUrl") },
                { title: i18n("mediaserver.embedUrl") },
            ],
            rows: () => rows,
        }).show();

        $("#ms-streams").find(".rbt-ms-copy-hls").off("click").on("click", function () {
            let u = decodeURIComponent($(this).attr("data-url") || "");
            modules.mediaserver.copyText(u);
        });

        let $auditHost = $("#ms-audit");
        if (AVAIL("mediaserver", "audit", "GET")) {
            modules.mediaserver.loadAudit(function (entries) {
                modules.mediaserver.renderAudit($auditHost, entries);
            });
        } else {
            $auditHost.html("<p class=\"text-muted small\">" + i18n("mediaserver.noAuditAccess") + "</p>");
        }
    },

    route: function () {
        document.title = i18n("windowTitle") + " :: " + i18n("mediaserver.mediaserver");
        if (!AVAIL("mediaserver", "streams", "GET")) {
            $("#altForm").hide();
            subTop();
            $("#mainForm").html("<div class=\"alert alert-info\">" + i18n("mediaserver.noAccess") + "</div>");
            loadingDone();
            return;
        }
        loadingStart();
        modules.mediaserver.loadOverview(() => {
            modules.mediaserver.render();
            loadingDone();
        });
    },
}).init();
