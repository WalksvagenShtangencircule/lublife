({
    _chDau: null,
    _chWauMau: null,
    _chEvDay: null,
    _chEvType: null,

    destroyChart: function (prop) {
        if (this[prop]) {
            this[prop].destroy();
            this[prop] = null;
        }
    },

    destroyAllCharts: function () {
        this.destroyChart("_chDau");
        this.destroyChart("_chWauMau");
        this.destroyChart("_chEvDay");
        this.destroyChart("_chEvType");
    },

    selectedHouseId: function () {
        let v = $.trim($("#analyticsHouseSelect").val());
        if (v === "" || v === "0") {
            return "";
        }
        return v;
    },

    fillHouseSelect: function ($sel, houses) {
        let prev = $sel.val();
        $sel.empty();
        $sel.append($("<option>", { value: "", text: i18n("analytics.allHouses") }));
        if (!houses || !houses.length) {
            return;
        }
        let arr = houses.slice().sort(function (a, b) {
            return String(a.houseFull || "").localeCompare(String(b.houseFull || ""), undefined, { sensitivity: "base" });
        });
        for (let i = 0; i < arr.length; i++) {
            let h = arr[i];
            if (h.houseId == null) {
                continue;
            }
            $sel.append($("<option>", { value: String(h.houseId), text: h.houseFull || ("#" + h.houseId) }));
        }
        if (prev) {
            let o = $sel.find("option[value='" + prev.replace(/'/g, "\\'") + "']");
            if (o.length) {
                $sel.val(prev);
            }
        }
    },

    loadHouses: function (doneFn) {
        let $sel = $("#analyticsHouseSelect");
        if (!AVAIL("addresses", "addresses", "GET")) {
            $sel.prop("disabled", true);
            $("#analyticsHouseLoadHint").text(i18n("analytics.housesNoAddressApi"));
            if (typeof doneFn === "function") {
                doneFn();
            }
            return;
        }
        $sel.prop("disabled", false);
        $("#analyticsHouseLoadHint").text("");
        QUERY("addresses", "addresses", { include: "houses" }, true).
        done(function (r) {
            let houses = (r && r.addresses && r.addresses.houses) ? r.addresses.houses : [];
            modules.analytics.fillHouseSelect($sel, houses);
        }).
        fail(function () {
            $("#analyticsHouseLoadHint").text(i18n("analytics.housesLoadError"));
        }).
        always(function () {
            if (typeof doneFn === "function") {
                doneFn();
            }
        });
    },

    init: function () {
        if (AVAIL("analytics", "stats", "GET")) {
            leftSide("fas fa-fw fa-chart-line", i18n("analytics.analytics"), "?#analytics", "productivity");
        }
        moduleLoaded("analytics", this);
    },

    eventLabel: function (n) {
        let k = "analytics.ev" + n;
        let t = i18n(k);
        return (t === k) ? String(n) : t;
    },

    previewLabel: function (n) {
        let k = "analytics.pr" + n;
        let t = i18n(k);
        return (t === k) ? String(n) : t;
    },

    escapeHtml: function (s) {
        return String(s)
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;");
    },

    formatEventTime: function (unixSec) {
        let d = new Date(unixSec * 1000);
        function z(n) { return n < 10 ? "0" + n : String(n); }
        return d.getFullYear() + "-" + z(d.getMonth() + 1) + "-" + z(d.getDate()) + " " + z(d.getHours()) + ":" + z(d.getMinutes()) + ":" + z(d.getSeconds());
    },

    attachEventCamshots: function ($root) {
        if (typeof IntersectionObserver === "undefined") {
            $root.find(".analytics-camshot-slot").each(function () {
                modules.analytics.loadCamshotIntoSlot($(this));
            });
            return;
        }
        let io = new IntersectionObserver(function (entries) {
            entries.forEach(function (ent) {
                if (!ent.isIntersecting) {
                    return;
                }
                io.unobserve(ent.target);
                modules.analytics.loadCamshotIntoSlot($(ent.target));
            });
        }, { root: null, rootMargin: "80px", threshold: 0.01 });
        $root.find(".analytics-camshot-slot").each(function () {
            io.observe(this);
        });
    },

    loadCamshotIntoSlot: function ($slot) {
        let uuid = $.trim($slot.data("uuid") || "");
        if (!uuid) {
            return;
        }
        if (!AVAIL("analytics", "camshot", "GET")) {
            $slot.html("<span class=\"text-muted small p-2\">" + i18n("analytics.previewNoApi") + "</span>");
            return;
        }
        $slot.html("<span class=\"text-muted small p-2\">" + i18n("analytics.previewLoading") + "</span>");
        QUERYID("analytics", "camshot", uuid, {}, true).
        done(function (r) {
            if (!r || !r.camshot || !r.camshot.base64) {
                $slot.html("<span class=\"text-muted small p-2\">" + i18n("analytics.previewError") + "</span>");
                return;
            }
            let ct = r.camshot.contentType || "image/jpeg";
            let src = "data:" + ct + ";base64," + r.camshot.base64;
            if (ct.indexOf("video/") === 0) {
                $slot.empty().append(
                    $("<video>", { controls: true, playsinline: true }).attr("src", src).css({ maxWidth: "100%", maxHeight: "240px" })
                );
            } else {
                $slot.empty().append(
                    $("<img>", { alt: "" }).attr("src", src).css({ maxWidth: "100%", maxHeight: "240px", objectFit: "contain", display: "block" })
                );
            }
        }).
        fail(function () {
            $slot.html("<span class=\"text-muted small p-2\">" + i18n("analytics.previewError") + "</span>");
        });
    },

    renderShell: function () {
        let h = "<div class=\"card mt-2 mb-2\">" +
          "<div class=\"card-header\"><h3 class=\"card-title mb-0\">" + i18n("analytics.statsTitle") + "</h3></div>" +
          "<div class=\"card-body\">" +
          "<p class=\"text-muted small\">" + i18n("analytics.metricHint") + "</p>" +
          "<div class=\"form-row align-items-end\">" +
          "<div class=\"form-group col-md-5 col-lg-4\">" +
          "<label for=\"analyticsHouseSelect\">" + i18n("analytics.house") + "</label>" +
          "<select id=\"analyticsHouseSelect\" class=\"form-control form-control-sm\">" +
          "<option value=\"\">" + i18n("analytics.allHouses") + "</option></select>" +
          "<span id=\"analyticsHouseLoadHint\" class=\"text-danger small d-block mt-1\"></span></div>" +
          "<div class=\"form-group col-md-3 col-lg-2\">" +
          "<label for=\"analyticsDays\">" + i18n("analytics.days") + "</label>" +
          "<select id=\"analyticsDays\" class=\"form-control form-control-sm\">" +
          "<option value=\"7\">7</option><option value=\"14\">14</option>" +
          "<option value=\"30\" selected>30</option><option value=\"60\">60</option></select></div>" +
          "<div class=\"form-group col-md-2\">" +
          "<button type=\"button\" class=\"btn btn-primary btn-sm\" id=\"analyticsRefresh\">" + i18n("analytics.refresh") + "</button></div></div>" +
          "<div id=\"analyticsStatsOut\" class=\"mt-3\"></div></div></div>" +
          "<div class=\"card mt-2 mb-2\">" +
          "<div class=\"card-header\"><h3 class=\"card-title mb-0\">" + i18n("analytics.eventsTitle") + "</h3></div>" +
          "<div class=\"card-body\">" +
          "<p class=\"text-muted small\">" + i18n("analytics.eventsListHint") + "</p>" +
          "<div class=\"form-row\">" +
          "<div class=\"form-group col-md-4 col-lg-3\"><label>" + i18n("analytics.phone") + "</label>" +
          "<input type=\"text\" id=\"analyticsPhone\" class=\"form-control form-control-sm\" placeholder=\"\"></div>" +
          "<div class=\"form-group col-md-auto d-flex align-items-end flex-wrap\">" +
          "<button type=\"button\" class=\"btn btn-secondary btn-sm mr-1 mb-1\" id=\"analyticsLoadEv\">" + i18n("analytics.loadEvents") + "</button>" +
          "<button type=\"button\" class=\"btn btn-outline-secondary btn-sm mb-1\" id=\"analyticsLoadEvAllHouse\">" + i18n("analytics.loadAllHouseEvents") + "</button></div></div>" +
          "<div id=\"analyticsEventsOut\" class=\"mt-3\"></div></div></div>";
        $("#mainForm").html(h);
        $("#analyticsRefresh").off("click").on("click", function () { modules.analytics.fetchStats(); });
        $("#analyticsLoadEv").off("click").on("click", function () { modules.analytics.fetchEvents(); });
        $("#analyticsLoadEvAllHouse").off("click").on("click", function () { modules.analytics.fetchEventsAllHouse(); });
        $("#analyticsDays").off("change").on("change", function () {
            modules.analytics.fetchStats();
            modules.analytics.fetchEvents();
        });
        $("#analyticsHouseSelect").off("change").on("change", function () {
            $("#analyticsPhone").val("");
            modules.analytics.fetchStats();
            modules.analytics.fetchEvents();
        });
    },

    fetchStats: function () {
        let days = parseInt($("#analyticsDays").val(), 10) || 30;
        let hid = modules.analytics.selectedHouseId();
        let q = { days: days };
        if (hid !== "") {
            q.houseId = hid;
        }
        loadingStart();
        QUERY("analytics", "stats", q, true).
        done(function (r) {
            modules.analytics.destroyChart("_chDau");
            modules.analytics.destroyChart("_chWauMau");
            let el = $("#analyticsStatsOut");
            el.empty();
            if (!r || !r.stats) {
                el.html("<div class=\"alert alert-warning\">" + i18n("analytics.noData") + "</div>");
                return;
            }
            let s = r.stats;
            let seriesDays = s.days != null ? s.days : days;
            let wau = s.wau7 != null ? s.wau7 : 0;
            let periodU = s.activeUsersPeriod != null ? s.activeUsersPeriod : 0;
            let hasSeries = s.series && s.series.length;
            if (!hasSeries && !wau && !periodU) {
                el.html("<div class=\"alert alert-warning\">" + i18n("analytics.noData") + "</div>");
                return;
            }
            let showWauMau = wau > 0 || periodU > 0;
            let capDau = i18n("analytics.chartDauCaption", seriesDays);
            let capBars = i18n("analytics.chartWauMauCaption", seriesDays);
            let rowHtml;
            if (hasSeries && showWauMau) {
                rowHtml =
                    "<div class=\"row\">" +
                    "<div class=\"col-lg-8 mb-3\"><p class=\"text-sm text-muted mb-1\">" + capDau + "</p>" +
                    "<div class=\"position-relative\" style=\"height:280px;\"><canvas id=\"analyticsChartDau\"></canvas></div></div>" +
                    "<div class=\"col-lg-4 mb-3\"><p class=\"text-sm text-muted mb-1\">" + capBars + "</p>" +
                    "<div class=\"position-relative\" style=\"height:280px;\"><canvas id=\"analyticsChartWauMau\"></canvas></div></div></div>";
            } else if (hasSeries) {
                rowHtml =
                    "<div class=\"row\">" +
                    "<div class=\"col-12 mb-3\"><p class=\"text-sm text-muted mb-1\">" + capDau + "</p>" +
                    "<div class=\"position-relative\" style=\"height:280px;\"><canvas id=\"analyticsChartDau\"></canvas></div></div></div>";
            } else if (showWauMau) {
                rowHtml =
                    "<div class=\"row\">" +
                    "<div class=\"col-lg-6 offset-lg-3 mb-3\"><p class=\"text-sm text-muted mb-1\">" + capBars + "</p>" +
                    "<div class=\"position-relative\" style=\"height:280px;\"><canvas id=\"analyticsChartWauMau\"></canvas></div></div></div>";
            } else {
                rowHtml = "<div class=\"alert alert-light\">" + i18n("analytics.noDauSeries") + "</div>";
            }
            el.html(rowHtml);
            let labels = [];
            let vals = [];
            if (hasSeries) {
                for (let i = 0; i < s.series.length; i++) {
                    labels.push(s.series[i].day);
                    vals.push(s.series[i].activeUsers != null ? s.series[i].activeUsers : s.series[i].activeFlats);
                }
            }
            let ctxD = document.getElementById("analyticsChartDau");
            if (hasSeries && ctxD && typeof Chart !== "undefined") {
                modules.analytics._chDau = new Chart(ctxD.getContext("2d"), {
                    type: "line",
                    data: {
                        labels: labels,
                        datasets: [{
                            label: i18n("analytics.dauSeries", seriesDays),
                            data: vals,
                            borderColor: "rgba(0, 123, 255, 0.9)",
                            backgroundColor: "rgba(0, 123, 255, 0.08)",
                            fill: true,
                            lineTension: 0.2,
                            pointRadius: 2,
                        }],
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        legend: { display: true },
                        scales: {
                            yAxes: [{ ticks: { beginAtZero: true, precision: 0 } }],
                        },
                    },
                });
            }
            let ctxW = document.getElementById("analyticsChartWauMau");
            if (showWauMau && ctxW && typeof Chart !== "undefined") {

                modules.analytics._chWauMau = new Chart(ctxW.getContext("2d"), {
                    type: "bar",
                    data: {
                        labels: [ i18n("analytics.wau7"), i18n("analytics.activeUsersPeriodLabel", seriesDays) ],
                        datasets: [{
                            label: i18n("analytics.chartWauMauBarsDataset", seriesDays),
                            data: [ wau, periodU ],
                            backgroundColor: [
                                "rgba(40, 167, 69, 0.75)",
                                "rgba(111, 66, 193, 0.75)",
                            ],
                        }],
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        legend: { display: false },
                        scales: {
                            yAxes: [{ ticks: { beginAtZero: true, precision: 0 } }],
                        },
                    },
                });
            }
        }).
        fail(FAIL).
        always(loadingDone);
    },

    fetchEvents: function () {
        let hid = modules.analytics.selectedHouseId();
        let el = $("#analyticsEventsOut");
        el.empty();
        if (hid === "") {
            el.html("<div class=\"alert alert-info\">" + i18n("analytics.pickHouseForEvents") + "</div>");
            return;
        }
        let days = parseInt($("#analyticsDays").val(), 10) || 30;
        let until = Math.floor(Date.now() / 1000);
        let since = until - days * 86400;
        let q = { limit: 200, houseId: hid, since: since, until: until };
        let phone = $.trim($("#analyticsPhone").val());
        if (phone !== "") {
            q.phone = phone;
        }
        loadingStart();
        QUERY("analytics", "events", q, true).
        done(function (r) {
            modules.analytics.destroyChart("_chEvDay");
            modules.analytics.destroyChart("_chEvType");
            el.empty();
            if (!r || !r.events || !r.events.events) {
                el.html("<div class=\"alert alert-warning\">" + i18n("analytics.noData") + "</div>");
                return;
            }
            let evs = r.events.events;
            if (!evs.length) {
                el.html("<div class=\"alert alert-warning\">" + i18n("analytics.noData") + "</div>");
                return;
            }
            let parts = [];
            for (let i = 0; i < evs.length; i++) {
                let e = evs[i];
                let ts = e.date ? modules.analytics.formatEventTime(e.date) : "—";
                let addr = e.addressLine || e.houseFull || (e.flatNumber != null && e.flatNumber !== "" ? (i18n("analytics.colFlat") + " " + modules.analytics.escapeHtml(String(e.flatNumber))) : "—");
                let evLab = modules.analytics.eventLabel(e.event != null ? e.event : "?");
                let pr = e.preview != null ? modules.analytics.previewLabel(e.preview) : "";
                let opened = e.opened ? i18n("analytics.openedYes") : i18n("analytics.openedNo");
                let extra = [];
                if (e.code) {
                    extra.push(i18n("analytics.code") + " " + modules.analytics.escapeHtml(String(e.code)));
                }
                if (e.rfid) {
                    extra.push("RFID " + modules.analytics.escapeHtml(String(e.rfid)));
                }
                let uuid = (e.image_uuid != null && String(e.image_uuid).trim() !== "") ? String(e.image_uuid).trim() : "";
                let flatLine = "";
                if (e.flatNumber != null && String(e.flatNumber).trim() !== "") {
                    flatLine = "<div class=\"small mt-1\">" + modules.analytics.escapeHtml(i18n("analytics.colFlat")) + ": " + modules.analytics.escapeHtml(String(e.flatNumber).trim()) + "</div>";
                }
                let openPhoneLine = "";
                if (e.eventUserPhone != null && String(e.eventUserPhone).trim() !== "") {
                    openPhoneLine = "<div class=\"small mt-1\">" + modules.analytics.escapeHtml(i18n("analytics.eventOpenPhoneLabel")) + ": " + modules.analytics.escapeHtml(String(e.eventUserPhone).trim()) + "</div>";
                }
                parts.push(
                    "<div class=\"card mb-2 analytics-event-card\">" +
                    "<div class=\"card-body py-2 px-3\">" +
                    "<div class=\"row align-items-start\">" +
                    "<div class=\"col-md-4 col-lg-3 mb-2 mb-md-0\">" +
                    "<div class=\"analytics-camshot-slot rounded border bg-light d-flex align-items-center justify-content-center\" style=\"min-height:140px;overflow:hidden;\" data-uuid=\"" +
                    modules.analytics.escapeHtml(uuid) + "\">" +
                    (uuid ? ("<span class=\"text-muted small p-2\">" + i18n("analytics.previewWait") + "</span>") : ("<span class=\"text-muted small p-2\">" + i18n("analytics.noPreview") + "</span>")) +
                    "</div></div>" +
                    "<div class=\"col-md-8 col-lg-9\">" +
                    "<div class=\"small text-muted\">" + modules.analytics.escapeHtml(ts) + "</div>" +
                    "<div class=\"font-weight-bold\">" + modules.analytics.escapeHtml(String(addr)) + "</div>" +
                    flatLine +
                    openPhoneLine +
                    "<div class=\"mt-1\">" + modules.analytics.escapeHtml(evLab) +
                    (pr ? (" · " + modules.analytics.escapeHtml(pr)) : "") +
                    " · " + modules.analytics.escapeHtml(opened) + "</div>" +
                    (extra.length ? ("<div class=\"small text-muted mt-1\">" + extra.join(" · ") + "</div>") : "") +
                    "</div></div></div></div>"
                );
            }
            el.html(parts.join(""));
            modules.analytics.attachEventCamshots(el);
        }).
        fail(FAIL).
        always(loadingDone);
    },

    fetchEventsAllHouse: function () {
        $("#analyticsPhone").val("");
        modules.analytics.fetchEvents();
    },

    route: function () {
        document.title = i18n("windowTitle") + " :: " + i18n("analytics.analytics");
        $("#altForm").hide();
        subTop();
        modules.analytics.destroyAllCharts();
        modules.analytics.renderShell();
        modules.analytics.loadHouses(function () {
            modules.analytics.fetchStats();
            modules.analytics.fetchEvents();
        });
    },
}).init();
