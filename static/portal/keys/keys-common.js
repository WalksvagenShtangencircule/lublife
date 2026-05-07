/**
 * Общий мост WebView SmartYard: токен, индикатор загрузки.
 */
(function () {
    var ua = (window.navigator && window.navigator.userAgent) ? window.navigator.userAgent.toLowerCase() : "";
    window._keysIsAndroid = ua.indexOf("android") >= 0;

    if (typeof window.bearerToken !== "function" && typeof Android !== "undefined" && Android && typeof Android.bearerToken === "function") {
        window.bearerToken = function () {
            return Android.bearerToken();
        };
    }

    if (typeof window.bearerToken !== "function") {
        window.bearerToken = function () {
            try {
                return new URL(document.location.href).searchParams.get("token") || "";
            } catch (e) {
                return "";
            }
        };
    }

    window.postLoadingStarted = function () {
        if (window._keysIsAndroid && typeof Android !== "undefined" && Android.postLoadingStarted) {
            Android.postLoadingStarted();
        } else if (window.webkit && window.webkit.messageHandlers && window.webkit.messageHandlers.loadingStartedHandler) {
            window.webkit.messageHandlers.loadingStartedHandler.postMessage({ loading: "started" });
        }
    };

    window.postLoadingFinished = function () {
        if (window._keysIsAndroid && typeof Android !== "undefined" && Android.postLoadingFinished) {
            Android.postLoadingFinished();
        } else if (window.webkit && window.webkit.messageHandlers && window.webkit.messageHandlers.loadingFinishedHandler) {
            window.webkit.messageHandlers.loadingFinishedHandler.postMessage({ loading: "finished" });
        }
    };

    /**
     * RFID для «Ключ на старт»: как в RBT — ровно 14 hex, слева нули.
     * Ввод: только 0–9, A–F (пробелы и прочее отбрасываются), 1–14 значащих hex-цифр.
     * @returns {string|null}
     */
    window.normalizeRfidJoinFlat = function (raw) {
        var x = String(raw || "").replace(/[^0-9A-Fa-f]/g, "").toUpperCase();
        if (x.length < 1 || x.length > 14) {
            return null;
        }
        while (x.length < 14) {
            x = "0" + x;
        }
        return x;
    };

    window.keysApiFetch = function (path, bodyObj) {
        var token = window.bearerToken();
        if (!token) {
            return Promise.reject(new Error("Нет токена авторизации"));
        }
        return fetch(path, {
            method: "POST",
            headers: {
                "Authorization": "Bearer " + token,
                "Content-Type": "application/json",
                "Accept": "application/json"
            },
            body: JSON.stringify(bodyObj || {})
        }).then(function (res) {
            return res.json().then(function (j) {
                return { httpStatus: res.status, json: j };
            });
        });
    };
})();
