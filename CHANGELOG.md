# Changelog

## 1.0.0 — 2026-07-31

Initial public release extracted from production Magento 2.4.7 operations toolkit.

- Live Envia multi-carrier rates (`envia` carrier)
- Colombia DANE locate + department code map
- Hybrid hide of `mstrate` when Envia quotes
- Parcel max weight, markup, one-method-per-carrier, rate cache
- Optional city-scoped free carrier (`dtlocalfree`), **default off**
- Admin system config (es_CO i18n)
- Python diagnostics: rate spike + city coverage
- Unit tests for pure logic (Python)

Production hardening lessons applied: Envia state codes (e.g. Santander `SN`), Cloudflare User-Agent, empty CO postcode fallback, ASCII content edge case.
