({
    menu: false,

    init: function () {
        let can =
            AVAIL("tools", "bulkImportSubscribers", "POST") ||
            AVAIL("tools", "bulkImportKeys", "POST");
        if (can) {
            this.menu = true;
            leftSide("fas fa-fw fa-toolbox", i18n("moduleTools"), "?#tools", "households");
        }
        moduleLoaded("tools", this);
    },

    /**
     * Успешный ответ frontend — JSON тела HTTP 200, без ключа "200" внутри объекта
     * (в отличие от сырого внутреннего массива вида { 200: { toolsSample: ... } }).
     */
    unwrap: function (r, key) {
        if (!r) {
            return null;
        }
        if (r[key] !== undefined) {
            return r[key];
        }
        if (r["200"] && r["200"][key] !== undefined) {
            return r["200"][key];
        }
        return null;
    },

    errText: function (code) {
        let k = "tools.err." + String(code || "");
        if (i18n(k) !== k) {
            return i18n(k);
        }
        return String(code || "");
    },

    loadHouses: function (done) {
        if (!AVAIL("addresses", "addresses", "GET")) {
            done(null, "noAddressesApi");
            return;
        }
        loadingStart();
        QUERY("addresses", "addresses", { include: "houses" }, true).
            fail(FAIL).
            done(r => {
                let houses = (r && r.addresses && r.addresses.houses) ? r.addresses.houses : [];
                done(houses, null);
            }).
            always(loadingDone);
    },

    houseOptions: function (houses) {
        let o = [];
        for (let i = 0; i < houses.length; i++) {
            let h = houses[i];
            let id = h.houseId;
            let label = h.houseFull ? h.houseFull : (h.house ? String(h.house) : ("#" + id));
            o.push({ value: String(id), text: label });
        }
        o.sort((a, b) => (a.text > b.text ? 1 : (a.text < b.text ? -1 : 0)));
        return o;
    },

    downloadSample: function (apiMethod) {
        loadingStart();
        QUERY("tools", apiMethod, {}, true).
            fail(FAIL).
            done(r => {
                let s = modules.tools.unwrap(r, "toolsSample");
                if (!s || !s.content) {
                    warning(i18n("tools.sampleLoadFail"));
                    return;
                }
                let name = s.fileName || "sample.csv";
                let blob = new Blob([ s.content ], { type: "text/csv;charset=utf-8" });
                let url = URL.createObjectURL(blob);
                let a = document.createElement("a");
                a.href = url;
                a.download = name;
                a.click();
                URL.revokeObjectURL(url);
                message(i18n("tools.sampleDownloaded"));
            }).
            always(loadingDone);
    },

    renderIndex: function () {
        let cards = [];
        if (AVAIL("tools", "bulkImportSubscribers", "POST")) {
            cards.push({
                title: i18n("tools.cardSubscribers"),
                hint: i18n("tools.cardSubscribersHint"),
                href: "?#tools&show=subscribers",
            });
        }
        if (AVAIL("tools", "bulkImportKeys", "POST")) {
            cards.push({
                title: i18n("tools.cardKeys"),
                hint: i18n("tools.cardKeysHint"),
                href: "?#tools&show=keys",
            });
        }
        let html = "<div class='row'>";
        for (let i = 0; i < cards.length; i++) {
            let c = cards[i];
            html += "<div class='col-md-6 col-lg-4 mb-3'>" +
                "<div class='card card-outline card-primary h-100'>" +
                "<div class='card-body d-flex flex-column'>" +
                "<h5 class='card-title'>" + escapeHTML(c.title) + "</h5>" +
                "<p class='card-text text-muted flex-grow-1'>" + escapeHTML(c.hint) + "</p>" +
                "<a class='btn btn-primary mt-2' href='" + escapeHTML(c.href) + "'>" + escapeHTML(i18n("tools.open")) + "</a>" +
                "</div></div></div>";
        }
        html += "</div>";
        if (!cards.length) {
            html = "<p class='text-muted'>" + escapeHTML(i18n("tools.noUtilities")) + "</p>";
        }
        $("#mainForm").html(
            "<div class='card card-primary card-outline'>" +
            "<div class='card-header'><h3 class='card-title mb-0'>" + escapeHTML(i18n("tools.utilities")) + "</h3></div>" +
            "<div class='card-body'>" + html + "</div></div>"
        );
        loadingDone();
    },

    renderSubscribers: function () {
        modules.tools.loadHouses((houses, err) => {
            if (err === "noAddressesApi") {
                $("#mainForm").html(
                    "<div class='alert alert-warning'>" + escapeHTML(i18n("tools.needAddressesRight")) + "</div>"
                );
                loadingDone();
                return;
            }
            let opts = modules.tools.houseOptions(houses || []);
            let houseField = {
                id: "houseId",
                type: "select2",
                title: i18n("tools.house"),
                options: opts,
                placeholder: i18n("tools.selectHouse"),
            };
            let fileField = {
                id: "csv",
                type: "files",
                title: i18n("tools.csvFile"),
            };
            $("#mainForm").html(
                "<div class='mb-2'><a class='btn btn-sm btn-outline-secondary' href='?#tools'>" + escapeHTML(i18n("tools.backToList")) + "</a></div>" +
                "<div id='toolsFormWrap'></div>"
            );
            cardForm({
                target: "#toolsFormWrap",
                title: i18n("tools.bulkSubscribersTitle"),
                footer: true,
                borderless: true,
                topApply: false,
                apply: i18n("tools.runImport"),
                fields: [ houseField, fileField ],
                callback: function (result) {
                    let hid = parseInt(result.houseId, 10);
                    if (!hid) {
                        error(i18n("tools.selectHouseError"), i18n("error"));
                        return;
                    }
                    let f = result.csv;
                    if (!f || !f[0]) {
                        error(i18n("tools.needFile"), i18n("error"));
                        return;
                    }
                    let reader = new FileReader();
                    reader.onload = function (ev) {
                        let text = ev.target.result;
                        loadingStart();
                        POST("tools", "bulkImportSubscribers", false, { houseId: hid, csv: text }).
                            fail(FAIL).
                            done(r => {
                                let d = modules.tools.unwrap(r, "toolsBulkSubscribers");
                                if (!d) {
                                    warning(i18n("tools.badResponse"));
                                    return;
                                }
                                modules.tools.showImportResult(d, "tools");
                            }).
                            always(loadingDone);
                    };
                    reader.readAsText(f[0], "UTF-8");
                },
            });
            let $extra = $("<div class='mt-2 mb-3'></div>");
            if (AVAIL("tools", "sampleImportSubscribers", "GET")) {
                $extra.append(
                    $("<button type='button' class='btn btn-sm btn-outline-secondary'></button>").text(i18n("tools.downloadSample")).on("click", () => {
                        modules.tools.downloadSample("sampleImportSubscribers");
                    })
                );
            }
            $("#toolsFormWrap .card-body").first().prepend($extra);
            loadingDone();
        });
    },

    renderKeys: function () {
        modules.tools.loadHouses((houses, err) => {
            if (err === "noAddressesApi") {
                $("#mainForm").html(
                    "<div class='alert alert-warning'>" + escapeHTML(i18n("tools.needAddressesRight")) + "</div>"
                );
                loadingDone();
                return;
            }
            let opts = modules.tools.houseOptions(houses || []);
            let houseField = {
                id: "houseId",
                type: "select2",
                title: i18n("tools.house"),
                options: opts,
                placeholder: i18n("tools.selectHouse"),
            };
            let fileField = {
                id: "csv",
                type: "files",
                title: i18n("tools.csvFile"),
            };
            $("#mainForm").html(
                "<div class='mb-2'><a class='btn btn-sm btn-outline-secondary' href='?#tools'>" + escapeHTML(i18n("tools.backToList")) + "</a></div>" +
                "<div id='toolsFormWrap'></div>"
            );
            cardForm({
                target: "#toolsFormWrap",
                title: i18n("tools.bulkKeysTitle"),
                footer: true,
                borderless: true,
                topApply: false,
                apply: i18n("tools.runImport"),
                fields: [ houseField, fileField ],
                callback: function (result) {
                    let hid = parseInt(result.houseId, 10);
                    if (!hid) {
                        error(i18n("tools.selectHouseError"), i18n("error"));
                        return;
                    }
                    let f = result.csv;
                    if (!f || !f[0]) {
                        error(i18n("tools.needFile"), i18n("error"));
                        return;
                    }
                    let reader = new FileReader();
                    reader.onload = function (ev) {
                        let text = ev.target.result;
                        loadingStart();
                        POST("tools", "bulkImportKeys", false, { houseId: hid, csv: text }).
                            fail(FAIL).
                            done(r => {
                                let d = modules.tools.unwrap(r, "toolsBulkKeys");
                                if (!d) {
                                    warning(i18n("tools.badResponse"));
                                    return;
                                }
                                modules.tools.showImportResult(d, "tools");
                            }).
                            always(loadingDone);
                    };
                    reader.readAsText(f[0], "UTF-8");
                },
            });
            let $extra = $("<div class='mt-2 mb-3'></div>");
            if (AVAIL("tools", "sampleImportKeys", "GET")) {
                $extra.append(
                    $("<button type='button' class='btn btn-sm btn-outline-secondary'></button>").text(i18n("tools.downloadSample")).on("click", () => {
                        modules.tools.downloadSample("sampleImportKeys");
                    })
                );
            }
            $("#toolsFormWrap .card-body").first().prepend($extra);
            loadingDone();
        });
    },

    showImportResult: function (d, ns) {
        let ok = d.imported != null ? d.imported : 0;
        let errs = d.errors || [];
        let lines = [];
        lines.push(i18n(ns + ".importDone", ok));
        if (errs.length) {
            lines.push(i18n(ns + ".importErrors", errs.length));
            for (let i = 0; i < errs.length && i < 50; i++) {
                let e = errs[i];
                let msg = modules.tools.errText(e.error);
                let ln = e.line != null ? ("стр. " + e.line) : "";
                let fl = e.flat != null ? (" · " + e.flat) : "";
                lines.push(ln + ": " + msg + fl);
            }
            if (errs.length > 50) {
                lines.push("…");
            }
        }
        message(lines.join("\n"), i18n("tools.resultTitle"), errs.length ? 30 : 12);
    },

    route: function (params) {
        $("#altForm").hide();
        document.title = i18n("windowTitle") + " :: " + i18n("moduleTools");

        let show = params.show || "index";
        if (show === "subscribers") {
            if (!AVAIL("tools", "bulkImportSubscribers", "POST")) {
                page404();
                return;
            }
            loadingStart();
            modules.tools.renderSubscribers();
            return;
        }
        if (show === "keys") {
            if (!AVAIL("tools", "bulkImportKeys", "POST")) {
                page404();
                return;
            }
            loadingStart();
            modules.tools.renderKeys();
            return;
        }
        if (!AVAIL("tools", "bulkImportSubscribers", "POST") && !AVAIL("tools", "bulkImportKeys", "POST")) {
            page404();
            return;
        }
        loadingStart();
        modules.tools.renderIndex();
    },
}).init();
