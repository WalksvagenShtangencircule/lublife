({
    init: function () {
        setInterval(() => {
            let a = $("a.nav-link:contains('Добавить квартиры')");
            if (a.length) {
                a.text('Дoбавить квартиры').off("click").on("click", function (e) {
                    e.stopPropagation();
                    let entrances = [];

                    for (let i in modules.addresses.houses.meta.entrances) {
                        entrances.push({
                            id: modules.addresses.houses.meta.entrances[i].entranceId,
                            text: i18n("addresses.entranceType" + modules.addresses.houses.meta.entrances[i].entranceType.substring(0, 1).toUpperCase() + modules.addresses.houses.meta.entrances[i].entranceType.substring(1) + "Full") + " " + modules.addresses.houses.meta.entrances[i].entrance,
                        });
                    }

                    let [ route, params, hash ] = hashParse();

                    cardForm({
                        title: i18n("addresses.addFlats"),
                        footer: true,
                        borderless: true,
                        topApply: true,
                        size: "lg",
                        apply: i18n("add"),
                        fields: [
                            {
                                id: "firstFloor",
                                value: "1",
                                title: i18n("addresses.firstFloor"),
                                validate: v => {
                                    return parseInt(v) >= 0;
                                },
                            },
                            {
                                id: "firstFlat",
                                value: "1",
                                title: i18n("addresses.firstFlat"),
                                validate: v => {
                                    return parseInt(v) >= 0;
                                },
                            },
                            {
                                id: "flatsByFloor",
                                title: i18n("addresses.flatsByFloor"),
                                value: "1",
                                validate: v => {
                                    return parseInt(v) > 0;
                                },
                            },
                            {
                                id: "totalFlats",
                                title: i18n("addresses.totalFlats"),
                                value: "1",
                                validate: v => {
                                    return parseInt(v) > 0;
                                },
                            },
                            {
                                id: "billingId",
                                title: "Идентификатор в биллинге",
                            },
                            {
                                id: "price",
                                title: "Стоимость услуги",
                            },
                            {
                                id: "entrances",
                                type: "multiselect",
                                title: i18n("addresses.entrances"),
                                hidden: entrances.length <= 0,
                                options: entrances,
                            },
                            {
                                id: "manualBlock",
                                type: "select",
                                title: i18n("addresses.manualBlock"),
                                placeholder: i18n("addresses.manualBlock"),
                                options: [
                                    {
                                        id: "0",
                                        text: i18n("no"),
                                    },
                                    {
                                        id: "1",
                                        text: i18n("yes"),
                                    },
                                ]
                            },
                            {
                                id: "adminBlock",
                                type: "select",
                                title: i18n("addresses.adminBlock"),
                                placeholder: i18n("addresses.adminBlock"),
                                options: [
                                    {
                                        id: "0",
                                        text: i18n("no"),
                                    },
                                    {
                                        id: "1",
                                        text: i18n("yes"),
                                    },
                                ]
                            },
                            {
                                id: "plog",
                                type: "select",
                                title: i18n("addresses.plog"),
                                placeholder: i18n("addresses.plog"),
                                options: [
                                    {
                                        id: "0",
                                        text: i18n("addresses.plogNone"),
                                    },
                                    {
                                        id: "1",
                                        text: i18n("addresses.plogAll"),
                                    },
                                    {
                                        id: "2",
                                        text: i18n("addresses.plogOwner"),
                                    },
                                    {
                                        id: "3",
                                        text: i18n("addresses.adminDisabled"),
                                    },
                                ],
                                value: 1,
                            },
                            {
                                id: "openCode",
                                type: "noyes",
                                title: i18n("addresses.openCode"),
                            },
                            {
                                id: "autoOpen",
                                type: "datetime-local",
                                sec: true,
                                title: i18n("addresses.autoOpen"),
                            },
                            {
                                id: "whiteRabbit",
                                type: "select",
                                title: i18n("addresses.whiteRabbit"),
                                placeholder: i18n("addresses.whiteRabbit"),
                                options: [
                                    {
                                        id: "0",
                                        text: i18n("no"),
                                    },
                                    {
                                        id: "1",
                                        text: i18n("addresses.1m"),
                                    },
                                    {
                                        id: "2",
                                        text: i18n("addresses.2m"),
                                    },
                                    {
                                        id: "3",
                                        text: i18n("addresses.3m"),
                                    },
                                    {
                                        id: "5",
                                        text: i18n("addresses.5m"),
                                    },
                                    {
                                        id: "7",
                                        text: i18n("addresses.7m"),
                                    },
                                    {
                                        id: "10",
                                        text: i18n("addresses.10m"),
                                    },
                                ]
                            },
                        ],
                        callback: result => {
                            let flats = [];
                            let floor = parseInt(result.firstFloor);
                            let flatsByFloor = 0;
                            for (let f = parseInt(result.firstFlat); f < parseInt(result.firstFlat) + parseInt(result.totalFlats); f++) {
                                flats.push({
                                    houseId: params.houseId,
                                    floor: floor,
                                    flat: f,
                                    code: md5(guid()),
                                    entrances: result.entrances,
                                    apartmentsAndLevels: false,
                                    manualBlock: result.manualBlock,
                                    adminBlock: result.adminBlock,
                                    openCode: parseInt(result.openCode) ? "!" : "00000",
                                    plog: result.plog,
                                    autoOpen: result.autoOpen,
                                    whiteRabbit: result.whiteRabbit,
                                    sipEnabled: 0,
                                    sipPassword: "",
                                });
                                flatsByFloor++;
                                if (flatsByFloor >= parseInt(result.flatsByFloor)) {
                                    flatsByFloor = 0;
                                    floor++;
                                }
                            }
                            let flatsAdded = 0;
                            let flat = flats.shift();
                            if (flat) {
                                loadingStart();

                                (function a(flat) {

                                    function n() {
                                        flat = flats.shift();
                                        if (flat) {
                                            a(flat);
                                        } else {
                                            message(i18n("addresses.flatsWasAdded", flatsAdded));
                                            modules.addresses.houses.renderHouse(params.houseId);
                                        }
                                    }

                                    POST("houses", "flat", false, flat).
                                    done(() => {
                                        flatsAdded++;
                                    }).
                                    always(r => {
                                        if (r && r.flatId && result.billingId && result.price) {
                                            PUT("houses", "customFields", "flat", {
                                                id: r.flatId,
                                                customFields: {
                                                    billing_id: result.billingId,
                                                    price: result.price,
                                                },
                                            }).
                                            fail(FAIL).
                                            always(n);
                                        } else {
                                            n();
                                        }
                                    });
                                })(flat);
                            }
                        },
                    });
                });
            }
        }, 100);

        moduleLoaded("custom", this);
    },

    billingMagic: function (prefix) {
        loadingStart();
        QUERYID("custom", "custom", "activateApartment", {
            "idBilling": $("#" + prefix + "_cf_billing_id").val(),
            "price": $("#" + prefix + "_cf_price").val(),
            "apartmentNumber": $("#" + prefix + "flat").val(),
            "apartmentId": $("#" + prefix + "flatId").val(),
        }, true).done(result => {
            console.log(result);
            if (result && result.custom) {
                message("Получен идентификатор абонента: " + result.custom, "Идентификатор получен", 15);
                $("#" + prefix + "contract").val(result.custom);
            }
        }).
        fail(FAIL).
        always(loadingDone);
    }
}).init();
