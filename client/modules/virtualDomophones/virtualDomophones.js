({
    meta: {
        domophones: [],
        servers: {},
    },

    init: function () {
        if (AVAIL("houses", "virtualDomophones", "GET")) {
            leftSide("fas fa-fw fa-qrcode", i18n("virtualDomophones.menuTitle"), "?#virtualDomophones", "households");
        }
        moduleLoaded("virtualDomophones", this);
    },

    unwrap: function (r, key) {
        if (r && r[key] !== undefined) {
            return r[key];
        }
        if (r && r["200"] && r["200"][key] !== undefined) {
            return r["200"][key];
        }
        return null;
    },

    guestPageUrl: function (slug) {
        if (!slug) {
            return "";
        }
        const api = String(lStore("_server") || "").replace(/\/$/, "");
        try {
            const u = new URL(api);
            return u.origin + "/virtual-intercom/index.html?t=" + encodeURIComponent(slug);
        } catch (e) {
            const front = api.replace(/\/api$/i, "/frontend");
            return front + "/virtual-intercom/index.html?t=" + encodeURIComponent(slug);
        }
    },

    extSlug: function (ext) {
        if (!ext) {
            return "";
        }
        if (typeof ext === "object" && ext.guestAccessSlug) {
            return String(ext.guestAccessSlug);
        }
        return "";
    },

    loadMeta: function (done) {
        loadingStart();
        QUERY("houses", "virtualDomophones", {}, true).
            fail(x => {
                if (x && x.responseJSON && (x.responseJSON.error === "accessDenied" || x.responseJSON.error === "permissionDenied")) {
                    subTop();
                    pageError(i18n("errors.accessDenied"));
                    return;
                }
                FAIL(x);
            }).
            done(r => {
                const pack = modules.virtualDomophones.unwrap(r, "virtualDomophones");
                if (!pack) {
                    modules.virtualDomophones.meta.domophones = [];
                    modules.virtualDomophones.meta.servers = {};
                } else {
                    modules.virtualDomophones.meta.domophones = pack.domophones || [];
                    modules.virtualDomophones.meta.servers = pack.servers || {};
                }
                if (typeof done === "function") {
                    done();
                }
            }).
            always(loadingDone);
    },

    serverOptions: function () {
        let o = [];
        const s = modules.virtualDomophones.meta.servers;
        for (let k in s) {
            if (!Object.prototype.hasOwnProperty.call(s, k)) {
                continue;
            }
            o.push({
                id: s[k].ip,
                text: s[k].title,
            });
        }
        return o;
    },

    showQrModal: function (url) {
        const mid = "vdomQr" + md5(url + String(Math.random()));
        const $m = $(`<div class="modal fade" id="${mid}" tabindex="-1" role="dialog" aria-hidden="true">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">${escapeHTML(i18n("virtualDomophones.guestUrl"))}</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                    </div>
                    <div class="modal-body text-center">
                        <div id="${mid}-qr" class="d-inline-block p-2 bg-white rounded"></div>
                        <p class="mt-3 small text-break"><a href="${escapeHTML(url)}" target="_blank" rel="noopener">${escapeHTML(url)}</a></p>
                    </div>
                </div>
            </div>
        </div>`);
        $("body").append($m);
        autoZ($m);
        $m.modal("show");
        const el = document.getElementById(mid + "-qr");
        if (el && typeof QRCode !== "undefined") {
            const opts = {
                width: 220,
                height: 220,
            };
            if (typeof QRCode !== "undefined" && QRCode.CorrectLevel) {
                opts.correctLevel = QRCode.CorrectLevel.M;
            }
            const qr = new QRCode(el, opts);
            qr.makeCode(url);
        }
        $m.on("hidden.bs.modal", function () {
            $m.remove();
        });
    },

    rotateSlug: function (domophoneId) {
        mConfirm(i18n("virtualDomophones.rotateSlugConfirm"), i18n("confirm"), `warning:${i18n("virtualDomophones.rotateSlug")}`, () => {
            loadingStart();
            PUT("houses", "virtualDomophoneRotateToken", domophoneId, {}).
                fail(FAIL).
                done(r => {
                    const rot = modules.virtualDomophones.unwrap(r, "rotation");
                    if (rot && rot.guestAccessSlug) {
                        message(i18n("virtualDomophones.slugRotated"));
                    }
                    modules.virtualDomophones.route({});
                }).
                always(loadingDone);
        });
    },

    doDelete: function (domophoneId) {
        loadingStart();
        DELETE("houses", "virtualDomophone", domophoneId).
            fail(FAIL).
            done(() => {
                message(i18n("virtualDomophones.panelDeleted"));
                modules.virtualDomophones.route({});
            }).
            always(loadingDone);
    },

    deletePanel: function (domophoneId) {
        mConfirm(i18n("virtualDomophones.confirmDelete", domophoneId), i18n("confirm"), `danger:${i18n("virtualDomophones.deletePanel")}`, () => {
            modules.virtualDomophones.doDelete(domophoneId);
        });
    },

    addPanel: function () {
        const servers = modules.virtualDomophones.serverOptions();
        if (!servers.length) {
            error(i18n("errors.unknown"));
            return;
        }
        cardForm({
            title: i18n("virtualDomophones.addPanel"),
            footer: true,
            borderless: true,
            topApply: true,
            apply: i18n("add"),
            size: "lg",
            fields: [
                {
                    id: "name",
                    type: "text",
                    title: i18n("virtualDomophones.name"),
                    validate: v => $.trim(v).length > 0 && $.trim(v).length <= 64,
                    tab: i18n("addresses.primary"),
                },
                {
                    id: "server",
                    type: "select2",
                    title: i18n("virtualDomophones.server"),
                    options: servers,
                    validate: v => $.trim(v) !== "",
                    tab: i18n("addresses.primary"),
                },
                {
                    id: "doorUrl0",
                    type: "text",
                    title: i18n("virtualDomophones.doorUrl0"),
                    placeholder: "https://",
                    tab: i18n("addresses.primary"),
                },
                {
                    id: "doorUrl1",
                    type: "text",
                    title: i18n("virtualDomophones.doorUrl1"),
                    placeholder: "https://",
                    tab: i18n("addresses.primary"),
                },
                {
                    id: "comments",
                    type: "text",
                    title: i18n("virtualDomophones.comments"),
                    validate: v => $.trim(v).length <= 64,
                    tab: i18n("addresses.secondary"),
                },
            ],
            callback: data => {
                loadingStart();
                POST("houses", "virtualDomophone", false, {
                    name: $.trim(data.name),
                    server: data.server,
                    doorUrl0: $.trim(data.doorUrl0),
                    doorUrl1: $.trim(data.doorUrl1),
                    comments: $.trim(data.comments),
                }).
                    fail(FAIL).
                    done(res => {
                        const id = modules.virtualDomophones.unwrap(res, "domophoneId");
                        if (id !== null && id !== undefined && id !== false) {
                            message(i18n("virtualDomophones.panelAdded"));
                        }
                        modules.virtualDomophones.route({});
                    }).
                    always(loadingDone);
            },
        });
    },

    editPanel: function (domophoneId) {
        loadingStart();
        GET("houses", "virtualDomophone", domophoneId, true).
            fail(FAIL).
            done(r => {
                const d = modules.virtualDomophones.unwrap(r, "domophone");
                if (!d) {
                    error(i18n("errors.unknown"));
                    return;
                }
                const ext = d.ext || {};
                const door = ext.doorOpeningUrls || {};
                const servers = modules.virtualDomophones.serverOptions();
                cardForm({
                    title: i18n("virtualDomophones.editPanel") + " #" + domophoneId,
                    footer: true,
                    borderless: true,
                    topApply: true,
                    apply: i18n("edit"),
                    delete: i18n("virtualDomophones.deletePanel"),
                    deleteTab: i18n("addresses.primary"),
                    size: "lg",
                    fields: [
                        {
                            id: "enabled",
                            type: "yesno",
                            title: i18n("addresses.enabled"),
                            value: String(d.enabled ? 1 : 0),
                            tab: i18n("addresses.primary"),
                        },
                        {
                            id: "name",
                            type: "text",
                            title: i18n("virtualDomophones.name"),
                            value: d.name,
                            validate: v => $.trim(v).length > 0 && $.trim(v).length <= 64,
                            tab: i18n("addresses.primary"),
                        },
                        {
                            id: "server",
                            type: "select2",
                            title: i18n("virtualDomophones.server"),
                            options: servers,
                            value: d.server,
                            validate: v => $.trim(v) !== "",
                            tab: i18n("addresses.primary"),
                        },
                        {
                            id: "doorUrl0",
                            type: "text",
                            title: i18n("virtualDomophones.doorUrl0"),
                            value: door[0] || door["0"] || "",
                            tab: i18n("addresses.primary"),
                        },
                        {
                            id: "doorUrl1",
                            type: "text",
                            title: i18n("virtualDomophones.doorUrl1"),
                            value: door[1] || door["1"] || "",
                            tab: i18n("addresses.primary"),
                        },
                        {
                            id: "comments",
                            type: "text",
                            title: i18n("virtualDomophones.comments"),
                            value: d.comments || "",
                            validate: v => $.trim(v).length <= 64,
                            tab: i18n("addresses.secondary"),
                        },
                    ],
                    callback: data => {
                        if (data.delete === "yes") {
                            modules.virtualDomophones.deletePanel(domophoneId);
                            return;
                        }
                        loadingStart();
                        PUT("houses", "virtualDomophone", domophoneId, {
                            enabled: data.enabled,
                            name: $.trim(data.name),
                            server: data.server,
                            doorUrl0: $.trim(data.doorUrl0),
                            doorUrl1: $.trim(data.doorUrl1),
                            comments: $.trim(data.comments),
                            dtmf: d.dtmf || "1",
                            firstTime: d.firstTime,
                            nat: d.nat,
                            locksAreOpen: d.locksAreOpen,
                            display: d.display || "",
                            monitoring: d.monitoring,
                            ext: d.ext,
                            concierge: d.concierge || "",
                            sos: d.sos || "",
                            tree: d.tree || "",
                        }).
                            fail(FAIL).
                            done(() => {
                                message(i18n("virtualDomophones.panelChanged"));
                                modules.virtualDomophones.route({});
                            }).
                            always(loadingDone);
                    },
                });
            }).
            always(loadingDone);
    },

    route: function () {
        if (!AVAIL("houses", "virtualDomophones", "GET")) {
            page404();
            return;
        }
        /* Сразу убираем правую колонку от прошлого экрана (например подъезд с камерами/ключами). */
        $("#altForm").hide().empty();
        document.title = i18n("windowTitle") + " :: " + i18n("virtualDomophones.virtualDomophones");
        modules.virtualDomophones.loadMeta(() => {
            subTop();
            const list = modules.virtualDomophones.meta.domophones;
            const rows = [];
            for (let i = 0; i < list.length; i++) {
                const row = list[i];
                const slug = modules.virtualDomophones.extSlug(row.ext);
                const url = modules.virtualDomophones.guestPageUrl(slug);
                const linkCol = url
                    ? {
                        data: `<a href="${escapeHTML(url)}" target="_blank" rel="noopener">${escapeHTML(url)}</a>`,
                        nowrap: true,
                    }
                    : { data: "—" };

                rows.push({
                    uid: row.domophoneId,
                    cols: [
                        { data: String(row.domophoneId) },
                        { data: escapeHTML($.trim(row.name) || "—"), nowrap: true },
                        { data: escapeHTML(String(row.server || "")), nowrap: true },
                        linkCol,
                    ],
                    dropDown: {
                        items: [
                            {
                                title: i18n("virtualDomophones.guestUrl"),
                                icon: "fas fa-qrcode",
                                click: function (uid) {
                                    const s = modules.virtualDomophones.extSlug(row.ext);
                                    const u2 = modules.virtualDomophones.guestPageUrl(s);
                                    if (u2) {
                                        modules.virtualDomophones.showQrModal(u2);
                                    }
                                },
                            },
                            {
                                title: i18n("virtualDomophones.rotateSlug"),
                                icon: "fas fa-sync",
                                click: function (uid) {
                                    modules.virtualDomophones.rotateSlug(parseInt(uid, 10));
                                },
                            },
                            {
                                title: i18n("virtualDomophones.deletePanel"),
                                icon: "fas fa-trash-alt",
                                class: "text-danger",
                                click: function (uid) {
                                    modules.virtualDomophones.deletePanel(parseInt(uid, 10));
                                },
                            },
                        ],
                    },
                });
            }

            cardTable({
                target: "#mainForm",
                id: "rbt-virtual-domophones-table",
                title: {
                    caption: i18n("virtualDomophones.listTitle"),
                    button: {
                        caption: i18n("virtualDomophones.addPanel"),
                        click: modules.virtualDomophones.addPanel,
                    },
                },
                edit: function (id) {
                    modules.virtualDomophones.editPanel(parseInt(id, 10));
                },
                columns: [
                    { title: i18n("virtualDomophones.id") },
                    { title: i18n("virtualDomophones.name") },
                    { title: i18n("virtualDomophones.server") },
                    { title: i18n("virtualDomophones.guestUrl"), fullWidth: true },
                ],
                rows: function () {
                    return rows;
                },
            }).show();
        });
    },
}).init();
