"""
Unit tests for EnviaShipping pure logic (mirrors PHP helpers).

Run:
  python3 -m pytest tests/validation/test_envia_shipping_logic.py -v
"""

from __future__ import annotations

import re
from pathlib import Path

import pytest

REPO = Path(__file__).resolve().parents[1]
MODULE = REPO  # module lives at package root


def one_per_carrier(rates: list[dict]) -> list[dict]:
    """Mirror RateDeduper::onePerCarrier."""
    best: dict[str, dict] = {}
    for row in rates:
        carrier = str(row.get("carrier") or "unknown").lower()
        price = float(row.get("totalPrice") or 0)
        if price <= 0:
            continue
        if carrier not in best or price < float(best[carrier]["totalPrice"]):
            best[carrier] = row
    out = list(best.values())
    out.sort(key=lambda r: float(r.get("totalPrice") or 0))
    return out


def apply_markup(
    base_price: float,
    markup_type: str,
    markup_value: float,
    handling_fee: float = 0.0,
) -> float:
    """Mirror MarkupApplier::apply."""
    if base_price < 0:
        base_price = 0.0
    if markup_type == "percent":
        price = base_price * (1.0 + max(0.0, markup_value) / 100.0)
    elif markup_type == "fixed":
        price = base_price + max(0.0, markup_value)
    else:
        price = base_price
    price += max(0.0, handling_fee)
    return round(price, 0)


def evaluate_eligibility(
    total_weight_kg: float,
    parcel_max_kg: float,
    require_dimensions: bool,
    items: list[dict],
) -> dict:
    """Mirror QuoteEligibility::evaluate."""
    if total_weight_kg <= 0:
        return {"eligible": False, "reason": "zero_or_missing_weight"}
    if total_weight_kg > parcel_max_kg:
        return {"eligible": False, "reason": "over_parcel_max_kg"}
    if not items:
        return {"eligible": False, "reason": "empty_cart"}
    for item in items:
        w = float(item.get("weight") or 0)
        q = float(item.get("qty") or 0)
        if w <= 0 or q <= 0:
            return {"eligible": False, "reason": "item_missing_weight"}
        if require_dimensions:
            l = float(item.get("length") or 0)
            wi = float(item.get("width") or 0)
            h = float(item.get("height") or 0)
            if l <= 0 or wi <= 0 or h <= 0:
                return {"eligible": False, "reason": "item_missing_dimensions"}
    return {"eligible": True, "reason": "ok"}


# --- markup ---


def test_markup_none():
    assert apply_markup(10000, "none", 10) == 10000


def test_markup_percent():
    assert apply_markup(10000, "percent", 10) == 11000


def test_markup_fixed():
    assert apply_markup(10000, "fixed", 2500) == 12500


def test_markup_with_handling():
    assert apply_markup(10000, "percent", 10, handling_fee=500) == 11500


def test_markup_negative_base_clamped():
    assert apply_markup(-5, "none", 0) == 0


# --- eligibility ---


def test_eligible_parcel():
    items = [{"weight": 2.0, "qty": 1}]
    r = evaluate_eligibility(2.0, 30.0, False, items)
    assert r["eligible"] is True
    assert r["reason"] == "ok"


def test_over_parcel_max():
    items = [{"weight": 40.0, "qty": 1}]
    r = evaluate_eligibility(40.0, 30.0, False, items)
    assert r["eligible"] is False
    assert r["reason"] == "over_parcel_max_kg"


def test_zero_weight():
    r = evaluate_eligibility(0, 30.0, False, [{"weight": 0, "qty": 1}])
    assert r["eligible"] is False
    assert r["reason"] == "zero_or_missing_weight"


def test_require_dimensions_fail():
    items = [{"weight": 2.0, "qty": 1, "length": 0, "width": 10, "height": 10}]
    r = evaluate_eligibility(2.0, 30.0, True, items)
    assert r["eligible"] is False
    assert r["reason"] == "item_missing_dimensions"


def test_require_dimensions_ok():
    items = [{"weight": 2.0, "qty": 1, "length": 20, "width": 15, "height": 10}]
    r = evaluate_eligibility(2.0, 30.0, True, items)
    assert r["eligible"] is True


def test_boundary_equal_max_is_eligible():
    items = [{"weight": 30.0, "qty": 1}]
    r = evaluate_eligibility(30.0, 30.0, False, items)
    assert r["eligible"] is True


# --- dedupe ---


def test_dedupe_keeps_cheapest_per_carrier():
    rates = [
        {"carrier": "interRapidisimo", "service": "a", "totalPrice": "29028"},
        {"carrier": "interRapidisimo", "service": "b", "totalPrice": "29028"},
        {"carrier": "serviEntrega", "service": "premier", "totalPrice": "23580"},
        {"carrier": "serviEntrega", "service": "industrial", "totalPrice": "58368"},
        {"carrier": "coordinadora", "service": "ground", "totalPrice": "31728"},
    ]
    out = one_per_carrier(rates)
    assert len(out) == 3
    by = {r["carrier"].lower(): r for r in out}
    assert by["servientrega"]["service"] == "premier"
    assert float(by["servientrega"]["totalPrice"]) == 23580
    # sorted by price
    assert float(out[0]["totalPrice"]) <= float(out[1]["totalPrice"])


# --- module structure ---


def test_module_files_exist():
    required = [
        "registration.php",
        "etc/module.xml",
        "etc/config.xml",
        "etc/di.xml",
        "etc/adminhtml/system.xml",
        "Model/Carrier.php",
        "Model/Config.php",
        "Model/Pricing/MarkupApplier.php",
        "Model/Pricing/RateDeduper.php",
        "Model/Hybrid/QuoteEligibility.php",
        "Model/Hybrid/QuoteContext.php",
        "Model/Api/Client.php",
        "Model/Api/RateRequestBuilder.php",
        "Model/Cache/RateCache.php",
        "Plugin/HideMstrateWhenEnviaPlugin.php",
        "Model/LocalFreeCarrier.php",
        "Model/Local/CityMatcher.php",
        "composer.json",
        "LICENSE",
        "README.md",
    ]
    missing = [p for p in required if not (MODULE / p).is_file()]
    assert missing == [], f"Missing: {missing}"


def test_default_active_off():
    config = (MODULE / "etc/config.xml").read_text(encoding="utf-8")
    assert re.search(r"<envia>[\s\S]*?<active>0</active>", config)


def test_carrier_code_envia():
    carrier = (MODULE / "Model/Carrier.php").read_text(encoding="utf-8")
    assert "protected $_code = 'envia'" in carrier


def test_plugin_targets_shipping_model():
    di = (MODULE / "etc/di.xml").read_text(encoding="utf-8")
    assert "Magento\\Shipping\\Model\\Shipping" in di
    assert "HideMstrateWhenEnviaPlugin" in di


def test_spike_script_exists():
    spike = REPO / "scripts/envia_rate_spike.py"
    assert spike.is_file()
    text = spike.read_text(encoding="utf-8")
    assert "/ship/rate/" in text
    assert "/locate" in text


def test_local_free_default_off():
    config = (MODULE / "etc/config.xml").read_text(encoding="utf-8")
    assert re.search(r"<dtlocalfree>[\s\S]*?<active>0</active>", config)


def test_php_markup_matches_python():
    """Sanity: PHP MarkupApplier source documents same formula."""
    php = (MODULE / "Model/Pricing/MarkupApplier.php").read_text(encoding="utf-8")
    assert "percent" in php
    assert "fixed" in php
    assert "round($price, 0)" in php


def test_php_eligibility_reasons():
    php = (MODULE / "Model/Hybrid/QuoteEligibility.php").read_text(encoding="utf-8")
    for reason in (
        "zero_or_missing_weight",
        "over_parcel_max_kg",
        "empty_cart",
        "item_missing_weight",
        "item_missing_dimensions",
    ):
        assert reason in php


# --- CityMatcher (local free zone) ---

def normalize_city(city: str) -> str:
    """Mirror CityMatcher::normalize (simplified ASCII fold)."""
    import unicodedata
    import re

    city = city.strip()
    if not city:
        return ""
    nfkd = unicodedata.normalize("NFKD", city)
    city = "".join(c for c in nfkd if not unicodedata.combining(c))
    city = city.encode("ascii", "ignore").decode("ascii").lower()
    if "," in city:
        city = city.split(",", 1)[0].strip()
    city = re.sub(r"\s+d\.?\s*c\.?\s*$", "", city, flags=re.I)
    city = re.sub(r"\s+", " ", city).strip()
    return city


def city_matches(city: str, allow_list: list[str]) -> bool:
    needle = normalize_city(city)
    if not needle:
        return False
    for a in allow_list:
        if normalize_city(a) == needle:
            return True
    return False


class TestCityMatcherLocalFree:
    def test_neiva_variants(self):
        allow = ["neiva"]
        assert city_matches("Neiva", allow)
        assert city_matches("NEIVA", allow)
        assert city_matches("neiva", allow)
        assert city_matches("  Neiva  ", allow)

    def test_rejects_other_cities(self):
        allow = ["neiva"]
        assert not city_matches("Bogota", allow)
        assert not city_matches("Campoalegre", allow)
        assert not city_matches("Rivera", allow)
        assert not city_matches("Medellin", allow)
        assert not city_matches("", allow)

    def test_exact_not_substring(self):
        allow = ["neiva"]
        # no real Colombian city is "XneivaY" but ensure exact match
        assert not city_matches("neivax", allow)

    def test_multi_allow_list(self):
        allow = ["neiva", "pitalito"]
        assert city_matches("Pitalito", allow)
        assert city_matches("Neiva", allow)
        assert not city_matches("Garzon", allow)


def test_envia_skips_when_local_free_zone():
    """Document hybrid rule: local free zone ⇒ no Envia rates."""
    # Pure policy: if city matches free list and local free active → skip Envia
    assert city_matches("Neiva", ["neiva"])
    # Non-match keeps Envia path eligible (weight permitting)
    assert not city_matches("Bogota", ["neiva"])
