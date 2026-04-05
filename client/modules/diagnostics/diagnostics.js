({
    _chHist: null,
    _tgWaitTimer: null,
    _tgWaitWasActive: false,
    _tgConfigErrNotified: false,

    clearTgWait: function () {
        if (this._tgWaitTimer) {
            clearTimeout(this._tgWaitTimer);
            clearInterval(this._tgWaitTimer);
            this._tgWaitTimer = null;
        }
    },

    init: function () {
        if (AVAIL("diagnostics", "run", "GET")) {
            leftSide("fas fa-fw fa-stethoscope", i18n("diagnostics.diagnostics"), "?#diagnostics", "productivity");
        }
        moduleLoaded("diagnostics", this);
    },

    destroyChart: function () {
        modules.diagnostics.clearTgWait();
        if (this._chHist) {
            this._chHist.destroy();
            this._chHist = null;
        }
    },

    badge: function (st) {
        if (st === "ok") {
            return "success";
        }
        if (st === "warn") {
            return "warning";
        }
        if (st === "fail") {
            return "danger";
        }
        return "secondary";
    },

    renderTable: function ($body, checks) {
        $body.empty();
        if (!checks || !checks.length) {
            $body.append("<tr><td colspan='6' class='text-muted'>—</td></tr>");
            return;
        }
        for (let i = 0; i < checks.length; i++) {
            let c = checks[i];
            let pct = (c.percent != null) ? (c.percent + "%") : "";
            let val = (c.value != null && c.value !== "") ? String(c.value) : "";
            if (pct && val) {
                val = val + " (" + pct + ")";
            } else if (pct && !val) {
                val = pct;
            }
            let lat = (c.latencyMs != null) ? String(c.latencyMs) : "";
            let hint = c.hint ? modules.diagnostics.escapeHtml(c.hint) : "";
            if (c.explain) {
                hint += "<div class='text-muted mt-1' style='font-size:0.82em;line-height:1.35'>" +
                    modules.diagnostics.escapeHtml(c.explain) + "</div>";
            }
            let title = modules.diagnostics.escapeHtml(c.title || c.id || "");
            let grpRaw = c.group || "";
            let grpMap = {
                config: i18n("diagnostics.groupConfig"),
                datastores: i18n("diagnostics.groupDatastores"),
                os: i18n("diagnostics.groupOs"),
                integrations: i18n("diagnostics.groupIntegrations"),
                ssl: i18n("diagnostics.groupSsl"),
                media: i18n("diagnostics.groupMedia"),
                background: i18n("diagnostics.groupBackground"),
                systemd: i18n("diagnostics.groupSystemd"),
                insights: i18n("diagnostics.groupInsights"),
            };
            let grp = modules.diagnostics.escapeHtml(grpMap[grpRaw] || grpRaw);
            let st = c.status || "skip";
            $body.append(
                "<tr><td>" + grp + "</td><td>" + title + "</td>" +
                "<td><span class='badge badge-" + modules.diagnostics.badge(st) + "'>" + modules.diagnostics.escapeHtml(st) + "</span></td>" +
                "<td>" + modules.diagnostics.escapeHtml(val) + "</td><td>" + lat + "</td><td class='small'>" + hint + "</td></tr>"
            );
        }
    },

    formatLoadError: function (x) {
        let msg = i18n("diagnostics.loadError");
        if (x && x.responseJSON && x.responseJSON.error) {
            let err = x.responseJSON.error;
            if (err === "forbidden") {
                return i18n("diagnostics.forbidden");
            }
            if (err === "accessDenied") {
                return i18n("diagnostics.accessDenied");
            }
            return msg + " (" + err + ")";
        }
        if (x && x.status) {
            return msg + " (HTTP " + x.status + ")";
        }
        return msg;
    },

    escapeHtml: function (s) {
        if (!s) {
            return "";
        }
        return String(s)
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;");
    },

    renderSummary: function (s) {
        if (!s || !s.counts) {
            return "";
        }
        let c = s.counts;
        return "<span class='mr-3'><span class='badge badge-success'>OK " + c.ok + "</span></span>" +
            "<span class='mr-3'><span class='badge badge-warning'>! " + c.warn + "</span></span>" +
            "<span class='mr-3'><span class='badge badge-danger'>× " + c.fail + "</span></span>" +
            "<span class='mr-3'><span class='badge badge-secondary'>… " + c.skip + "</span></span>";
    },

    /** Показ баннера и кнопки теста алерта, если в config включена симуляция */
    updateSimulateUi: function (simulateActive) {
        let $banner = $("#diagnosticsSimulateBanner");
        let $btn = $("#diagTgSimAlert");
        if (simulateActive) {
            $banner.removeClass("d-none").html(
                "<strong>" + modules.diagnostics.escapeHtml(i18n("diagnostics.simulateTitle")) + "</strong> " +
                modules.diagnostics.escapeHtml(i18n("diagnostics.simulateHint"))
            );
            $btn.removeClass("d-none");
        } else {
            $banner.addClass("d-none").empty();
            $btn.addClass("d-none");
        }
    },

    /**
     * @param heavy полная проверка (в т.ч. тяжёлые шаги)
     * @param skipNotify true — не слать Telegram после прогона (автозагрузка вкладки)
     */
    fetchRun: function (heavy, skipNotify) {
        let $body = $("#diagnosticsTableBody");
        $body.html("<tr><td colspan='6'>…</td></tr>");
        loadingStart();
        let q = { heavy: heavy ? 1 : 0, _: Math.random() };
        if (skipNotify) {
            q.skip_notify = 1;
        }
        $.ajax({
            url: lStore("_server") + "/diagnostics/run?" + $.param(q),
            type: "GET",
            dataType: "json",
            beforeSend: function (xhr) {
                xhr.setRequestHeader("Authorization", "Bearer " + lStore("_token"));
                xhr.setRequestHeader("Accept-Language", lStore("_lang") || config.defaultLanguage || "ru");
                xhr.setRequestHeader("X-Api-Refresh", "1");
            },
        }).
            done(function (r) {
                if (!r || !r.diagnostics) {
                    $body.html("<tr><td colspan='6' class='text-danger'>" + i18n("diagnostics.loadErrorBadShape") + "</td></tr>");
                    return;
                }
                $("#diagnosticsSummaryBar").html(modules.diagnostics.renderSummary(r.diagnostics.summary));
                modules.diagnostics.renderTable($body, r.diagnostics.checks);
                modules.diagnostics.updateSimulateUi(!!r.diagnostics.simulateActive);
            }).
            fail(function (x) {
                let msg = modules.diagnostics.formatLoadError(x);
                $body.html("<tr><td colspan='6' class='text-danger'>" + modules.diagnostics.escapeHtml(msg) + "</td></tr>");
            }).
            always(loadingDone);
    },

    fetchHistoryChart: function () {
        modules.diagnostics.destroyChart();
        $.ajax({
            url: lStore("_server") + "/diagnostics/history?" + $.param({ _: Math.random() }),
            type: "GET",
            dataType: "json",
            beforeSend: function (xhr) {
                xhr.setRequestHeader("Authorization", "Bearer " + lStore("_token"));
                xhr.setRequestHeader("Accept-Language", lStore("_lang") || config.defaultLanguage || "ru");
                xhr.setRequestHeader("X-Api-Refresh", "1");
            },
        }).
            done(function (r) {
                let pts = (r && r.diagnosticsHistory && r.diagnosticsHistory.points) ? r.diagnosticsHistory.points : [];
                if (pts.length < 2 || typeof Chart === "undefined") {
                    $("#diagnosticsHistWrap").html("<p class='text-muted small'>" + i18n("diagnostics.historyTitle") + " — " + pts.length + " pts</p>");
                    return;
                }
                $("#diagnosticsHistWrap").html("<div style='height:200px'><canvas id='diagnosticsHistCanvas'></canvas></div>");
                let labels = [];
                let disk = [];
                let fails = [];
                for (let i = 0; i < pts.length; i++) {
                    let p = pts[i];
                    labels.push(p.t ? new Date(p.t * 1000).toLocaleString() : String(i));
                    disk.push(p.disk != null ? p.disk : null);
                    fails.push(p.fail != null ? p.fail : 0);
                }
                let ctx = document.getElementById("diagnosticsHistCanvas");
                modules.diagnostics._chHist = new Chart(ctx.getContext("2d"), {
                    type: "line",
                    data: {
                        labels: labels,
                        datasets: [
                            {
                                label: "Disk %",
                                data: disk,
                                borderColor: "rgba(0,123,255,0.9)",
                                fill: false,
                                yAxisID: "y",
                            },
                            {
                                label: "Fail",
                                data: fails,
                                borderColor: "rgba(220,53,69,0.9)",
                                fill: false,
                                yAxisID: "y1",
                            },
                        ],
                    },
                    options: {
                        legend: { display: true },
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            yAxes: [
                                { id: "y", position: "left", ticks: { beginAtZero: true } },
                                { id: "y1", position: "right", gridLines: { drawOnChartArea: false }, ticks: { beginAtZero: true } },
                            ],
                        },
                    },
                });
            }).
            fail(function () {
                $("#diagnosticsHistWrap").html("");
            });
    },

    route: function () {
        document.title = i18n("windowTitle") + " :: " + i18n("diagnostics.diagnostics");
        $("#altForm").hide();
        subTop();
        modules.diagnostics.destroyChart();
        $("#mainForm").html(
            "<div class='card card-primary card-outline'>" +
            "<div class='card-header'><h3 class='card-title'>" + i18n("diagnostics.subtitle") + "</h3></div>" +
            "<div class='card-body'>" +
            "<div id='diagnosticsSimulateBanner' class='alert alert-warning small py-2 d-none mb-3'></div>" +
            "<div class='mb-3' id='diagnosticsSummaryBar'></div>" +
            "<div class='btn-group mb-3 flex-wrap'>" +
            "<button type='button' class='btn btn-primary btn-sm' id='diagRunFull'>" + i18n("diagnostics.fullRun") + "</button>" +
            "<button type='button' class='btn btn-outline-secondary btn-sm' id='diagRunLight'>" + i18n("diagnostics.lightRun") + "</button>" +
            "<button type='button' class='btn btn-outline-warning btn-sm' id='diagClearCache'>" + i18n("diagnostics.clearCache") + "</button>" +
            "<button type='button' class='btn btn-outline-info btn-sm' id='diagBump'>" + i18n("diagnostics.bumpDiagCache") + "</button>" +
            "<button type='button' class='btn btn-outline-primary btn-sm' id='diagTgTest'>" + i18n("diagnostics.tgTest") + "</button>" +
            "<button type='button' class='btn btn-outline-dark btn-sm d-none' id='diagTgSimAlert' title='" +
            modules.diagnostics.escapeHtml(i18n("diagnostics.tgSimAlertTitle")) + "'>" + i18n("diagnostics.tgSimAlert") + "</button>" +
            "<button type='button' class='btn btn-outline-success btn-sm' id='diagTgWait'>" + i18n("diagnostics.tgWaitChat") + "</button>" +
            "</div>" +
            "<div id='diagnosticsHistWrap' class='mb-3'></div>" +
            "<div class='table-responsive'><table class='table table-sm table-striped'>" +
            "<thead><tr><th>" + i18n("diagnostics.groupLabel") + "</th><th>" + i18n("diagnostics.titleCol") + "</th><th>" + i18n("diagnostics.statusLabel") + "</th><th>" + i18n("diagnostics.valueCol") + "</th><th>" + i18n("diagnostics.latencyCol") + "</th><th>" + i18n("diagnostics.hintCol") + "</th></tr></thead>" +
            "<tbody id='diagnosticsTableBody'></tbody></table></div></div></div>"
        );
        $("#diagRunFull").on("click", function () {
            modules.diagnostics.fetchRun(true);
            setTimeout(modules.diagnostics.fetchHistoryChart, 500);
        });
        $("#diagRunLight").on("click", function () {
            modules.diagnostics.fetchRun(false);
        });
        $("#diagClearCache").on("click", function () {
            loadingStart();
            POST("diagnostics", "action", false, { action: "clearFrontCache" }).
                done(function () {
                    message(i18n("diagnostics.clearCache"));
                }).
                fail(FAIL).
                always(loadingDone);
        });
        $("#diagBump").on("click", function () {
            loadingStart();
            POST("diagnostics", "action", false, { action: "bumpDiagnosticsCache" }).
                done(function () {
                    message(i18n("diagnostics.bumpDiagCache"));
                }).
                fail(FAIL).
                always(loadingDone);
        });
        $("#diagTgTest").on("click", function () {
            loadingStart();
            POST("diagnostics", "action", false, { action: "testTelegram" }).
                done(function (r) {
                    let d = r && r.diagnosticsAction;
                    if (d && d.ok) {
                        message(i18n("diagnostics.tgTestOk"));
                    } else if (d && d.telegramError) {
                        message(i18n("diagnostics.tgTestFail") + ": " + d.telegramError);
                    } else {
                        message(i18n("diagnostics.tgTestFail"));
                    }
                }).
                fail(FAIL).
                always(loadingDone);
        });
        $("#diagTgSimAlert").on("click", function () {
            loadingStart();
            POST("diagnostics", "action", false, { action: "telegramSimulateAlert" }).
                done(function (r) {
                    let d = r && r.diagnosticsAction;
                    if (d && d.ok) {
                        message(i18n("diagnostics.tgSimAlertOk"));
                    } else if (d && d.telegramError === "simulate_disabled") {
                        message(i18n("diagnostics.tgSimAlertNeedSimulate"));
                    } else if (d && d.telegramError) {
                        message(i18n("diagnostics.tgSimAlertFail") + ": " + d.telegramError);
                    } else {
                        message(i18n("diagnostics.tgSimAlertFail"));
                    }
                }).
                fail(FAIL).
                always(loadingDone);
        });
        $("#diagTgWait").on("click", function () {
            modules.diagnostics.clearTgWait();
            modules.diagnostics._tgWaitWasActive = false;
            modules.diagnostics._tgConfigErrNotified = false;
            modules.diagnostics._tgWhDeletedNoted = false;
            modules.diagnostics._tgWhErrNoted = false;
            modules.diagnostics._tgWebhookBlockNoted = false;
            loadingStart();
            POST("diagnostics", "action", false, { action: "telegramArmWait" }).
                done(function (r) {
                    let d = r && r.diagnosticsAction;
                    message(i18n("diagnostics.tgWaitArmed"));
                    if (d && d.telegramWebhookDeferred) {
                        message(i18n("diagnostics.tgWaitWebhookDeferred"));
                    }
                    let ticks = 0;
                    let scheduleTgPoll = function () {
                        modules.diagnostics._tgWaitTimer = setTimeout(tgPollOnce, 2000);
                    };
                    let tgPollOnce = function () {
                        ticks++;
                        if (ticks > 90) {
                            modules.diagnostics.clearTgWait();
                            message(i18n("diagnostics.tgWaitTimeout"));
                            return;
                        }
                        $.ajax({
                            url: lStore("_server") + "/diagnostics/telegramWait?" + $.param({ _: Math.random() }),
                            type: "GET",
                            dataType: "json",
                            timeout: 130000,
                            beforeSend: function (xhr) {
                                xhr.setRequestHeader("Authorization", "Bearer " + lStore("_token"));
                                xhr.setRequestHeader("Accept-Language", lStore("_lang") || config.defaultLanguage || "ru");
                                xhr.setRequestHeader("X-Api-Refresh", "1");
                            },
                        }).
                            done(function (r) {
                                let w = r && r.telegramWait;
                                if (!w) {
                                    if (ticks <= 5) {
                                        message(
                                            i18n("diagnostics.tgWaitPollErr") + ": " +
                                                i18n("diagnostics.tgWaitBadResponse")
                                        );
                                    }
                                    scheduleTgPoll();
                                    return;
                                }
                                if (w.pollError === "webhook_not_cleared") {
                                    if (!modules.diagnostics._tgWebhookBlockNoted) {
                                        modules.diagnostics._tgWebhookBlockNoted = true;
                                        warning(
                                            i18n("diagnostics.tgWaitWebhookMustClear") +
                                                (w.pollDescription ? " " + w.pollDescription : ""),
                                            i18n("diagnostics.tgWaitWebhookWarnCaption"),
                                            30
                                        );
                                    }
                                } else if (w.pollError && ticks <= 5) {
                                    message(i18n("diagnostics.tgWaitPollErr") + ": " + (w.pollDescription || w.pollError));
                                }
                                if (w.pendingWebhookDeleted && !modules.diagnostics._tgWhDeletedNoted) {
                                    modules.diagnostics._tgWhDeletedNoted = true;
                                    message(i18n("diagnostics.tgWaitWebhookCleared"));
                                }
                                if (w.pendingWebhookError && ticks <= 20 && !modules.diagnostics._tgWhErrNoted) {
                                    modules.diagnostics._tgWhErrNoted = true;
                                    warning(
                                        i18n("diagnostics.tgWaitWebhookWarnDetail") + " " + w.pendingWebhookError,
                                        i18n("diagnostics.tgWaitWebhookWarnCaption"),
                                        25
                                    );
                                }
                                if (w.configWriteErrors && w.configWriteErrors.length && !modules.diagnostics._tgConfigErrNotified) {
                                    modules.diagnostics._tgConfigErrNotified = true;
                                    let parts = w.configWriteErrors.map(function (e) {
                                        return (e.chat_id || "?") + ": " + (e.error || "");
                                    }).join("; ");
                                    message(i18n("diagnostics.tgWaitConfigErr") + " " + parts);
                                }
                                if (w.active) {
                                    modules.diagnostics._tgWaitWasActive = true;
                                }
                                if (w.added && w.added.length) {
                                    modules.diagnostics.clearTgWait();
                                    let names = w.added.map(function (a) {
                                        return (a.username ? "@" + a.username : "?") + " → " + (a.chat_id || "");
                                    }).join("; ");
                                    message(i18n("diagnostics.tgWaitAdded") + " " + names);
                                    return;
                                }
                                if (!w.active) {
                                    modules.diagnostics.clearTgWait();
                                    if (modules.diagnostics._tgWaitWasActive) {
                                        message(i18n("diagnostics.tgWaitEnded"));
                                    }
                                    return;
                                }
                                scheduleTgPoll();
                            }).
                            fail(function (x) {
                                if (ticks <= 8) {
                                    let detail = modules.diagnostics.formatLoadError(x);
                                    if (x && x.status === 504) {
                                        detail = i18n("diagnostics.tgWaitNginx504");
                                    }
                                    message(i18n("diagnostics.tgWaitPollErr") + ": " + detail);
                                }
                                scheduleTgPoll();
                            });
                    };
                    scheduleTgPoll();
                }).
                fail(FAIL).
                always(loadingDone);
        });
        /* Без Tor в браузере: тормозило из‑за авто «полной» проверки + цепочки Telegram на сервере */
        modules.diagnostics.fetchRun(false, true);
        modules.diagnostics.fetchHistoryChart();
    },
}).init();
