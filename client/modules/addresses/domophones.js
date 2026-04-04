({
    init: function () {
        if ((AVAIL("addresses", "region", "PUT") || AVAIL("addresses", "addresses", "GET")) && AVAIL("houses", "domophones", "GET")) {
            leftSide("fas fa-fw fa-lock", i18n("addresses.domophones"), "?#addresses.domophones", "households");
        }
        moduleLoaded("addresses.domophones", this);
    },

    meta: false,
    startPage: 1,
    filter: "",

    doAddDomophone: function (domophone) {
        loadingStart();
        POST("houses", "domophone", false, domophone).
        fail(FAIL).
        done(() => {
            message(i18n("addresses.domophoneWasAdded"))
            modules.addresses.domophones.route({
                flter: domophone.url
            });
        }).
        fail(modules.addresses.domophones.route);
    },

    doModifyDomophone: function (domophone, params) {
        loadingStart();
        PUT("houses", "domophone", domophone.domophoneId, domophone).
        fail(FAIL).
        done(() => {
            message(i18n("addresses.domophoneWasChanged"))
        }).
        always(() => {
            modules.addresses.domophones.route(params);
        });
    },

    doDeleteDomophone: function (domophoneId) {
        loadingStart();
        DELETE("houses", "domophone", domophoneId).
        fail(FAIL).
        done(() => {
            message(i18n("addresses.domophoneWasDeleted"))
        }).
        always(modules.addresses.domophones.route);
    },

    addDomophone: function () {
        let models = [];
        let servers = [];

        for (let id in modules.addresses.domophones.meta.models) {
            models.push({
                id,
                text: modules.addresses.domophones.meta.models[id].title,
            })
        }

        models.sort((a, b) => {
            if (a.text.toLowerCase() > b.text.toLowerCase()) {
                return 1;
            }
            if (a.text.toLowerCase() < b.text.toLowerCase()) {
                return -1;
            }
            return 0;
        });

        for (let id in modules.addresses.domophones.meta.servers) {
            servers.push({
                id: modules.addresses.domophones.meta.servers[id].ip,
                text: modules.addresses.domophones.meta.servers[id].title,
            })
        }

        for (let i in modules.addresses.domophones.meta.tree) {
            if (modules.addresses.domophones.meta.tree[i].tree[modules.addresses.domophones.meta.tree[i].tree.length - 1] == ".") {
                modules.addresses.domophones.meta.tree[i].tree = modules.addresses.domophones.meta.tree[i].tree.substr(0, modules.addresses.domophones.meta.tree[i].tree.length - 1);
            }
            modules.addresses.domophones.meta.tree[i].id = modules.addresses.domophones.meta.tree[i].tree + ".";
            modules.addresses.domophones.meta.tree[i].text = modules.addresses.domophones.meta.tree[i].name;
        }

        let t = buildTreeFromPaths(modules.addresses.domophones.meta.tree);

        cardForm({
            title: i18n("addresses.addDomophone"),
            footer: true,
            borderless: true,
            topApply: true,
            apply: i18n("add"),
            size: "lg",
            fields: [
                {
                    id: "name",
                    type: "text",
                    title: i18n("addresses.domophoneName"),
                    placeholder: i18n("addresses.domophoneName"),
                    validate: v => {
                        return $.trim(v).length <= 64;
                    },
                    tab: i18n("addresses.primary"),
                },
                {
                    id: "enabled",
                    type: "yesno",
                    title: i18n("addresses.enabled"),
                    value: "1",
                    tab: i18n("addresses.primary"),
                },
                {
                    id: "model",
                    type: "select2",
                    title: i18n("addresses.model"),
                    placeholder: i18n("addresses.model"),
                    options: models,
                    tab: i18n("addresses.primary"),
                },
                {
                    id: "server",
                    type: "select2",
                    title: i18n("addresses.server"),
                    placeholder: i18n("addresses.server"),
                    options: servers,
                    tab: i18n("addresses.primary"),
                },
                {
                    id: "url",
                    type: "text",
                    title: i18n("addresses.url"),
                    placeholder: "http://",
                    validate: v => {
                        try {
                            if (!/^https?:\/\/.+/.test(v)) {
                                throw new Error();
                            }
                            new URL(v);
                            return true;
                        } catch (_) {
                            return false;
                        }
                    },
                    tab: i18n("addresses.primary"),
                },
                {
                    id: "credentials",
                    type: "text",
                    title: i18n("addresses.credentials"),
                    placeholder: i18n("addresses.credentials"),
                    validate: v => {
                        return $.trim(v) !== "";
                    },
                    tab: i18n("addresses.primary"),
                    button: {
                        class: "fas fa-fw fa-magic",
                        hint: i18n("addresses.generatePassword"),
                        click: prefix => {
                            PWGen.initialize();
                            let p = PWGen.generate();
                            $(`#${prefix}credentials`).val(p);
                        },
                    },
                },
                {
                    id: "dtmf",
                    type: "text",
                    title: i18n("addresses.dtmf"),
                    placeholder: i18n("addresses.dtmf"),
                    value: "1",
                    validate: v => {
                        return [ "*", "#", "0", "1", "2", "3", "4", "5", "6", "7", "8", "9" ].indexOf($.trim(v)) >= 0;
                    },
                    tab: i18n("addresses.secondary"),
                },
                {
                    id: "nat",
                    type: "yesno",
                    title: i18n("addresses.nat"),
                    value: "0",
                    tab: i18n("addresses.secondary"),
                },
                {
                    id: "video",
                    type: "select",
                    title: i18n("addresses.video"),
                    options: [
                        {
                            id: "inband",
                            text: i18n("addresses.inband"),
                        },
                        {
                            id: "webrtc",
                            text: i18n("addresses.webrtc"),
                        },
                    ],
                    value: "inband",
                    tab: i18n("addresses.secondary"),
                },
                {
                    id: "concierge",
                    type: "text",
                    title: i18n("addresses.concierge"),
                    placeholder: i18n("addresses.concierge"),
                    hint: i18n("addresses.dial"),
                    tab: i18n("addresses.secondary"),
                },
                {
                    id: "sos",
                    type: "text",
                    title: i18n("addresses.sos"),
                    placeholder: i18n("addresses.sos"),
                    hint: i18n("addresses.dial"),
                    tab: i18n("addresses.secondary"),
                },
                {
                    id: "comments",
                    type: "text",
                    title: i18n("addresses.comments"),
                    placeholder: i18n("addresses.comments"),
                    validate: v => {
                        return $.trim(v).length <= 64;
                    },
                    tab: i18n("addresses.secondary"),
                },
                {
                    id: "monitoring",
                    type: "yesno",
                    title: i18n("addresses.monitoring"),
                    placeholder: i18n("addresses.monitoring"),
                    tab: i18n("addresses.primary"),
                },
                {
                    id: "display",
                    type: "area",
                    title: i18n("addresses.display"),
                    placeholder: i18n("addresses.display"),
                    tab: i18n("addresses.primary"),
                },
                {
                    id: "tree",
                    type: "jstree",
                    title: false,
                    tab: i18n("addresses.path"),
                    data: t,
                    search: true,
                },
                {
                    id: "ext",
                    type: "json",
                    title: false,
                    tab: i18n("addresses.ext"),
                    noHover: true,
                },
            ],
            callback: modules.addresses.domophones.doAddDomophone,
        });
    },

    modifyDomophone: function (domophoneId, params) {
        let models = [];
        let servers = [];

        for (let id in modules.addresses.domophones.meta.models) {
            models.push({
                id,
                text: modules.addresses.domophones.meta.models[id].title,
            })
        }

        models.sort((a, b) => {
            if (a.text.toLowerCase() > b.text.toLowerCase()) {
                return 1;
            }
            if (a.text.toLowerCase() < b.text.toLowerCase()) {
                return -1;
            }
            return 0;
        });

        for (let id in modules.addresses.domophones.meta.servers) {
            servers.push({
                id: modules.addresses.domophones.meta.servers[id].ip,
                text: modules.addresses.domophones.meta.servers[id].title,
            })
        }

        let domophone = false;

        for (let i in modules.addresses.domophones.meta.domophones) {
            if (modules.addresses.domophones.meta.domophones[i].domophoneId == domophoneId) {
                domophone = modules.addresses.domophones.meta.domophones[i];
                break;
            }
        }

        for (let i in modules.addresses.domophones.meta.tree) {
            if (modules.addresses.domophones.meta.tree[i].tree[modules.addresses.domophones.meta.tree[i].tree.length - 1] == ".") {
                modules.addresses.domophones.meta.tree[i].tree = modules.addresses.domophones.meta.tree[i].tree.substr(0, modules.addresses.domophones.meta.tree[i].tree.length - 1);
            }
            modules.addresses.domophones.meta.tree[i].id = modules.addresses.domophones.meta.tree[i].tree + ".";
            modules.addresses.domophones.meta.tree[i].text = modules.addresses.domophones.meta.tree[i].name;
            modules.addresses.domophones.meta.tree[i].state = (modules.addresses.domophones.meta.tree[i].id == domophone.tree) ? { selected: true, } : {};
        }

        let t = buildTreeFromPaths(modules.addresses.domophones.meta.tree);

        if (domophone) {
            cardForm({
                title: i18n("addresses.editDomophone"),
                footer: true,
                borderless: true,
                topApply: true,
                apply: i18n("edit"),
                delete: i18n("addresses.deleteDomophone"),
                deleteTab: i18n("addresses.primary"),
                size: "lg",
                fields: [
                    {
                        id: "domophoneId",
                        type: "text",
                        title: i18n("addresses.domophoneId"),
                        value: domophoneId,
                        readonly: true,
                        hint: 100000 + parseInt(domophoneId),
                        tab: i18n("addresses.primary"),
                    },
                    {
                        id: "name",
                        type: "text",
                        title: i18n("addresses.domophoneName"),
                        placeholder: i18n("addresses.domophoneName"),
                        value: domophone.name,
                        validate: v => {
                            return $.trim(v).length <= 64;
                        },
                        tab: i18n("addresses.primary"),
                    },
                    {
                        id: "enabled",
                        type: "yesno",
                        title: i18n("addresses.enabled"),
                        value: domophone.enabled,
                        tab: i18n("addresses.primary"),
                    },
                    {
                        id: "model",
                        type: "select2",
                        title: i18n("addresses.model"),
                        placeholder: i18n("addresses.model"),
                        options: models,
                        value: domophone.model,
                        tab: i18n("addresses.primary"),
                    },
                    {
                        id: "server",
                        type: "select2",
                        title: i18n("addresses.server"),
                        placeholder: i18n("addresses.server"),
                        options: servers,
                        value: domophone.server,
                        tab: i18n("addresses.primary"),
                    },
                    {
                        id: "url",
                        type: "text",
                        title: i18n("addresses.url"),
                        placeholder: "http://",
                        value: domophone.url,
                        validate: v => {
                            try {
                                if (!/^https?:\/\/.+/.test(v)) {
                                    throw new Error();
                                }
                                new URL(v);
                                return true;
                            } catch (_) {
                                return false;
                            }
                        },
                        tab: i18n("addresses.primary"),
                    },
                    {
                        id: "credentials",
                        type: "text",
                        title: i18n("addresses.credentials"),
                        placeholder: i18n("addresses.credentials"),
                        value: domophone.credentials,
                        validate: v => {
                            return $.trim(v) !== "";
                        },
                        tab: i18n("addresses.primary"),
                    },
                    {
                        id: "dtmf",
                        type: "text",
                        title: i18n("addresses.dtmf"),
                        placeholder: i18n("addresses.dtmf"),
                        value: domophone.dtmf,
                        validate: v => {
                            return [ "*", "#", "0", "1", "2", "3", "4", "5", "6", "7", "8", "9" ].indexOf($.trim(v)) >= 0;
                        },
                        tab: i18n("addresses.secondary"),
                    },
                    {
                        id: "firstTime",
                        type: "yesno",
                        title: i18n("addresses.firstTime"),
                        value: domophone.firstTime,
                        tab: i18n("addresses.secondary"),
                    },
                    {
                        id: "nat",
                        type: "yesno",
                        title: i18n("addresses.nat"),
                        value: domophone.nat,
                        tab: i18n("addresses.secondary"),
                    },
                    {
                        id: "video",
                        type: "select",
                        title: i18n("addresses.video"),
                        options: [
                            {
                                id: "inband",
                                text: i18n("addresses.inband"),
                            },
                            {
                                id: "webrtc",
                                text: i18n("addresses.webrtc"),
                            },
                        ],
                        value: domophone.video,
                        tab: i18n("addresses.secondary"),
                    },
                    {
                        id: "concierge",
                        type: "text",
                        title: i18n("addresses.concierge"),
                        placeholder: i18n("addresses.concierge"),
                        hint: i18n("addresses.dial"),
                        value: domophone.concierge,
                        tab: i18n("addresses.secondary"),
                    },
                    {
                        id: "sos",
                        type: "text",
                        title: i18n("addresses.sos"),
                        placeholder: i18n("addresses.sos"),
                        hint: i18n("addresses.dial"),
                        value: domophone.sos,
                        tab: i18n("addresses.secondary"),
                    },
                    {
                        id: "locksAreOpen",
                        type: "yesno",
                        title: i18n("addresses.locksAreOpen"),
                        value: domophone.locksAreOpen,
                        tab: i18n("addresses.secondary"),
                    },
                    {
                        id: "comments",
                        type: "text",
                        title: i18n("addresses.comments"),
                        placeholder: i18n("addresses.comments"),
                        value: domophone.comments,
                        validate: v => {
                            return $.trim(v).length <= 64;
                        },
                        tab: i18n("addresses.secondary"),
                    },
                    {
                        id: "display",
                        type: "area",
                        title: i18n("addresses.display"),
                        placeholder: i18n("addresses.displayPlaceholder"),
                        value: domophone.display,
                        tab: i18n("addresses.primary"),
                    },
                    {
                        id: "monitoring",
                        type: "yesno",
                        title: i18n("addresses.monitoring"),
                        placeholder: i18n("addresses.monitoring"),
                        tab: i18n("addresses.secondary"),
                        value: domophone.monitoring,
                    },
                    {
                        id: "tree",
                        type: "jstree",
                        title: false,
                        tab: i18n("addresses.path"),
                        data: t,
                        value: domophone.tree,
                        search: true,
                    },
                    {
                        id: "ext",
                        type: "json",
                        title: false,
                        tab: i18n("addresses.ext"),
                        value: domophone.ext,
                        noHover: true,
                    },
                ],
                callback: result => {
                    if (result.delete === "yes") {
                        modules.addresses.domophones.deleteDomophone(domophoneId);
                    } else {
                        modules.addresses.domophones.doModifyDomophone(result, params);
                    }
                },
            });
        } else {
            error(i18n("addresses.domophoneNotFound"));
        }
    },

    deleteDomophone: function (domophoneId) {
        mConfirm(i18n("addresses.confirmDeleteDomophone", domophoneId), i18n("confirm"), `danger:${i18n("addresses.deleteDomophone")}`, () => {
            modules.addresses.domophones.doDeleteDomophone(domophoneId);
        });
    },

    refreshDomophoneListThumbs: function () {
        $("#rbt-domophones-list-table .rbt-entrance-cam-thumb[data-camera-id]").each(function () {
            let $img = $(this);
            let id = $img.attr("data-camera-id");
            if (!id) {
                return;
            }
            GET("cameras", "camshot", id, true).
                done(r => {
                    if (r && r.shot) {
                        $img.attr("src", "data:image/jpeg;base64," + r.shot);
                    }
                }).
                fail(() => {});
        });
    },

    openDomophonePreviewViewer: function (domophoneId) {
        let d = null;
        for (let i in modules.addresses.domophones.meta.domophones) {
            if (String(modules.addresses.domophones.meta.domophones[i].domophoneId) === String(domophoneId)) {
                d = modules.addresses.domophones.meta.domophones[i];
                break;
            }
        }
        if (!d || !parseInt(d.previewCameraId, 10)) {
            return;
        }
        let title = i18n("domophone") + " #" + domophoneId;
        if (d.name) {
            title += " — " + d.name;
        }
        modules.addresses.houses.openCameraStreamViewerByCameraId(d.previewCameraId, title);
    },

    route: function (params) {
        $("#altForm").hide();

        document.title = i18n("windowTitle") + " :: " + i18n("addresses.domophones");

        if (params.filter && typeof params.filter !== "function") {
            lStore("domophones.filter", params.filter);
            modules.addresses.domophones.filter = params.filter;
        } else {
            modules.addresses.domophones.filter = lStore("domophones.filter");
        }

        QUERY("houses", "domophones", { by: (params.tree ? "tree" : false), query: (params.tree ? params.tree : false) }, true).
        done(response => {
            modules.addresses.domophones.meta = response.domophones;

            if (response.domophones.tree != "unavailable") {
                modules.addresses.treePath(response.domophones.tree, params.tree, params.id);
            } else {
                subTop();
            }

            cardTable({
                target: "#mainForm",
                id: "rbt-domophones-list-table",
                title: {
                    caption: i18n("addresses.domophones"),
                    button: {
                        caption: i18n("addresses.addDomophone"),
                        click: modules.addresses.domophones.addDomophone,
                    },
                    filter: modules.addresses.domophones.filter ? modules.addresses.domophones.filter : true,
                    filterChange: f => {
                        lStore("domophones.filter", f);
                        modules.addresses.domophones.filter = f;
                    },
                },
                afterRedraw: () => {
                    modules.addresses.domophones.refreshDomophoneListThumbs();
                },
                edit: id => {
                    modules.addresses.domophones.modifyDomophone(id, params);
                },
                startPage: modules.addresses.domophones.startPage,
                pageChange: p => {
                    modules.addresses.domophones.startPage = p;
                },
                columns: [
                    {
                        title: i18n("addresses.domophoneIdList"),
                    },
                    {
                        title: i18n("addresses.entranceCameraPreview"),
                        thClass: "rbt-entrance-preview-col",
                    },
                    {
                        title: i18n("addresses.url"),
                    },
                    {
                        title: i18n("addresses.model"),
                    },
                    {
                        title: i18n("addresses.domophoneName"),
                    },
                    {
                        title: i18n("addresses.comments"),
                        fullWidth: true,
                    },
                ],
                rows: () => {
                    let rows = [];

                    for (let i in modules.addresses.domophones.meta.domophones) {
                        if (!params.id || params.id == modules.addresses.domophones.meta.domophones[i].domophoneId) {
                            let dp = modules.addresses.domophones.meta.domophones[i];
                            let did = dp.domophoneId;
                            let pCam = dp.previewCameraId;
                            let hasPrev = parseInt(pCam, 10) > 0;
                            rows.push({
                                uid: did,
                                cols: [
                                    {
                                        data: did,
                                    },
                                    hasPrev ? {
                                        data: `<img class="rbt-entrance-cam-thumb" src="img/cctv.png" alt="" loading="lazy" data-camera-id="${pCam}">`,
                                        click: domophoneId => {
                                            modules.addresses.domophones.openDomophonePreviewViewer(domophoneId);
                                        },
                                        class: "rbt-entrance-preview-cell",
                                    } : {
                                        data: "—",
                                        class: "text-muted rbt-entrance-preview-cell",
                                    },
                                    {
                                        data: dp.url,
                                        nowrap: true,
                                    },
                                    {
                                        data: modules.addresses.domophones.meta.models[dp.model]?.title ?? "&nbsp;",
                                        nowrap: true,
                                    },
                                    {
                                        data: dp.name ? dp.name : "",
                                        nowrap: true,
                                    },
                                    {
                                        data: dp.comments ? dp.comments : "",
                                        nowrap: true,
                                    },
                                ],
                            });
                        }
                    }

                    return rows;
                },
            }).show();
        }).
        fail(x => {
            if (x && x.responseJSON && (x.responseJSON.error === "accessDenied" || x.responseJSON.error === "permissionDenied")) {
                subTop();
                pageError(i18n("errors.accessDenied"));
                return;
            }
            FAIL(x);
        }).
        always(loadingDone);
    },
}).init();