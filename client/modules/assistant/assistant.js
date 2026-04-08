({
    menu: false,

    init: function () {
        if (AVAIL("assistant", "chat", "POST")) {
            this.menu = true;
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
        POST("assistant", "chat", false, { message: text }).
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

    route: function () {
        $("#altForm").hide();
        document.title = i18n("windowTitle") + " :: " + i18n("moduleAssistant");
        if (!AVAIL("assistant", "chat", "POST")) {
            page404();
            return;
        }
        $("#mainForm").html(
            "<div class='card card-outline card-primary'>" +
            "<div class='card-header'><h3 class='card-title mb-0'>" + escapeHTML(i18n("assistant.title")) + "</h3></div>" +
            "<div class='card-body'>" +
            "<p class='text-muted small'>" + escapeHTML(i18n("assistant.hint")) + "</p>" +
            "<div id='assistantThread' class='border rounded p-2 mb-2' style='min-height:220px;max-height:55vh;overflow:auto;background:#faf9f7'></div>" +
            "<div class='input-group'>" +
            "<textarea id='assistantInput' class='form-control' rows='2' placeholder='" + escapeHTML(i18n("assistant.placeholder")) + "'></textarea>" +
            "<div class='input-group-append'>" +
            "<button type='button' class='btn btn-primary' id='assistantSend'>" + escapeHTML(i18n("assistant.send")) + "</button>" +
            "</div></div></div></div>"
        );
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
