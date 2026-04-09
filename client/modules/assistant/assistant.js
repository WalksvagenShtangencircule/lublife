({
    menu: false,

    /** История диалога для контекста (user/assistant). В запрос уходит только хвост CONTEXT_LIMIT. */
    transcript: [],

    CONTEXT_LIMIT: 10,

    quickScenarios: function () {
        return [
            { key: "houseOverview", prompt: i18n("assistant.quick.houseOverviewPrompt") },
            { key: "subscriberTimeline", prompt: i18n("assistant.quick.subscriberTimelinePrompt") },
            { key: "keyUsage", prompt: i18n("assistant.quick.keyUsagePrompt") },
            { key: "mobileFunnel", prompt: i18n("assistant.quick.mobileFunnelPrompt") },
            { key: "crossHouse", prompt: i18n("assistant.quick.crossHousePrompt") },
            { key: "entranceLoad", prompt: i18n("assistant.quick.entranceLoadPrompt") },
            { key: "flatRisk", prompt: i18n("assistant.quick.flatRiskPrompt") },
            { key: "anomalies", prompt: i18n("assistant.quick.anomaliesPrompt") },
            { key: "apiRights", prompt: i18n("assistant.quick.apiRightsPrompt") },
            { key: "schemaAudit", prompt: i18n("assistant.quick.schemaAuditPrompt") },
        ];
    },

    askNumber: function (label, defValue, callback) {
        mPrompt(
            label,
            i18n("assistant.quick.wizardTitle"),
            defValue || "",
            v => {
                let x = parseInt($.trim(String(v || "")), 10);
                if (!x || x < 0) {
                    warning(i18n("assistant.quick.invalidNumber"));
                    return;
                }
                callback(x);
            }
        );
    },

    askText: function (label, defValue, callback) {
        mPrompt(
            label,
            i18n("assistant.quick.wizardTitle"),
            defValue || "",
            v => {
                let x = $.trim(String(v || ""));
                if (!x) {
                    warning(i18n("assistant.quick.invalidText"));
                    return;
                }
                callback(x);
            }
        );
    },

    askOptionalText: function (label, defValue, callback) {
        mPrompt(
            label,
            i18n("assistant.quick.wizardTitle"),
            defValue || "",
            v => callback($.trim(String(v || "")))
        );
    },

    sendPrompt: function (prompt) {
        $("#assistantInput").val(String(prompt || ""));
        modules.assistant.send();
    },

    runScenarioWizard: function (key) {
        let A = modules.assistant;
        if (key === "apiRights" || key === "schemaAudit") {
            A.sendPrompt(i18n("assistant.quick." + key + "Prompt"));
            return;
        }
        if (key === "crossHouse") {
            A.askText(i18n("assistant.quick.askPhone"), "", phone => {
                A.sendPrompt(i18n("assistant.quick.crossHousePrompt") + " Телефон: " + phone + ".");
            });
            return;
        }
        if (key === "subscriberTimeline") {
            A.askText(i18n("assistant.quick.askPhone"), "", phone => {
                A.askNumber(i18n("assistant.quick.askDays"), "30", days => {
                    A.sendPrompt(i18n("assistant.quick.subscriberTimelinePrompt") + " Телефон: " + phone + ". Период: последние " + days + " дней.");
                });
            });
            return;
        }

        A.askNumber(i18n("assistant.quick.askHouseId"), "", houseId => {
            if (key === "mobileFunnel") {
                A.sendPrompt(i18n("assistant.quick.mobileFunnelPrompt") + " house_id=" + houseId + ".");
                return;
            }
            if (key === "entranceLoad") {
                A.askNumber(i18n("assistant.quick.askDays"), "14", days => {
                    A.sendPrompt(i18n("assistant.quick.entranceLoadPrompt") + " house_id=" + houseId + ", период " + days + " дней.");
                });
                return;
            }
            if (key === "flatRisk" || key === "anomalies" || key === "houseOverview") {
                A.askNumber(i18n("assistant.quick.askDays"), key === "houseOverview" ? "7" : "14", days => {
                    A.sendPrompt(i18n("assistant.quick." + key + "Prompt") + " house_id=" + houseId + ", период " + days + " дней.");
                });
                return;
            }
            if (key === "keyUsage") {
                A.askOptionalText(i18n("assistant.quick.askRfidOptional"), "", rfid => {
                    A.askNumber(i18n("assistant.quick.askDays"), "14", days => {
                        let tail = " house_id=" + houseId + ", период " + days + " дней.";
                        if (rfid) {
                            tail += " RFID=" + rfid + ".";
                        }
                        A.sendPrompt(i18n("assistant.quick.keyUsagePrompt") + tail);
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
                "<i class='fas fa-link mr-1'></i>" + escapeHTML(i18n("assistant.quick." + x.key)) + "</a>";
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
            "<div class='card-header'><h3 class='card-title mb-0'>" + escapeHTML(i18n("assistant.quick.title")) + "</h3></div>" +
            "<div class='card-body small'>" +
            "<p class='text-muted'>" + escapeHTML(i18n("assistant.quick.hint")) + "</p>" +
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
