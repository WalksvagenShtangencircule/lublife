/* Встроенные подписи: сервис-воркер и браузерный кэш могут отдавать старый i18n/ru.json без блока objectSeniors. */
var __OS_FALL = {
    ru: {
        objectSeniors: "Старшие по объектам",
        menuTitle: "Старшие по объектам",
        listTitle: "Личные кабинеты старших",
        add: "Добавить кабинет",
        addTitle: "Новый личный кабинет",
        editTitle: "Редактирование кабинета",
        cabinetLink: "Ссылка в ЛК",
        colTitle: "Название",
        colHouse: "Объект",
        colLogin: "Логин",
        colFlags: "Права",
        house: "Дом / объект",
        labelTitle: "Подпись",
        login: "Логин кабинета",
        password: "Пароль",
        passwordNew: "Новый пароль (оставьте пустым, если не менять)",
        canEvents: "Просмотр событий",
        canSubs: "Управление жильцами",
        canEntr: "Назначение подъездов",
        scopedFlatsTitle: "Квартиры (ID)",
        scopedFlatsHint: "Ограничение по квартирам (ID через запятую; пусто = весь дом)",
        badPassword: "Пароль не менее 6 символов",
        created: "Кабинет создан",
        saved: "Сохранено",
        slugRotated: "Новый slug",
        confirmDelete: "Удалить запись кабинета?",
        delete: "Удалить",
        deleted: "Удалено",
        edit: "Изменить",
        rotateSlug: "Новая ссылка (slug)",
    },
    en: {
        objectSeniors: "Building representatives",
        menuTitle: "Building representatives",
        listTitle: "Senior dashboards",
        add: "Add dashboard",
        addTitle: "New dashboard",
        editTitle: "Edit dashboard",
        cabinetLink: "Dashboard link",
        colTitle: "Title",
        colHouse: "Site",
        colLogin: "Login",
        colFlags: "Rights",
        house: "House",
        labelTitle: "Label",
        login: "Login",
        password: "Password",
        passwordNew: "New password (leave empty to keep)",
        canEvents: "View events",
        canSubs: "Manage residents",
        canEntr: "Entrance access",
        scopedFlatsTitle: "Flat IDs",
        scopedFlatsHint: "Flat IDs (comma); empty = whole building",
        badPassword: "Password at least 6 characters",
        created: "Created",
        saved: "Saved",
        slugRotated: "New slug",
        confirmDelete: "Delete this dashboard?",
        delete: "Delete",
        deleted: "Deleted",
        edit: "Edit",
        rotateSlug: "Rotate link (slug)",
    },
};

function __osTxt(subKey) {
    const msg = "objectSeniors." + subKey;
    const v = i18n(msg);
    if (v !== msg) {
        return v;
    }
    const l = lStore("_lang") || "ru";
    const pack = __OS_FALL[l] || __OS_FALL.ru;
    return Object.prototype.hasOwnProperty.call(pack, subKey) ? pack[subKey] : msg;
}

/** Значение из cardForm после type yesno → select: строки "1"/"0". */
function __osBool01(v) {
    return v === true || v === 1 || String(v) === "1";
}

({
    meta: { houses: [] },

    init: function () {
        if (AVAIL("objectSeniors", "items", "GET")) {
            leftSide("fas fa-fw fa-user-tie", i18n("objectSeniors.menuTitle"), "?#objectSeniors", "households");
        }
        moduleLoaded("objectSeniors", this);
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

    seniorCabinetUrl: function (slug) {
        if (!slug) {
            return "";
        }
        const api = String(lStore("_server") || "").replace(/\/$/, "");
        try {
            const u = new URL(api);
            return u.origin + "/frontend/house-senior/index.html?t=" + encodeURIComponent(slug);
        } catch (e) {
            const front = api.replace(/\/api$/i, "/frontend");
            return front.replace(/\/frontend$/i, "") + "/frontend/house-senior/index.html?t=" + encodeURIComponent(slug);
        }
    },

    loadHouses: function (doneFn) {
        if (!AVAIL("addresses", "addresses", "GET")) {
            if (typeof doneFn === "function") {
                doneFn();
            }
            return;
        }
        QUERY("addresses", "addresses", { include: "houses" }, true).
            done(r => {
                const pack = r && r.addresses ? r.addresses : (r && r["200"] && r["200"].addresses ? r["200"].addresses : null);
                modules.objectSeniors.meta.houses = (pack && pack.houses) ? pack.houses : [];
            }).
            always(() => {
                if (typeof doneFn === "function") {
                    doneFn();
                }
            });
    },

    showQrModal: function (url) {
        const mid = "osQr" + md5(url + String(Math.random()));
        const $m = $(`<div class="modal fade" id="${mid}" tabindex="-1" role="dialog" aria-hidden="true">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">${escapeHTML(i18n("objectSeniors.cabinetLink"))}</h5>
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
        $m.modal("show");
        const el = document.getElementById(mid + "-qr");
        if (el && typeof QRCode !== "undefined") {
            const qr = new QRCode(el, { width: 220, height: 220, correctLevel: QRCode.CorrectLevel.M });
            qr.makeCode(url);
        }
        $m.on("hidden.bs.modal", function () {
            $m.remove();
        });
    },

    loadList: function (done) {
        loadingStart();
        QUERY("objectSeniors", "items", {}, true).
            fail(FAIL).
            done(r => {
                const rows = modules.objectSeniors.unwrap(r, "objectSeniors");
                if (typeof done === "function") {
                    done(Array.isArray(rows) ? rows : []);
                }
            }).
            always(loadingDone);
    },

    addSenior: function () {
        modules.objectSeniors.loadHouses(() => {
            const houseOpts = [];
            const hs = modules.objectSeniors.meta.houses || [];
            for (let i = 0; i < hs.length; i++) {
                const h = hs[i];
                if (h && h.houseId != null) {
                    houseOpts.push({ id: String(h.houseId), text: h.houseFull || ("#" + h.houseId) });
                }
            }
            cardForm({
                title: i18n("objectSeniors.addTitle"),
                size: "lg",
                footer: true,
                borderless: true,
                topApply: true,
                apply: i18n("add"),
                fields: [
                    { id: "houseId", type: "select2", title: i18n("objectSeniors.house"), options: houseOpts, required: true },
                    { id: "title", type: "text", title: i18n("objectSeniors.labelTitle"), required: false },
                    { id: "login", type: "text", title: i18n("objectSeniors.login"), required: true },
                    { id: "password", type: "password", title: i18n("objectSeniors.password"), required: true },
                    { id: "can_view_events", type: "yesno", title: i18n("objectSeniors.canEvents"), value: true },
                    { id: "can_manage_subscribers", type: "yesno", title: i18n("objectSeniors.canSubs"), value: true },
                    { id: "can_manage_entrance_access", type: "yesno", title: i18n("objectSeniors.canEntr"), value: true },
                    { id: "scopedFlatIdsText", type: "text", title: __osTxt("scopedFlatsTitle"), hint: __osTxt("scopedFlatsHint"), required: false },
                ],
                callback: data => {
                    const houseId = parseInt(data.houseId, 10);
                    const pwd = String(data.password || "");
                    if (!houseId || pwd.length < 6) {
                        warning(i18n("objectSeniors.badPassword"));
                        return;
                    }
                    let scoped = [];
                    const tx = $.trim(String(data.scopedFlatIdsText || ""));
                    if (tx) {
                        tx.split(/[,\\s]+/).forEach(p => {
                            const n = parseInt(p, 10);
                            if (n > 0) {
                                scoped.push(n);
                            }
                        });
                    }
                    const body = {
                        houseId: houseId,
                        title: $.trim(String(data.title || "")),
                        login: $.trim(String(data.login || "")),
                        password: pwd,
                        can_view_events: __osBool01(data.can_view_events),
                        can_manage_subscribers: __osBool01(data.can_manage_subscribers),
                        can_manage_entrance_access: __osBool01(data.can_manage_entrance_access),
                    };
                    if (scoped.length) {
                        body.scopedFlatIds = scoped;
                    }
                    loadingStart();
                    POST("objectSeniors", "senior", false, body).
                        fail(FAIL).
                        done(() => {
                            message(i18n("objectSeniors.created"));
                            modules.objectSeniors.route({});
                        }).
                        always(loadingDone);
                },
            });
        });
    },

    editSenior: function (row) {
        cardForm({
                title: i18n("objectSeniors.editTitle") + " — " + escapeHTML(String(row.houseFull || row.houseId || "")),
                size: "lg",
                footer: true,
                borderless: true,
                topApply: true,
                apply: i18n("save"),
                fields: [
                    { id: "title", type: "text", title: i18n("objectSeniors.labelTitle"), value: row.title || "" },
                    { id: "login", type: "text", title: i18n("objectSeniors.login"), value: row.login || "", required: true },
                    { id: "password", type: "password", title: i18n("objectSeniors.passwordNew"), required: false },
                    { id: "can_view_events", type: "yesno", title: i18n("objectSeniors.canEvents"), value: !!row.can_view_events },
                    { id: "can_manage_subscribers", type: "yesno", title: i18n("objectSeniors.canSubs"), value: !!row.can_manage_subscribers },
                    { id: "can_manage_entrance_access", type: "yesno", title: i18n("objectSeniors.canEntr"), value: !!row.can_manage_entrance_access },
                    { id: "scopedFlatIdsText", type: "text", title: __osTxt("scopedFlatsTitle"), hint: __osTxt("scopedFlatsHint"), value: (row.scopedFlatIds || []).join(",") },
                ],
                callback: data => {
                    const body = {
                        title: $.trim(String(data.title || "")),
                        login: $.trim(String(data.login || "")),
                        can_view_events: __osBool01(data.can_view_events),
                        can_manage_subscribers: __osBool01(data.can_manage_subscribers),
                        can_manage_entrance_access: __osBool01(data.can_manage_entrance_access),
                    };
                    const pwd = String(data.password || "");
                    if (pwd.length > 0) {
                        if (pwd.length < 6) {
                            warning(i18n("objectSeniors.badPassword"));
                            return;
                        }
                        body.password = pwd;
                    }
                    const tx = $.trim(String(data.scopedFlatIdsText || ""));
                    const scoped = [];
                    if (tx) {
                        tx.split(/[,\\s]+/).forEach(p => {
                            const n = parseInt(p, 10);
                            if (n > 0) {
                                scoped.push(n);
                            }
                        });
                    }
                    body.scopedFlatIds = scoped;
                    loadingStart();
                    PUT("objectSeniors", "senior", row.seniorId, body).
                        fail(FAIL).
                        done(() => {
                            message(i18n("objectSeniors.saved"));
                            modules.objectSeniors.route({});
                        }).
                        always(loadingDone);
                },
        });
    },

    rotateSlug: function (seniorId) {
        loadingStart();
        PUT("objectSeniors", "rotateSlug", seniorId, {}).
            fail(FAIL).
            done(r => {
                const pack = modules.objectSeniors.unwrap(r, "objectSeniors");
                if (pack && pack.slug) {
                    message(i18n("objectSeniors.slugRotated") + ": " + pack.slug);
                }
                modules.objectSeniors.route({});
            }).
            always(loadingDone);
    },

    deleteSenior: function (seniorId) {
        mConfirm(i18n("objectSeniors.confirmDelete"), i18n("confirm"), "danger:" + i18n("objectSeniors.delete"), () => {
            loadingStart();
            DELETE("objectSeniors", "senior", seniorId, {}).
                fail(FAIL).
                done(() => {
                    message(i18n("objectSeniors.deleted"));
                    modules.objectSeniors.route({});
                }).
                always(loadingDone);
        });
    },

    route: function () {
        if (!AVAIL("objectSeniors", "items", "GET")) {
            page404();
            return;
        }
        document.title = i18n("windowTitle") + " :: " + i18n("objectSeniors.objectSeniors");
        modules.objectSeniors.loadList(list => {
            subTop();
            const rows = [];
            for (let i = 0; i < list.length; i++) {
                const row = list[i];
                const url = modules.objectSeniors.seniorCabinetUrl(row.slug);
                const linkCol = url
                    ? { data: `<a href="${escapeHTML(url)}" target="_blank" rel="noopener">${escapeHTML(url)}</a>`, nowrap: true }
                    : { data: "—" };
                const flags = [
                    row.can_view_events ? "E" : "",
                    row.can_manage_subscribers ? "U" : "",
                    row.can_manage_entrance_access ? "T" : "",
                ].join("");
                rows.push({
                    uid: row.seniorId,
                    cols: [
                        { data: String(row.seniorId) },
                        { data: escapeHTML(String(row.title || "—")), nowrap: true },
                        { data: escapeHTML(String(row.houseFull || row.houseId || "")), nowrap: true },
                        { data: escapeHTML(String(row.login || "")), nowrap: true },
                        { data: escapeHTML(flags || "—"), nowrap: true },
                        linkCol,
                    ],
                    dropDown: {
                        items: [
                            {
                                title: i18n("objectSeniors.cabinetLink"),
                                icon: "fas fa-qrcode",
                                click: function () {
                                    if (url) {
                                        modules.objectSeniors.showQrModal(url);
                                    }
                                },
                            },
                            {
                                title: i18n("objectSeniors.edit"),
                                icon: "fas fa-pen",
                                click: function () {
                                    modules.objectSeniors.editSenior(row);
                                },
                            },
                            {
                                title: i18n("objectSeniors.rotateSlug"),
                                icon: "fas fa-sync",
                                click: function () {
                                    modules.objectSeniors.rotateSlug(row.seniorId);
                                },
                            },
                            {
                                title: i18n("objectSeniors.delete"),
                                icon: "fas fa-trash-alt",
                                class: "text-danger",
                                click: function () {
                                    modules.objectSeniors.deleteSenior(row.seniorId);
                                },
                            },
                        ],
                    },
                });
            }
            cardTable({
                target: "#mainForm",
                id: "rbt-object-seniors-table",
                title: {
                    caption: i18n("objectSeniors.listTitle"),
                    button: {
                        caption: i18n("objectSeniors.add"),
                        click: modules.objectSeniors.addSenior,
                    },
                },
                edit: function (id) {
                    const found = list.find(x => String(x.seniorId) === String(id));
                    if (found) {
                        modules.objectSeniors.editSenior(found);
                    }
                },
                columns: [
                    { title: "ID" },
                    { title: i18n("objectSeniors.colTitle") },
                    { title: i18n("objectSeniors.colHouse") },
                    { title: i18n("objectSeniors.colLogin") },
                    { title: i18n("objectSeniors.colFlags") },
                    { title: i18n("objectSeniors.cabinetLink"), fullWidth: true },
                ],
                rows: function () {
                    return rows;
                },
            });
        });
    },
}).init();
