# Magento 2 — Envia Live Shipping Rates

**Language:** **English** · [Español](README.es.md)

**Live multi-carrier checkout rates** for Magento 2 using the [Envia.com Shipping API](https://docs.envia.com/docs/ecommerce-checkout).

Envia’s official “Cotización Automática / Live checkout rates” product supports Shopify, WooCommerce, Wix, etc. — **not Magento 2**. This module implements the same capability as a **custom Magento shipping carrier**.

Battle-tested on a production Colombia ferretería store ([depositotrujillo.co](https://www.depositotrujillo.co)): hybrid with table rates, city-scoped free shipping, DANE city resolution, and parcel weight caps.

| | |
|---|---|
| **Module** | `DepositoTrujillo_EnviaShipping` |
| **Magento** | 2.4.x (PHP ≥ 8.1) |
| **License** | MIT |
| **Default** | Carrier **disabled** until you set token + origin |

---

## Features

- **Live rates** at checkout via `POST /ship/rate/` (multi-carrier: e.g. Servientrega, InterRapidísimo, Coordinadora)
- **Colombia DANE codes** via Envia `POST /locate` (city name + department → municipal code)
- **Hybrid with table rates**: optional plugin hides Mirasvit `mstrate` (or any carrier code you adapt) when Envia returns rates
- **Parcel max weight** (default 100 kg): over that weight Envia is skipped so freight/table rates can take over
- **Markup**: percent or fixed + handling fee
- **One method per carrier** (dedupe cheapest service)
- **Optional city-scoped free shipping** (`dtlocalfree`) — *not* Magento core Free Shipping (which cannot filter by city)
- **Sandbox / production** API toggle
- Encrypted API token storage
- Rate response cache (TTL configurable)
- Spanish (`es_CO`) admin labels

### Not included (by design)

- Envia dashboard order sync / Ecommerce Pro OAuth  
- Auto label generation (`/ship/generate/`)  
- Tracking write-back  

Those are separate fulfillment projects. This module is **checkout quotation only**.

---

## Architecture

```
Cart weight ≤ parcel_max_kg?
        │
        ├─ No  → skip Envia → table rate / freight (e.g. mstrate)
        │
        └─ Yes → locate DANE → POST /ship/rate/ per carrier
                    │
                    ├─ ≥1 rate → show Envia methods; hide mstrate (optional plugin)
                    └─ fail / empty → mstrate (or backup flat rate)
```

Optional: destination city on free-city list → `dtlocalfree` @ $0 and Envia skipped.

---

## Requirements

- Magento Open Source / Adobe Commerce 2.4.x  
- PHP 8.1+  
- Envia account + **API token** ([envia.com](https://envia.com) / docs)  
- Outbound HTTPS to `api.envia.com` or `api-test.envia.com`  
- Product **weights** set on catalog (kg) for accurate quotes  

Optional hybrid: Mirasvit Shipping Table (`mstrate`) or any table-rate carrier. The hide-plugin targets carrier code `mstrate` by default.

---

## Install

### Composer (recommended once published on Packagist / VCS)

```bash
composer require depositotrujillo/module-envia-shipping
bin/magento module:enable DepositoTrujillo_EnviaShipping
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:clean config full_page layout block_html
```

### Manual (app/code)

```bash
# from Magento root
mkdir -p app/code/DepositoTrujillo
cp -a /path/to/magento2-envia-live-rates app/code/DepositoTrujillo/EnviaShipping

bin/magento module:enable DepositoTrujillo_EnviaShipping
bin/magento setup:upgrade
bin/magento setup:di:compile
# Prefer cache:clean over cache:flush on production shared hosts
bin/magento cache:clean config full_page layout block_html
```

### Composer path repository (local path)

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "../magento2-envia-live-rates"
    }
  ],
  "require": {
    "depositotrujillo/module-envia-shipping": "*"
  }
}
```

---

## Configuration

**Stores → Configuration → Sales → Delivery Methods**

### Envia.com (Live Rates)

| Setting | Notes |
|---------|--------|
| **Enabled** | Leave **No** until token + origin are set |
| API token | Encrypted; from Envia account |
| Sandbox | Yes = `api-test.envia.com` |
| Carriers list | Comma-separated Envia slugs, e.g. `servientrega,interrapidisimo,coordinadora` |
| Parcel max kg | Above this, Envia is skipped (default **100**) |
| Origin | Street, city, **2-letter CO department** (e.g. `HU`), postal code |
| Markup | None / percent / fixed COP |
| One method per carrier | Recommended **Yes** |
| Hide mstrate when Envia succeeds | Hybrid UX |

### Envío gratis (city-scoped) / Local free

Optional second carrier `dtlocalfree`:

- Enable only if you want **free home delivery for listed cities** (e.g. `neiva`)
- Magento core Free Shipping **cannot** filter by city — do not use it for “free only in X”

---

## Colombia notes

1. Envia expects **DANE municipal codes** in the `city` field for CO rates. The module calls `/locate` with human city + department.  
2. Magento regions may be `CO-CUN`, `CO-ANT`, full names, etc. `RateRequestBuilder` maps them to Envia state codes (e.g. Santander → `SN`, not `ST`).  
3. Empty postcode on CO checkout is allowed; a stable fallback is derived from DANE.  
4. Use **ASCII** package content strings if you see empty rate responses (UTF-8 edge cases on some paths).  
5. API client sends a real **User-Agent** (Cloudflare may block empty UA).

See `docs/colombia-department-codes.md` for the Envia state map validated in production.

---

## Hybrid with table rates (mstrate)

Plugins:

- `HideMstrateWhenEnviaPlugin` — after collectRates, drop methods with carrier code `mstrate` if any `envia` rate exists  
- `HideMstrateWhenLocalFreePlugin` — same when `dtlocalfree` is present  

If you use a different table-rate carrier code, change the string `'mstrate'` in those plugins (or open a PR for a config field).

**Freight:** parcel carriers often stop ~100–500 kg per package. Keep table/freight rates for heavy carts; do not set `parcel_max_kg` to multi-ton values expecting Envia to replace freight.

---

## Diagnostics scripts

Python 3, no Magento required (API only):

```bash
export ENVIA_TOKEN='your-token'

# Latency + sample prices
python3 scripts/envia_rate_spike.py --prod

# City coverage matrix (cities file format: city|region)
python3 scripts/envia_city_coverage.py --prod \
  --cities-file path/to/cities.pipe \
  -o coverage.json
```

---

## Tests

Logic tests (no Magento bootstrap):

```bash
python3 -m pytest tests/ -v
```

---

## Safety defaults

- `carriers/envia/active = 0`  
- `carriers/dtlocalfree/active = 0`  
- Sandbox API by default  
- Prefer `bin/magento cache:clean …` — avoid `cache:flush` on production if you materialize `var/view_preprocessed`  

---

## Production lessons (short)

Shipped and hardened on Magento 2.4.7 + shared hosting:

- Department code map must match **Envia** codes (not only ISO)  
- Parcel max ~100 kg was a good checkout range for multi-carrier parcel APIs  
- City-scoped free shipping must be a **custom carrier**, not core Free Shipping  
- Hybrid hide of table rates avoids confusing dual quotes  

More detail: `docs/DESIGN.md`

---

## Roadmap / not this repo

- [ ] Order → Envia label generate after payment  
- [ ] Ecommerce Pro Magento order sync  
- [ ] Configurable “hide carrier codes” list in admin  
- [ ] Multi-package / multi-box split for heavy carts  

Contributions welcome via PR.

---

## License

MIT — see [LICENSE](LICENSE).

## Credits

Built for [Depósito Trujillo](https://www.depositotrujillo.co) operations.  
Envia API © Envia.com — this project is **not** an official Envia product.
