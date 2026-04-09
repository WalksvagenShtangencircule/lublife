({
    menu: false,

    /** История диалога для контекста (user/assistant). В запрос уходит только хвост CONTEXT_LIMIT. */
    transcript: [],

    CONTEXT_LIMIT: 10,

    t: function (key) {
        let a = lang && lang.assistant ? lang.assistant : null;
        if (a && a.quick && Object.prototype.hasOwnProperty.call(a.quick, key)) {
            return String(a.quick[key]);
        }
        return i18n("assistant.quick." + key);
    },

    quickScenarios: function () {
        let t = modules.assistant.t;
        return [
            { key: "houseOverview", prompt: t("houseOverviewPrompt") },
            { key: "subscriberTimeline", prompt: t("subscriberTimelinePrompt") },
            { key: "keyUsage", prompt: t("keyUsagePrompt") },
            { key: "mobileFunnel", prompt: t("mobileFunnelPrompt") },
            { key: "crossHouse", prompt: t("crossHousePrompt") },
            { key: "entranceLoad", prompt: t("entranceLoadPrompt") },
            { key: "flatRisk", prompt: t("flatRiskPrompt") },
            { key: "anomalies", prompt: t("anomaliesPrompt") },
            { key: "apiRights", prompt: t("apiRightsPrompt") },
            { key: "schemaAudit", prompt: t("schemaAuditPrompt") },
        ];
    },

    askNumber: function (label, defValue, callback) {
        mPrompt(
            label,
            modules.assistant.t("wizardTitle"),
            defValue || "",
            v => {
                let x = parseInt($.trim(String(v || "")), 10);
                if (!x || x < 0) {
                    warning(modules.assistant.t("invalidNumber"));
                    return;
                }
                callback(x);
            }
        );
    },

    askText: function (label, defValue, callback) {
        mPrompt(
            label,
            modules.assistant.t("wizardTitle"),
            defValue || "",
            v => {
                let x = $.trim(String(v || ""));
                if (!x) {
                    warning(modules.assistant.t("invalidText"));
                    return;
                }
                callback(x);
            }
        );
    },

    askOptionalText: function (label, defValue, callback) {
        mPrompt(
            label,
            modules.assistant.t("wizardTitle"),
            defValue || "",
            v => callback($.trim(String(v || "")))
        );
    },

    askPeriodDays: function (defDays, callback) {
        let A = modules.assistant;
        let variants = [
            "1) " + A.t("periodYesterday"),
            "2) " + A.t("period7"),
            "3) " + A.t("period14"),
            "4) " + A.t("period30"),
            "5) " + A.t("periodCustom"),
        ];
        mPrompt(
            A.t("askPeriodPreset") + "<br><br>" + escapeHTML(variants.join("\n")).replace(/\n/g, "<br>"),
            A.t("wizardTitle"),
            "2",
            v => {
                let x = parseInt($.trim(String(v || "")), 10);
                if (x === 1) return callback(1);
                if (x === 2) return callback(7);
                if (x === 3) return callback(14);
                if (x === 4) return callback(30);
                if (x === 5) return A.askNumber(A.t("askDays"), String(defDays || 14), callback);
                warning(A.t("invalidNumber"));
            }
        );
    },

    resolveHouseSmart: function (callback) {
        let A = modules.assistant;
        A.askText(A.t("askHouseSearch"), "", search => {
            loadingStart();
            QUERY("houses", "search", { search: search }, true).
                fail(() => {
                    FAIL();
                    A.askNumber(A.t("askHouseIdFallback"), "", callback);
                }).
                done(r => {
                    let rows = (r && r.houses && Array.isArray(r.houses)) ? r.houses : [];
                    if (!rows.length) {
                        warning(A.t("houseNotFound"));
                        A.askNumber(A.t("askHouseIdFallback"), "", callback);
                        return;
                    }
                    if (rows.length === 1) {
                        let hid = parseInt(rows[0].houseId, 10);
                        if (hid > 0) {
                            message(sprintf(A.t("houseResolved"), rows[0].houseFull || ("#" + hid)));
                            callback(hid);
                            return;
                        }
                    }

                    let top = rows.slice(0, 10);
                    let variants = [];
                    for (let i = 0; i < top.length; i++) {
                        let h = top[i];
                        variants.push((i + 1) + ") " + (h.houseFull || ("#" + h.houseId)));
                    }
                    mPrompt(
                        A.t("pickHouseFromList") + "<br><br>" + escapeHTML(variants.join("\n")).replace(/\n/g, "<br>"),
                        A.t("wizardTitle"),
                        "1",
                        v => {
                            let idx = parseInt($.trim(String(v || "")), 10);
                            if (!idx || idx < 1 || idx > top.length) {
                                warning(A.t("invalidNumber"));
                                return;
                            }
                            let hid = parseInt(top[idx - 1].houseId, 10);
                            if (!hid) {
                                warning(A.t("invalidNumber"));
                                return;
                            }
                            callback(hid);
                        }
                    );
                }).
                always(loadingDone);
        });
    },

    resolveSubscriberSmart: function (callback) {
        let A = modules.assistant;
        A.askText(A.t("askSubscriberSearch"), "", search => {
            loadingStart();
            QUERY("subscribers", "search", { search: search }, true).
                fail(() => {
                    FAIL();
                    A.askText(A.t("askSubscriberIdFallback"), "", sid => {
                        callback(sid);
                    });
                }).
                done(r => {
                    let rows = (r && r.subscribers && Array.isArray(r.subscribers)) ? r.subscribers : [];
                    if (!rows.length) {
                        warning(A.t("subscriberNotFound"));
                        A.askText(A.t("askSubscriberIdFallback"), "", sid => callback(sid));
                        return;
                    }
                    if (rows.length === 1 && rows[0].subscriberId) {
                        callback(String(rows[0].subscriberId));
                        return;
                    }
                    let top = rows.slice(0, 10);
                    let variants = [];
                    for (let i = 0; i < top.length; i++) {
                        let s = top[i];
                        let label = "#" + s.subscriberId + " " + (s.subscriberFull || "") + (s.mobile ? (" (" + s.mobile + ")") : "");
                        variants.push((i + 1) + ") " + label);
                    }
                    mPrompt(
                        A.t("pickSubscriberFromList") + "<br><br>" + escapeHTML(variants.join("\n")).replace(/\n/g, "<br>"),
                        A.t("wizardTitle"),
                        "1",
                        v => {
                            let idx = parseInt($.trim(String(v || "")), 10);
                            if (!idx || idx < 1 || idx > top.length) {
                                warning(A.t("invalidNumber"));
                                return;
                            }
                            callback(String(top[idx - 1].subscriberId));
                        }
                    );
                }).
                always(loadingDone);
        });
    },

    resolveRfidSmart: function (callback) {
        let A = modules.assistant;
        A.askOptionalText(A.t("askRfidOptional"), "", search => {
            if (!search) {
                callback("");
                return;
            }
            loadingStart();
            QUERY("subscribers", "searchRf", { search: search }, true).
                fail(() => {
                    FAIL();
                    A.askOptionalText(A.t("askRfidOptional"), search, callback);
                }).
                done(r => {
                    let rows = (r && r.rfs && Array.isArray(r.rfs)) ? r.rfs : [];
                    if (!rows.length) {
                        warning(A.t("rfidNotFound"));
                        callback(search);
                        return;
                    }
                    if (rows.length === 1 && rows[0].rfId) {
                        callback(String(rows[0].rfId));
                        return;
                    }
                    let top = rows.slice(0, 10);
                    let variants = [];
                    for (let i = 0; i < top.length; i++) {
                        let x = top[i];
                        variants.push((i + 1) + ") " + (x.rfId || ("#" + (x.keyId || i + 1))));
                    }
                    mPrompt(
                        A.t("pickRfidFromList") + "<br><br>" + escapeHTML(variants.join("\n")).replace(/\n/g, "<br>"),
                        A.t("wizardTitle"),
                        "1",
                        v => {
                            let idx = parseInt($.trim(String(v || "")), 10);
                            if (!idx || idx < 1 || idx > top.length) {
                                warning(A.t("invalidNumber"));
                                return;
                            }
                            callback(String(top[idx - 1].rfId || ""));
                        }
                    );
                }).
                always(loadingDone);
        });
    },

    sendPrompt: function (prompt) {
        $("#assistantInput").val(String(prompt || ""));
        modules.assistant.send();
    },

    runScenarioWizard: function (key) {
        let A = modules.assistant;
        if (key === "apiRights" || key === "schemaAudit") {
            A.sendPrompt(A.t(key + "Prompt"));
            return;
        }
        if (key === "crossHouse") {
            A.askText(A.t("askPhone"), "", phone => {
                A.sendPrompt(A.t("crossHousePrompt") + " Телефон: " + phone + ".");
            });
            return;
        }
        if (key === "subscriberTimeline") {
            A.resolveSubscriberSmart(subscriberId => {
                A.askPeriodDays(30, days => {
                    A.sendPrompt(A.t("subscriberTimelinePrompt") + " house_subscriber_id=" + subscriberId + ". Период: последние " + days + " дней.");
                });
            });
            return;
        }

        A.resolveHouseSmart(houseId => {
            if (key === "mobileFunnel") {
                A.sendPrompt(A.t("mobileFunnelPrompt") + " house_id=" + houseId + ".");
                return;
            }
            if (key === "entranceLoad") {
                A.askPeriodDays(14, days => {
                    A.sendPrompt(A.t("entranceLoadPrompt") + " house_id=" + houseId + ", период " + days + " дней.");
                });
                return;
            }
            if (key === "flatRisk" || key === "anomalies" || key === "houseOverview") {
                A.askPeriodDays(key === "houseOverview" ? 7 : 14, days => {
                    A.sendPrompt(A.t(key + "Prompt") + " house_id=" + houseId + ", период " + days + " дней.");
                });
                return;
            }
            if (key === "keyUsage") {
                A.resolveRfidSmart(rfid => {
                    A.askPeriodDays(14, days => {
                        let tail = " house_id=" + houseId + ", период " + days + " дней.";
                        if (rfid) {
                            tail += " RFID=" + rfid + ".";
                        }
                        A.sendPrompt(A.t("keyUsagePrompt") + tail);
                    });
                });
                return;
            }
        });
    },

    init: function () {
        if (AVAIL("assistant", "chat", "POST")) {
            leftSide("fas fa-fw fa-robot", i18n("moduleAssistant"), "?#assistant", "households");
        }
        moduleLoaded("assistant", this);
    },

    send: function () {
        let text = $.trim($("#assistantInput").val() || "");
        if (!text) {
            return;
        }
        $("#assistantInput").val("");
        modules.assistant.appendBubble("user", text);
        loadingStart();

        let pendingUser = { role: "user", content: text };
        let messagesPayload = modules.assistant.transcript
            .concat([pendingUser])
            .slice(-modules.assistant.CONTEXT_LIMIT);

        POST("assistant", "chat", false, { messages: messagesPayload }).
            fail(FAIL).
            done(r => {
                let p = r && r.assistantChat ? r.assistantChat : r;
                if (p && p.error === "deepseek_unreachable") {
                    error(i18n("assistant.deepseekUnreachable"), i18n("error"));
                    return;
                }
                let reply = p && p.reply != null ? String(p.reply) : "";
                if (!reply && p && p.error) {
                    warning(String(p.error));
                    return;
                }
                modules.assistant.transcript.push(pendingUser);
                modules.assistant.transcript.push({ role: "assistant", content: reply || "—" });
                while (modules.assistant.transcript.length > 100) {
                    modules.assistant.transcript.shift();
                }
                modules.assistant.appendBubble("assistant", reply || "—");
            }).
            always(loadingDone);
    },

    appendBubble: function (role, text) {
        let cls = role === "user" ? "bg-light border" : "bg-primary text-white";
        let $box = $("#assistantThread");
        let safe = escapeHTML(text).replace(/\n/g, "<br>");
        $box.append(
            "<div class='mb-2 p-2 rounded " + cls + "' style='max-width:95%;" + (role === "user" ? " margin-left:auto;" : "") + "'>" +
            safe + "</div>"
        );
        $box.scrollTop($box[0].scrollHeight);
    },

    renderQuickLinks: function () {
        let items = modules.assistant.quickScenarios();
        modules.assistant._quickCache = items;
        let html = "";
        for (let i = 0; i < items.length; i++) {
            let x = items[i];
            html += "<a href='#' class='d-block mb-2 assistant-quick-link' data-idx='" + i + "'>" +
                "<i class='fas fa-link mr-1'></i>" + escapeHTML(modules.assistant.t(x.key)) + "</a>";
        }
        $("#assistantQuickLinks").html(html);
        $(".assistant-quick-link").off("click").on("click", function (e) {
            e.preventDefault();
            let idx = parseInt($(this).attr("data-idx"), 10);
            let key = "";
            if (!isNaN(idx) && modules.assistant._quickCache && modules.assistant._quickCache[idx]) {
                key = String(modules.assistant._quickCache[idx].key || "");
            }
            if (!key) return;
            modules.assistant.runScenarioWizard(key);
        });
    },

    route: function () {
        $("#altForm").hide();
        document.title = i18n("windowTitle") + " :: " + i18n("moduleAssistant");
        if (!AVAIL("assistant", "chat", "POST")) {
            page404();
            return;
        }
        $("#mainForm").html(
            "<div class='row'>" +
            "<div class='col-lg-8 mb-3'>" +
            "<div class='card card-outline card-primary h-100'>" +
            "<div class='card-header'><h3 class='card-title mb-0'>" + escapeHTML(i18n("assistant.title")) + "</h3></div>" +
            "<div class='card-body'>" +
            "<p class='text-muted small'>" + escapeHTML(i18n("assistant.hint")) + "</p>" +
            "<div id='assistantThread' class='border rounded p-2 mb-2' style='min-height:220px;max-height:55vh;overflow:auto;background:#faf9f7'></div>" +
            "<div class='input-group'>" +
            "<textarea id='assistantInput' class='form-control' rows='2' placeholder='" + escapeHTML(i18n("assistant.placeholder")) + "'></textarea>" +
            "<div class='input-group-append'>" +
            "<button type='button' class='btn btn-primary' id='assistantSend'>" + escapeHTML(i18n("assistant.send")) + "</button>" +
            "</div></div></div></div></div>" +
            "<div class='col-lg-4 mb-3'>" +
            "<div class='card card-outline card-secondary h-100'>" +
            "<div class='card-header'><h3 class='card-title mb-0'>" + escapeHTML(modules.assistant.t("title")) + "</h3></div>" +
            "<div class='card-body small'>" +
            "<p class='text-muted'>" + escapeHTML(modules.assistant.t("hint")) + "</p>" +
            "<div id='assistantQuickLinks'></div>" +
            "</div></div></div></div>"
        );
        modules.assistant.transcript = [];
        modules.assistant.renderQuickLinks();
        $("#assistantSend").off("click").on("click", () => modules.assistant.send());
        $("#assistantInput").off("keydown").on("keydown", e => {
            if (e.key === "Enter" && !e.shiftKey) {
                e.preventDefault();
                modules.assistant.send();
            }
        });
        loadingDone();
    },
}).init();
