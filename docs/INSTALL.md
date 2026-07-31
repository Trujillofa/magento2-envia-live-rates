# Install guide (quick)

## 1. Place the module

**app/code**

```bash
mkdir -p app/code/DepositoTrujillo
git clone https://github.com/<YOUR_ORG>/magento2-envia-live-rates.git \
  app/code/DepositoTrujillo/EnviaShipping
```

Or Composer VCS:

```bash
composer config repositories.envia-shipping vcs https://github.com/<YOUR_ORG>/magento2-envia-live-rates.git
composer require depositotrujillo/module-envia-shipping:dev-main
```

## 2. Enable

```bash
bin/magento module:enable DepositoTrujillo_EnviaShipping
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:clean config full_page layout block_html
```

## 3. Configure (admin)

1. **Delivery Methods → Envia.com (Live Rates)**  
2. Paste API token, set origin (CO city + 2-letter department + postal).  
3. Set carriers list and parcel max kg.  
4. Enable **only after** a sandbox or prod rate spike works.  

Optional: enable city-scoped free carrier and list cities (e.g. `neiva`).

## 4. Smoke

```bash
export ENVIA_TOKEN=…
python3 scripts/envia_rate_spike.py --prod
```

Checkout: light cart → destination city → expect Envia methods.

## 5. Production tips

- Product weights must be correct (kg).  
- Do not use Magento core Free Shipping for city-limited free delivery.  
- Keep a table-rate/freight carrier for carts over `parcel_max_kg`.  
- Prefer `cache:clean` over `cache:flush` on constrained hosts.  
