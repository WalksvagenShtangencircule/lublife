({
    meta: [],

    init: function () {
        if (AVAIL("contacts", "contacts")) {
            leftSide("fas fa-fw fa-address-card", i18n("contacts.contacts"), "?#contacts", "contacts");
        }
        moduleLoaded("contacts", this);
    },

    renderContects: function (params) {

    },

    route: function (params) {
        document.title = i18n("windowTitle") + " :: " + i18n("contacts.contacts");

        $("#altForm").hide();
        subTop();

        modules.contacts.renderContacts(params);
    },
}).init();