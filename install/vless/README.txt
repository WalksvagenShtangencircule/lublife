VLESS/Reality нельзя указать в PHP напрямую: используется Xray с локальным SOCKS5.

  Сервис: systemctl status xray-telegram
  Конфиг:  /opt/xray/config.json (права 600)
  SOCKS:   127.0.0.1:10808 → diagnostics.telegram.proxy.static_proxies

После смены узла отредактируйте /opt/xray/config.json и: systemctl restart xray-telegram
