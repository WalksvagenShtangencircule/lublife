({
    init: function () {
        if (AVAIL("vendorKeys", "catalog", "GET")) {
            leftSide("fas fa-fw fa-key", i18n("moduleVendorKeys"), "?#vendorKeys", "households");
        }
        moduleLoaded("vendorKeys", this);
    },

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

    formatCreated: function (ts) {
        if (ts == null || ts === "") {
            return "—";
        }
        let n = Number(ts);
        let d = new Date(n * 1000);
        if (isNaN(d.getTime())) {
            return String(ts);
        }
        return d.toLocaleString();
    },

    route: function () {
        if (!AVAIL("vendorKeys", "catalog", "GET")) {
            page404();
            return;
        }
        document.title = i18n("windowTitle") + " :: " + i18n("moduleVendorKeys");
        $("#altForm").hide();
        modules.vendorKeys.render();
    },

    render: function () {
        loadingStart();
        GET("vendorKeys", "catalog", false, true).
            fail(FAIL).
            done(r => {
                let d = modules.vendorKeys.unwrap(r, "vendorKeysCatalog");
                if (!d || !Array.isArray(d.rows)) {
                    warning(i18n("vendorKeys.badResponse"));
                    return;
                }
                let rows = d.rows;
                let total = d.total != null ? d.total : rows.length;
                let tb = "";
                for (let i = 0; i < rows.length; i++) {
                    let row = rows[i];
                    let rf = String(row.rfid || "");
                    let rfEsc = escapeHTML(rf);
                    let ca = escapeHTML(modules.vendorKeys.formatCreated(row.created_at));
                    tb += "<tr data-rfid='" + escapeHTML(rf) + "'><td><code>" + rfEsc + "</code></td><td>" + ca + "</td>";
                    if (AVAIL("vendorKeys", "remove", "POST")) {
                        tb += "<td><button type='button' class='btn btn-sm btn-outline-danger vk-del'>" + escapeHTML(i18n("delete")) + "</button></td>";
                    } else {
                        tb += "<td></td>";
                    }
                    tb += "</tr>";
                }
                if (!tb.length) {
                    tb = "<tr><td colspan='" + (AVAIL("vendorKeys", "remove", "POST") ? "3" : "2") + "' class='text-muted'>—</td></tr>";
                }

                let uploadBlock = "";
                if (AVAIL("vendorKeys", "importCsv", "POST")) {
                    uploadBlock =
                        "<div class='card card-outline card-secondary mb-3'>" +
                        "<div class='card-header'><h4 class='card-title mb-0'>" + escapeHTML(i18n("vendorKeys.uploadTitle")) + "</h4></div>" +
                        "<div class='card-body'>" +
                        "<p class='text-muted small mb-2'>" + escapeHTML(i18n("vendorKeys.uploadHint")) + "</p>" +
                        "<div class='form-group mb-2'>" +
                        "<div class='custom-file'>" +
                        "<input type='file' class='custom-file-input' id='vkCsvFile' accept='.csv,text/csv,text/plain'>" +
                        "<label class='custom-file-label' for='vkCsvFile'>" + escapeHTML(i18n("vendorKeys.chooseFile")) + "</label>" +
                        "</div></div>" +
                        "<button type='button' class='btn btn-primary' id='vkCsvBtn'>" + escapeHTML(i18n("vendorKeys.runImport")) + "</button>" +
                        "</div></div>";
                }

                $("#mainForm").html(
                    "<div class='card card-primary card-outline'>" +
                    "<div class='card-header'><h3 class='card-title mb-0'>" + escapeHTML(i18n("vendorKeys.title")) + "</h3></div>" +
                    "<div class='card-body'>" +
                    "<p class='text-muted'>" + escapeHTML(i18n("vendorKeys.lead")) + "</p>" +
                    uploadBlock +
                    "<p class='mb-2'><strong>" + escapeHTML(i18n("vendorKeys.totalLabel")) + "</strong> " + escapeHTML(String(total)) + "</p>" +
                    "<div class='table-responsive'><table class='table table-sm table-striped'><thead><tr>" +
                    "<th>RFID</th><th>" + escapeHTML(i18n("vendorKeys.createdAt")) + "</th>" +
                    (AVAIL("vendorKeys", "remove", "POST") ? "<th></th>" : "") +
                    "</tr></thead><tbody>" + tb + "</tbody></table></div>" +
                    "</div></div>"
                );

                $("#vkCsvFile").on("change", function () {
                    let fn = (this.files && this.files[0] && this.files[0].name) ? this.files[0].name : i18n("vendorKeys.chooseFile");
                    $(this).next(".custom-file-label").text(fn);
                });

                $("#vkCsvBtn").on("click", function () {
                    let inp = document.getElementById("vkCsvFile");
                    if (!inp || !inp.files || !inp.files[0]) {
                        error(i18n("vendorKeys.needFile"), i18n("error"));
                        return;
                    }
                    let reader = new FileReader();
                    reader.onload = function (ev) {
                        let text = ev.target.result;
                        loadingStart();
                        POST("vendorKeys", "importCsv", false, { csv: text }).
                            fail(FAIL).
                            done(resp => {
                                let imp = modules.vendorKeys.unwrap(resp, "vendorKeysImport");
                                if (!imp) {
                                    warning(i18n("vendorKeys.badResponse"));
                                    return;
                                }
                                let lines = [];
                                lines.push(i18n("vendorKeys.resultImported", imp.imported != null ? imp.imported : 0));
                                lines.push(i18n("vendorKeys.resultDuplicates", imp.duplicates != null ? imp.duplicates : 0));
                                let errs = imp.errors || [];
                                if (errs.length) {
                                    lines.push(i18n("vendorKeys.resultErrors", errs.length));
                                    for (let j = 0; j < errs.length && j < 40; j++) {
                                        let e = errs[j];
                                        lines.push((e.line != null ? ("стр. " + e.line + ": ") : "") + (e.error || ""));
                                    }
                                }
                                message(lines.join("\n"), i18n("vendorKeys.resultTitle"), errs.length ? 24 : 10);
                                modules.vendorKeys.render();
                            }).
                            always(loadingDone);
                    };
                    reader.readAsText(inp.files[0], "UTF-8");
                });

                $("#mainForm").off("click", ".vk-del").on("click", ".vk-del", function () {
                    let tr = $(this).closest("tr");
                    let rfid = tr.attr("data-rfid");
                    if (!rfid) {
                        return;
                    }
                    if (!confirm(i18n("vendorKeys.confirmDelete"))) {
                        return;
                    }
                    loadingStart();
                    POST("vendorKeys", "remove", false, { rfid: rfid }).
                        fail(FAIL).
                        done(() => {
                            modules.vendorKeys.render();
                        }).
                        always(loadingDone);
                });
            }).
            always(loadingDone);
    },
}).init();
