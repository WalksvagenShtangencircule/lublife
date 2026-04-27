({
    menu: false,

    /** История диалога для контекста (user/assistant). В запрос уходит только хвост CONTEXT_LIMIT. */
    transcript: [],

    CONTEXT_LIMIT: 6,

    t: function (key) {
        let a = lang && lang.assistant ? lang.assistant : null;
        if (a && a.quick && Object.prototype.hasOwnProperty.call(a.quick, key)) {
            return String(a.quick[key]);
        }
        return i18n("assistant.quick." + key);
    },

    /** Извлекает объекты из rendered HTML ответа ассистента и обновляет контекстную панель. */
    updateContextPanel: function (replyHtml) {
        let parser = new DOMParser();
        let doc = parser.parseFromString(replyHtml, "text/html");
        let anchors = doc.querySelectorAll("a[href]");

        let houses = [], flats = [], subscribers = [], domophones = [], cameras = [];
        let seen = {};

        for (let a of anchors) {
            let href = (a.getAttribute("href") || "").trim();
            if (!href.startsWith("?#addresses")) continue;
            let label = (a.textContent || "").trim();
            if (!label || seen[href]) continue;
            seen[href] = true;

            if (href.includes("subscriberId=")) {
                subscribers.push({ href, label });
            } else if (href.includes("flatId=")) {
                flats.push({ href, label });
            } else if (href.includes("domophones")) {
                domophones.push({ href, label });
            } else if (href.includes("cameras")) {
                cameras.push({ href, label });
            } else if (href.includes("houseId=") || href.includes("houses")) {
                houses.push({ href, label });
            }
        }

        let total = houses.length + flats.length + subscribers.length + domophones.length + cameras.length;

        function section(icon, title, items, maxItems) {
            if (!items.length) return "";
            let s = "<div class='mb-2'><div style='font-size:10px;font-weight:700;text-transform:uppercase;" +
                "letter-spacing:0.05em;color:#8898aa;margin-bottom:4px'>" + title + "</div>";
            for (let x of items.slice(0, maxItems || 6)) {
                s += "<div class='mb-1' style='overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:12px'>" +
                    "<i class='" + icon + " mr-1' style='color:#aab;width:12px'></i>" +
                    "<a href='" + escapeHTML(x.href) + "' style='color:#2c3e50'>" + escapeHTML(x.label) + "</a>" +
                    "</div>";
            }
            return s + "</div>";
        }

        let html = "";
        if (!total) {
            html = "<p class='text-muted' style='font-size:12px;margin-top:8px'>Ответ не содержит ссылок на объекты системы.</p>";
        } else {
            html += section("fas fa-building", "Дома", houses, 8);
            html += section("fas fa-door-open", "Квартиры", flats, 8);
            html += section("fas fa-user", "Абоненты", subscribers, 8);
            html += section("fas fa-phone-square", "Домофоны", domophones, 6);
            html += section("fas fa-video", "Камеры", cameras, 6);
        }

        let $panel = $("#assistantContextPanel");
        $panel.html(html);
        $panel.closest(".card").show();
    },

    /** Загружает и отображает быструю статистику системы в панели дашборда. */
    loadSystemStats: function () {
        let $panel = $("#assistantStatsPanel");
        $panel.html("<p class='text-muted mb-0' style='font-size:12px'>Загрузка...</p>");
        QUERY("assistant", "stats", {}, true).
            fail(() => {
                $panel.html("<p class='text-muted mb-0' style='font-size:12px'>Не удалось загрузить статистику.</p>");
            }).
            done(r => {
                let s = r && r.assistantStats && r.assistantStats.stats ? r.assistantStats.stats : null;
                if (!s) {
                    $panel.html("<p class='text-muted mb-0' style='font-size:12px'>Нет данных.</p>");
                    return;
                }
                function row(icon, label, val, cls) {
                    return "<div class='d-flex justify-content-between align-items-center mb-1' style='font-size:12px'>" +
                        "<span><i class='" + icon + " mr-1' style='color:#aab;width:13px'></i>" + label + "</span>" +
                        "<strong class='" + (cls || "") + "'>" + val + "</strong></div>";
                }
                let html =
                    row("fas fa-building",      "Домов",                 s.houses) +
                    row("fas fa-door-open",     "Квартир",               s.flats) +
                    row("fas fa-users",         "Абонентов",             s.subscribers) +
                    row("fas fa-phone-square",  "Домофонов активных",    s.domophones_active) +
                    row("fas fa-video",         "Камер",                 s.cameras) +
                    "<hr class='my-1'>" +
                    row("fas fa-mobile-alt",    "Активны за 7 дн.",      s.active_7d,  "text-success") +
                    row("fas fa-mobile-alt",    "Активны за 30 дн.",     s.active_30d, "text-info") +
                    row("fas fa-ban",           "Квартир заблокировано", s.flats_blocked, s.flats_blocked > 0 ? "text-warning" : "");
                $panel.html(html);
            });
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
        modules.assistant.hardenPromptInput();
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
        modules.assistant.hardenPromptInput();
    },

    askOptionalText: function (label, defValue, callback) {
        mPrompt(
            label,
            modules.assistant.t("wizardTitle"),
            defValue || "",
            v => callback($.trim(String(v || "")))
        );
        modules.assistant.hardenPromptInput();
    },

    hardenPromptInput: function () {
        setTimeout(() => {
            let $i = $("#promptModalInput");
            $i.attr("autocomplete", "new-password");
            $i.attr("autocapitalize", "off");
            $i.attr("autocorrect", "off");
            $i.attr("spellcheck", "false");
            $i.attr("data-lpignore", "true");
            $i.attr("data-form-type", "other");
            $i.attr("name", "assistantPromptInput_" + Date.now());
            // Анти-автозаполнение: временный readonly снимаем на фокусе.
            $i.prop("readonly", true);
            $i.off("focus.assistantAutofill").on("focus.assistantAutofill", function () {
                $(this).prop("readonly", false);
            });
            // На мобильных иногда first focus не приходит, снимаем через tick.
            setTimeout(() => $i.prop("readonly", false), 180);
        }, 20);
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
            A.t("askPeriodPreset") +
            "<div class='mt-2 mb-2'>" +
            "<button type='button' class='btn btn-sm btn-outline-primary mr-1 mb-1 assistant-period-btn' data-days='1'>1</button>" +
            "<button type='button' class='btn btn-sm btn-outline-primary mr-1 mb-1 assistant-period-btn' data-days='7'>7</button>" +
            "<button type='button' class='btn btn-sm btn-outline-primary mr-1 mb-1 assistant-period-btn' data-days='14'>14</button>" +
            "<button type='button' class='btn btn-sm btn-outline-primary mr-1 mb-1 assistant-period-btn' data-days='30'>30</button>" +
            "</div>" +
            escapeHTML(variants.join("\n")).replace(/\n/g, "<br>"),
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
        A.hardenPromptInput();
        setTimeout(() => {
            $(".assistant-period-btn").off("click").on("click", function () {
                let d = parseInt($(this).attr("data-days"), 10);
                if (!d) {
                    return;
                }
                $("#promptModal").modal("hide");
                callback(d);
            });
        }, 20);
    },

    resolveHouseSmart: function (callback) {
        let A = modules.assistant;
        A.askText(A.t("askHouseSearch"), "", search => {
            loadingStart();
            QUERY("houses", "search", { search: search }, true).
                fail(xhr => {
                    FAIL(xhr);
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
                fail(xhr => {
                    FAIL(xhr);
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
                fail(xhr => {
                    FAIL(xhr);
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

    /**
     * Заголовок левой карточки — строки зашиты в код, без i18n/json: иначе при неизменном ?ver=
     * в кэше остаётся старый assistant.js с i18n("assistant.title") → «Ассистент (DeepSeek)».
     */
    cardPageTitle: function () {
        let l = typeof lStore === "function" ? lStore("_lang") : "";
        if (!l && typeof config === "object" && config) {
            l = config.defaultLanguage || "";
        }
        return (l === "en") ? "AI assistant" : "ИИ ассистент";
    },

    /** Подсказка под заголовком — тоже без JSON, только язык интерфейса. */
    cardPageHint: function () {
        let l = typeof lStore === "function" ? lStore("_lang") : "";
        if (!l && typeof config === "object" && config) {
            l = config.defaultLanguage || "";
        }
        return (l === "en") ? "Work uses only data inside the system." : "Работа ведётся только с данными внутри системы.";
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
                let renderedReply = modules.assistant.renderMarkdown(reply || "—");
                modules.assistant.appendBubble("assistant", reply || "—");
                modules.assistant.updateContextPanel(renderedReply);
            }).
            always(loadingDone);
    },

    renderMarkdown: function (text) {
        if (window.remarkable && window.remarkable.Remarkable) {
            try {
                let md = new window.remarkable.Remarkable({ html: false, breaks: true });
                return md.render(text);
            } catch (e) {}
        }
        return escapeHTML(text).replace(/\n/g, "<br>");
    },

    appendBubble: function (role, text) {
        let $box = $("#assistantThread");
        let isUser = role === "user";
        let bubbleStyle, wrapStyle;

        if (isUser) {
            bubbleStyle = "background:#2c3e50;color:#ffffff;border-radius:12px 12px 4px 12px;";
            wrapStyle = "display:flex;justify-content:flex-end;margin-bottom:10px;";
        } else {
            bubbleStyle = "background:#ffffff;color:#2c3e50;border:1px solid #dde3ea;border-radius:12px 12px 12px 4px;";
            wrapStyle = "display:flex;justify-content:flex-start;margin-bottom:10px;";
        }

        let contentHtml;
        if (isUser) {
            contentHtml = "<div style='white-space:pre-wrap'>" + escapeHTML(text) + "</div>";
        } else {
            contentHtml = "<div class='assistant-md-body'>" + modules.assistant.renderMarkdown(text) + "</div>";
        }

        let downloadBtn = "";
        if (!isUser && text.length > 800) {
            let escaped = escapeHTML(text).replace(/'/g, "&#39;");
            downloadBtn = "<div style='margin-top:8px;padding-top:6px;border-top:1px solid #eaecef;text-align:right'>" +
                "<a href='#' class='assistant-dl-btn' style='font-size:11px;color:#6c757d;text-decoration:none;' " +
                "data-text='" + escaped + "'>" +
                "<i class='fas fa-download mr-1'></i>Скачать TXT</a></div>";
        }

        $box.append(
            "<div style='" + wrapStyle + "'>" +
            "<div style='max-width:90%;padding:10px 14px;font-size:14px;line-height:1.5;" + bubbleStyle + "'>" +
            contentHtml + downloadBtn +
            "</div></div>"
        );
        $box.scrollTop($box[0].scrollHeight);
    },

    renderQuickLinks: function () {
        let items = modules.assistant.quickScenarios();
        modules.assistant._quickCache = items;
        let html = "";
        for (let i = 0; i < items.length; i++) {
            let x = items[i];
            let tip = modules.assistant.t(x.key + "Desc");
            if (!tip || tip === ("assistant.quick." + x.key + "Desc")) {
                tip = x.prompt;
            }
            html += "<a href='#' class='d-block mb-2 assistant-quick-link' data-idx='" + i + "'>" +
                "<i class='fas fa-link mr-1'></i>" +
                "<span title='" + escapeHTML(tip) + "'>" + escapeHTML(modules.assistant.t(x.key)) + "</span>" +
                "<i class='fas fa-circle-question text-muted ml-1' title='" + escapeHTML(tip) + "'></i>" +
                "</a>";
        }
        $("#assistantQuickLinks").html(html);
        $("#assistantQuickHintLive").text(modules.assistant.t("hint"));
        $(".assistant-quick-link").off("click").on("click", function (e) {
            e.preventDefault();
            let idx = parseInt($(this).attr("data-idx"), 10);
            let key = "";
            if (!isNaN(idx) && modules.assistant._quickCache && modules.assistant._quickCache[idx]) {
                key = String(modules.assistant._quickCache[idx].key || "");
            }
            if (!key) return;
            modules.assistant.runScenarioWizard(key);
        }).on("mouseenter", function () {
            let idx = parseInt($(this).attr("data-idx"), 10);
            if (isNaN(idx) || !modules.assistant._quickCache || !modules.assistant._quickCache[idx]) {
                return;
            }
            let key = String(modules.assistant._quickCache[idx].key || "");
            if (!key) {
                return;
            }
            let tip = modules.assistant.t(key + "Desc");
            if (!tip || tip === ("assistant.quick." + key + "Desc")) {
                tip = modules.assistant._quickCache[idx].prompt || "";
            }
            $("#assistantQuickHintLive").text(tip);
        }).on("mouseleave", function () {
            $("#assistantQuickHintLive").text(modules.assistant.t("hint"));
        });
    },

    route: function () {
        $("#altForm").hide();
        document.title = i18n("windowTitle") + " :: " + i18n("moduleAssistant");
        if (!AVAIL("assistant", "chat", "POST")) {
            page404();
            return;
        }
        if (!document.getElementById("assistantMdStyles")) {
            let s = document.createElement("style");
            s.id = "assistantMdStyles";
            s.textContent = [
                ".assistant-md-body h1,.assistant-md-body h2,.assistant-md-body h3{margin:8px 0 4px;font-size:1em;font-weight:700;color:#1a2a3a}",
                ".assistant-md-body p{margin:0 0 6px}",
                ".assistant-md-body ul,.assistant-md-body ol{margin:0 0 6px;padding-left:18px}",
                ".assistant-md-body li{margin-bottom:2px}",
                ".assistant-md-body table{border-collapse:collapse;width:100%;margin:6px 0;font-size:13px}",
                ".assistant-md-body th{background:#f0f4f8;color:#2c3e50;padding:5px 8px;border:1px solid #dde3ea;text-align:left}",
                ".assistant-md-body td{padding:4px 8px;border:1px solid #eaecef;vertical-align:top}",
                ".assistant-md-body tr:nth-child(even) td{background:#f8fafc}",
                ".assistant-md-body code{background:#f0f4f8;padding:1px 4px;border-radius:3px;font-size:12px;font-family:monospace}",
                ".assistant-md-body pre{background:#f0f4f8;padding:8px;border-radius:4px;overflow-x:auto;font-size:12px}",
                ".assistant-md-body strong{font-weight:700}",
                ".assistant-md-body hr{border:none;border-top:1px solid #eaecef;margin:8px 0}",
            ].join("");
            document.head.appendChild(s);
        }

        $("#mainForm").html(
            "<div class='row'>" +
            "<div class='col-lg-8 mb-3'>" +
            "<div class='card card-outline card-primary h-100'>" +
            "<div class='card-header'><h3 class='card-title mb-0'>" + escapeHTML(modules.assistant.cardPageTitle()) + "</h3></div>" +
            "<div class='card-body'>" +
            "<p class='text-muted small'>" + escapeHTML(modules.assistant.cardPageHint()) + "</p>" +
            "<div id='assistantThread' class='rounded p-2 mb-2' style='min-height:220px;max-height:55vh;overflow:auto;background:#f4f6f9;border:1px solid #dde3ea'></div>" +
            "<div class='input-group'>" +
            "<textarea id='assistantInput' class='form-control' rows='2' placeholder='" + escapeHTML(i18n("assistant.placeholder")) + "'></textarea>" +
            "<div class='input-group-append'>" +
            "<button type='button' class='btn btn-primary' id='assistantSend'>" + escapeHTML(i18n("assistant.send")) + "</button>" +
            "</div></div></div></div></div>" +

            "<div class='col-lg-4 mb-3'>" +

            "<div class='card card-outline card-secondary mb-3'>" +
            "<div class='card-header py-2 d-flex justify-content-between align-items-center'>" +
            "<h3 class='card-title mb-0' style='font-size:13px'><i class='fas fa-chart-bar mr-1'></i>Система</h3>" +
            "<a href='#' id='assistantStatsRefresh' style='font-size:11px;color:#aab' title='Обновить'><i class='fas fa-sync-alt'></i></a>" +
            "</div>" +
            "<div class='card-body py-2'><div id='assistantStatsPanel'></div></div></div>" +

            "<div class='card card-outline card-secondary'>" +
            "<div class='card-header py-2'><h3 class='card-title mb-0' style='font-size:13px'>" +
            "<i class='fas fa-map-marker-alt mr-1'></i>Объекты из диалога</h3></div>" +
            "<div class='card-body py-2'>" +
            "<p class='text-muted mb-0' style='font-size:12px'>Здесь появятся ссылки на дома, квартиры и абонентов после ответа ассистента.</p>" +
            "<div id='assistantContextPanel' class='mt-2'></div>" +
            "</div></div>" +

            "</div></div>"
        );
        modules.assistant.transcript = [];
        modules.assistant.loadSystemStats();
        $("#assistantStatsRefresh").off("click").on("click", e => {
            e.preventDefault();
            modules.assistant.loadSystemStats();
        });

        let $ai = $("#assistantInput");
        $ai
            .attr("autocomplete", "off")
            .attr("autocapitalize", "off")
            .attr("autocorrect", "off")
            .attr("spellcheck", "false")
            .attr("data-lpignore", "true")
            .attr("data-form-type", "other")
            .attr("name", "assistantChatInput_" + Date.now())
            .prop("readonly", true);
        $ai.off("focus.assistantAutofill").on("focus.assistantAutofill", function () {
            $(this).prop("readonly", false);
        });
        setTimeout(() => $ai.prop("readonly", false), 180);
        $("#assistantSend").off("click").on("click", () => modules.assistant.send());
        $("#assistantInput").off("keydown").on("keydown", e => {
            if (e.key === "Enter" && !e.shiftKey) {
                e.preventDefault();
                modules.assistant.send();
            }
        });
        $("#mainForm").off("click.assistantDl").on("click.assistantDl", ".assistant-dl-btn", function (e) {
            e.preventDefault();
            let text = $(this).attr("data-text") || "";
            text = text.replace(/&#39;/g, "'");
            let blob = new Blob([text], { type: "text/plain;charset=utf-8" });
            let url = URL.createObjectURL(blob);
            let a = document.createElement("a");
            a.href = url;
            a.download = "assistant-" + new Date().toISOString().slice(0, 19).replace(/[T:]/g, "-") + ".txt";
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        });
        loadingDone();
    },
}).init();
