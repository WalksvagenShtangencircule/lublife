({
    _overview: null,
    _previewAjaxQueue: [],
    _previewAjaxRunning: 0,
    _previewAjaxMax: 4,

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
        if (s === "not_on_server") {
            return i18n("mediaserver.stateNotOnServer");
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
        if (s === "not_on_server") {
            return "badge badge-warning";
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

    /**
     * Кадр с камеры (cameras/camshot): очередь + IntersectionObserver, как превью событий в аналитике.
     */
    attachStreamPreviews: function ($root) {
        let nodes = $root.find(".rbt-ms-preview-slot").toArray();
        if (!nodes.length) {
            return;
        }
        function startSlot($slot) {
            if ($slot.data("previewStarted")) {
                return;
            }
            $slot.data("previewStarted", true);
            modules.mediaserver._previewAjaxQueue.push($slot);
            modules.mediaserver.runNextStreamPreview();
        }
        if (typeof IntersectionObserver !== "undefined") {
            let io = new IntersectionObserver(function (entries) {
                for (let i = 0; i < entries.length; i++) {
                    if (!entries[i].isIntersecting) {
                        continue;
                    }
                    let el = entries[i].target;
                    io.unobserve(el);
                    startSlot($(el));
                }
            }, { root: null, rootMargin: "100px", threshold: 0.01 });
            for (let j = 0; j < nodes.length; j++) {
                io.observe(nodes[j]);
            }
        } else {
            for (let k = 0; k < nodes.length; k++) {
                startSlot($(nodes[k]));
            }
        }
    },

    runNextStreamPreview: function () {
        let t = modules.mediaserver;
        while (t._previewAjaxRunning < t._previewAjaxMax && t._previewAjaxQueue.length) {
            let $next = t._previewAjaxQueue.shift();
            if (!$next || !$next.length) {
                continue;
            }
            t._previewAjaxRunning++;
            t.loadStreamPreviewSlotNow($next, function () {
                t._previewAjaxRunning--;
                t.runNextStreamPreview();
            });
        }
    },

    loadStreamPreviewSlotNow: function ($slot, doneFn) {
        let cid = $.trim($slot.attr("data-camera-id") || "");
        if (!cid || !AVAIL("cameras", "camshot", "GET")) {
            $slot.html("<span class=\"text-muted small p-2\">" + i18n("mediaserver.previewNoApi") + "</span>");
            if (typeof doneFn === "function") {
                doneFn();
            }
            return;
        }
        $slot.html("<span class=\"text-muted small p-2\">" + i18n("mediaserver.previewLoading") + "</span>");
        let l = lStore("_lang") || config.defaultLanguage || "ru";
        let url = lStore("_server") + "/cameras/camshot/" + encodeURIComponent(cid) + "?_=" + Math.random();
        $.ajax({
            url: url,
            type: "GET",
            timeout: 90000,
            dataType: "json",
            beforeSend: function (xhr) {
                xhr.setRequestHeader("Authorization", "Bearer " + lStore("_token"));
                xhr.setRequestHeader("Accept-Language", l);
                xhr.setRequestHeader("X-Api-Refresh", "1");
            },
        }).
            done(function (r) {
                let shot = modules.mediaserver.unwrap(r, "shot");
                if (!shot && r && r.shot) {
                    shot = r.shot;
                }
                if (shot) {
                    let $img = $("<img>", { alt: "" }).attr("src", "data:image/jpeg;base64," + shot).css({
                        maxWidth: "100%",
                        maxHeight: "120px",
                        width: "auto",
                        objectFit: "contain",
                        display: "block",
                    });
                    $slot.empty().append($img);
                } else {
                    $slot.html("<span class=\"text-muted small p-2\">" + i18n("mediaserver.previewNoImage") + "</span>");
                }
            }).
            fail(function (xhr, status) {
                if (status === "timeout") {
                    $slot.html("<span class=\"text-muted small p-2\">" + i18n("mediaserver.previewTimeout") + "</span>");
                } else {
                    $slot.html("<span class=\"text-muted small p-2\">" + i18n("mediaserver.previewError") + "</span>");
                }
            }).
            always(function () {
                if (typeof doneFn === "function") {
                    doneFn();
                }
            });
    },

    showAddStream: function () {
        if (!modules.addresses || !modules.addresses.cameras || typeof modules.addresses.cameras.addCamera !== "function") {
            error(i18n("mediaserver.needAddressesCameras"));
            return;
        }
        if (!AVAIL("mediaserver", "cameraStream", "POST")) {
            error(i18n("mediaserver.noCameraStreamApi"));
            return;
        }
        modules.addresses.cameras.addCamera({ mediaserver: true });
    },

    showEditStream: function (cameraId) {
        let cid = parseInt(String(cameraId || ""), 10);
        if (!cid) {
            return;
        }
        if (!AVAIL("mediaserver", "updateCameraStream", "POST")) {
            error(i18n("mediaserver.noUpdateCameraStreamApi"));
            return;
        }
        if (!AVAIL("cameras", "cameras", "GET")) {
            error(i18n("mediaserver.needCamerasListForEdit"));
            return;
        }
        loadingStart();
        QUERY("cameras", "cameras", { by: "id", query: cid }, true).
            done(r => {
                let list = r.cameras && r.cameras.cameras ? r.cameras.cameras : [];
                let cam = null;
                for (let j in list) {
                    if (String(list[j].cameraId) === String(cid)) {
                        cam = list[j];
                        break;
                    }
                }
                if (!cam && list.length === 1) {
                    cam = list[0];
                }
                if (!cam) {
                    message(i18n("mediaserver.cameraNotFoundForEdit"), i18n("mediaserver.mediaserver"), 5);
                    return;
                }
                let ext = cam.ext;
                if (typeof ext === "string") {
                    try {
                        ext = $.trim(ext) ? JSON.parse(ext) : {};
                    } catch (e) {
                        ext = {};
                    }
                }
                if (!ext || typeof ext !== "object") {
                    ext = {};
                }
                let msnExisting = $.trim(String(ext.mediaserverStreamName || ext.flussonicStreamName || ""));
                cardForm({
                    title: i18n("mediaserver.editStreamTitle"),
                    footer: true,
                    borderless: true,
                    topApply: true,
                    apply: "save",
                    fields: [
                        {
                            id: "mediaserverStreamName",
                            type: "text",
                            title: i18n("mediaserver.mediaserverStreamNameField"),
                            placeholder: "Lug_1_2",
                            hint: i18n("mediaserver.mediaserverStreamNameHint"),
                            value: msnExisting,
                            required: true,
                            validate: v => {
                                let s = $.trim(String(v || ""));
                                if (!s) {
                                    return false;
                                }
                                return /^[A-Za-z0-9][A-Za-z0-9_.-]*$/.test(s);
                            },
                        },
                        {
                            id: "stream",
                            type: "text",
                            title: i18n("mediaserver.rtspStreamField"),
                            placeholder: "rtsp://",
                            hint: i18n("mediaserver.editStreamHint"),
                            value: $.trim(String(cam.stream || "")),
                            required: true,
                            validate: v => {
                                v = $.trim(String(v || ""));
                                if (!v) {
                                    return false;
                                }
                                try {
                                    if (!/^rtsps?:\/\/.+/.test(v)) {
                                        return false;
                                    }
                                    new URL(v);
                                    return true;
                                } catch (e) {
                                    return false;
                                }
                            },
                        },
                    ],
                    callback: values => {
                        let msn = $.trim(String(values.mediaserverStreamName || ""));
                        if (!msn || !/^[A-Za-z0-9][A-Za-z0-9_.-]*$/.test(msn)) {
                            error(i18n("mediaserver.mediaserverStreamNameInvalid"));
                            return;
                        }
                        let streamVal = $.trim(String(values.stream || ""));
                        loadingStart();
                        POST("mediaserver", "updateCameraStream", false, {
                            cameraId: cid,
                            mediaserverStreamName: msn,
                            stream: streamVal,
                        }).
                            done(() => {
                                message(i18n("mediaserver.streamUpdateOk"));
                                modules.mediaserver.route({});
                            }).
                            fail(xhr => {
                                let j = xhr && xhr.responseJSON ? xhr.responseJSON : null;
                                if (j && j.error === "applyUrlsFailed") {
                                    warning(i18n("mediaserver.applyUrlsFailed"));
                                    modules.mediaserver.route({});
                                } else if (j && (j.cameraId != null || j.error === "flussonicError")) {
                                    warning(i18n("mediaserver.streamUpdateFlussonicFailed"));
                                    modules.mediaserver.route({});
                                } else {
                                    FAIL(xhr);
                                }
                            }).
                            always(loadingDone);
                    },
                });
            }).
            fail(FAIL).
            always(loadingDone);
    },

    showAddStreamFlussonicOnly: function () {
        cardForm({
            title: i18n("mediaserver.addStreamTitle"),
            footer: true,
            borderless: true,
            topApply: true,
            apply: "save",
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
                done(() => {
                    message(i18n("mediaserver.save"));
                    modules.mediaserver.route({});
                }).
                fail(FAIL).
                always(loadingDone);
            },
        });
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

    deleteStreamAndCamera: function (streamName, cameraId) {
        let cid = parseInt(String(cameraId || ""), 10);
        let sn = $.trim(String(streamName || ""));
        if (!cid || !sn) {
            return;
        }
        if (!AVAIL("mediaserver", "deleteStreamAndCamera", "POST")) {
            error(i18n("mediaserver.noDeleteStreamAndCameraApi"));
            return;
        }
        if (!confirm(sprintf(i18n("mediaserver.confirmDeleteStreamAndCamera"), sn))) {
            return;
        }
        loadingStart();
        POST("mediaserver", "deleteStreamAndCamera", false, { streamName: sn, cameraId: cid }).
        done(() => {
            message(i18n("mediaserver.streamAndCameraDeleted"));
            modules.mediaserver.route({});
        }).
        fail(xhr => {
            if (xhr && parseInt(xhr.status, 10) === 502) {
                error(i18n("mediaserver.flussonicError"));
                return;
            }
            FAIL(xhr);
        }).
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
        modules.mediaserver._previewAjaxQueue = [];
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
        let canDeleteLinked = canManage && AVAIL("mediaserver", "deleteStreamAndCamera", "POST");
        let canEditStream = AVAIL("mediaserver", "updateCameraStream", "POST") && AVAIL("cameras", "cameras", "GET");
        let canPreview = AVAIL("cameras", "camshot", "GET");
        let rows = (o.streams || []).map(s => {
            let menu = [];
            if (canEditStream && s.cameraId) {
                menu.push({
                    title: i18n("mediaserver.editStream"),
                    icon: "fas fa-fw fa-edit",
                    click: () => modules.mediaserver.showEditStream(s.cameraId),
                });
            }
            if (canDeleteLinked && s.cameraId) {
                menu.push({
                    title: i18n("mediaserver.deleteStreamAndCamera"),
                    icon: "far fa-fw fa-trash-alt",
                    click: () => modules.mediaserver.deleteStreamAndCamera(s.streamName, s.cameraId),
                });
            } else if (canManage && !s.cameraId) {
                menu.push({
                    title: i18n("mediaserver.deleteStream"),
                    icon: "far fa-fw fa-trash-alt",
                    click: () => modules.mediaserver.deleteStream(s.streamName),
                });
            }
            return {
                uid: s.streamName,
                cols: [
                    {
                        data: (() => {
                            if (!s.cameraId) {
                                return "<span class=\"text-muted small\">—</span>";
                            }
                            if (!canPreview) {
                                return "<span class=\"text-muted small\">" + i18n("mediaserver.previewNoApi") + "</span>";
                            }
                            let escId = String(s.cameraId).replace(/&/g, "&amp;").replace(/"/g, "&quot;");
                            return "<div class=\"rbt-ms-preview-slot rounded border bg-light d-flex align-items-center justify-content-center\" style=\"min-width:140px;max-width:200px;min-height:96px;overflow:hidden;\" data-camera-id=\"" + escId + "\"><span class=\"text-muted small p-2\">" + i18n("mediaserver.previewWait") + "</span></div>";
                        })(),
                        nowrap: true,
                    },
                    { data: s.streamName, nowrap: true },
                    {
                        data: "<span class=\"" + modules.mediaserver.stateClass(s.state) + "\">" + modules.mediaserver.stateLabel(s.state) + "</span>",
                        nowrap: true,
                    },
                    {
                        data: s.cameraName ? ($("<span>").text(s.cameraName).html() + (s.cameraId ? " (#" + s.cameraId + ")" : "")) : "—",
                    },
                    {
                        data: (() => {
                            let hlsCopy = (s.dvrStreamUrl && String(s.dvrStreamUrl).trim()) ? String(s.dvrStreamUrl).trim() : String(s.hlsUrl || "").trim();
                            return "<button type=\"button\" class=\"btn btn-sm btn-outline-primary rbt-ms-copy-hls\" data-url=\"" + encodeURIComponent(hlsCopy) + "\">" + i18n("mediaserver.copyHls") + "</button>";
                        })(),
                        nowrap: true,
                    },
                    {
                        data: (() => {
                            let emb = (s.embedUrlStored && String(s.embedUrlStored).trim()) ? String(s.embedUrlStored).trim() : String(s.embedUrl || "").trim();
                            if (!emb) {
                                return "<span class=\"text-muted small\">—</span>";
                            }
                            return "<a class=\"btn btn-sm btn-outline-secondary\" href=\"" + emb.replace(/"/g, "&quot;") + "\" target=\"_blank\" rel=\"noopener noreferrer\">" + i18n("mediaserver.openEmbed") + "</a>";
                        })(),
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
                { title: i18n("mediaserver.previewColumn") },
                { title: i18n("mediaserver.streamName") },
                { title: i18n("mediaserver.state") },
                { title: i18n("mediaserver.camera"), fullWidth: true },
                { title: i18n("mediaserver.hlsUrl") },
                { title: i18n("mediaserver.embedUrl") },
            ],
            rows: () => rows,
        }).show();

        modules.mediaserver.attachStreamPreviews($("#ms-streams"));

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
