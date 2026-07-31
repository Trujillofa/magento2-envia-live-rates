# Design notes

## Why custom Magento carrier?

Envia’s “live checkout rates” integration list does not include Magento 2.  
The Shipping API (`/locate`, `/ship/rate/`) is public and sufficient to build a Magento `AbstractCarrier`.

## Hybrid strategy

| Cart | Behavior |
|------|----------|
| Weight ≤ `parcel_max_kg` | Call Envia; if rates exist, optionally hide table-rate carrier |
| Weight > max, missing weight, locate fail, API fail | No Envia methods → table rates / other carriers remain |
| Optional free cities | `dtlocalfree` $0; Envia skipped for those destinations |

**Do not** replace freight table rates with parcel APIs for multi-ton carts. Envia package carriers enforce per-package weight caps (order of ~100–500 kg).

## Colombia addressing

- Envia `city` for CO = DANE municipal id (often 8 digits)  
- Module resolves names through `/locate` with department state codes  
- Magento may send `CO-SAN`, `Santander`, etc. — map carefully to Envia’s codes (`SN` for Santander, `QU` for Quindío, `CU` for Cauca, …)

## Local free shipping

Magento core Free Shipping has no city filter (title alone is cosmetic).  
`dtlocalfree` matches normalized destination city against a configurable allow-list.

## Security

- Token stored via Magento encrypted backend  
- Module ships with carriers disabled  
- Never commit API tokens  

## Cache

Rate results cached by origin/destination/weight/carriers/packages TTL.  
Admin config changes need `cache:clean config`.
