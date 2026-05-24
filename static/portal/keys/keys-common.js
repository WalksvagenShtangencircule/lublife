/**
 * Общий мост WebView SmartYard: токен, индикатор загрузки, NFC (Chrome).
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

    window.keysPortalBaseUrl = function () {
        try {
            var base = document.baseURI || document.location.href || "";
            var u = new URL(base, base);
            var path = u.pathname || "/";
            if (path.indexOf("/portal/keys") >= 0) {
                if (!path.endsWith("/")) {
                    path = path.replace(/\/[^/]*$/, "/");
                }
                return u.origin + path;
            }
            return u.origin + "/static/portal/keys/";
        } catch (e) {
            return "";
        }
    };

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

    window.normalizeRfidFromNfcSerial = function (serial) {
        var k = String(serial || "").toUpperCase().replace(/[^0-9A-F]/g, "");
        if (k.length === 14) {
            k = k[12] + k[13] + k[10] + k[11] + k[8] + k[9] + k[6] + k[7] + k[4] + k[5] + k[2] + k[3] + k[0] + k[1];
        }
        if (k.length === 16) {
            k = "000000" + k.substr(6, 8);
        } else if (k.length === 8) {
            k = "000000" + k[6] + k[7] + k[4] + k[5] + k[2] + k[3] + k[0] + k[1];
        }
        if (k.length === 14) {
            return k;
        }
        return normalizeRfidJoinFlat(k);
    };

    window.isWebNfcAvailable = function () {
        return typeof window.NDEFReader !== "undefined";
    };

    window.buildChromeIntentUrl = function (httpsUrl) {
        var u = new URL(httpsUrl);
        var intentPath = u.host + u.pathname + u.search;
        var fallback = encodeURIComponent(u.toString());
        return "intent://" + intentPath + "#Intent;scheme=https;package=com.android.chrome;S.browser_fallback_url=" + fallback + ";end";
    };

    window.buildNfcScanPageUrl = function (flatId, comment) {
        var nFlatId = parseInt(flatId, 10);
        if (!Number.isFinite(nFlatId) || nFlatId <= 0) {
            return null;
        }
        var token = bearerToken();
        if (!token) {
            return null;
        }
        var base = keysPortalBaseUrl();
        if (!base) {
            return null;
        }
        var url = new URL("nfc-scan.html", base);
        url.searchParams.set("token", token);
        url.searchParams.set("flatId", String(nFlatId));
        if (comment) {
            url.searchParams.set("comment", String(comment).slice(0, 128));
        }
        return url.toString();
    };

    /**
     * Варианты ссылок для открытия nfc-scan (видимые <a target="_blank"> в WebView).
     * @returns {{pageUrl:string,chromeIntent:string,externalUrl:string}|null}
     */
    window.getNfcScanOpenOptions = function (flatId, comment) {
        var pageUrl = buildNfcScanPageUrl(flatId, comment);
        if (!pageUrl) {
            return null;
        }
        var ext = new URL(pageUrl);
        ext.hash = "smart-yard-external";
        return {
            pageUrl: pageUrl,
            chromeIntent: buildChromeIntentUrl(pageUrl),
            externalUrl: ext.toString()
        };
    };

    /**
     * Подставить href в ссылки открытия NFC.
     * @returns {boolean}
     */
    window.applyNfcScanLinks = function (flatId, comment, chromeLinkEl, browserLinkEl, intentLinkEl) {
        var opts = getNfcScanOpenOptions(flatId, comment);
        if (!opts) {
            return false;
        }
        /* Основная: https#smart-yard-external — стабильно открывается из WebView. */
        if (chromeLinkEl) {
            chromeLinkEl.href = opts.externalUrl;
        }
        if (browserLinkEl) {
            browserLinkEl.href = opts.externalUrl;
        }
        if (intentLinkEl) {
            intentLinkEl.href = opts.externalUrl;
        }
        return true;
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
