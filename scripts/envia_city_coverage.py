#!/usr/bin/env python3
"""
Validate which Colombian cities Envia can quote from our origin (Neiva).

For each city+region:
  1. POST /locate  (city name + 2-letter department)
  2. If locate OK → POST /ship/rate/ (1 kg box, configured carriers)

Does not touch Magento. Needs ENVIA_TOKEN or --token.

Usage:
  export ENVIA_TOKEN="$(cat /tmp/envia_token_local.txt)"
  python3 scripts/diagnostics/envia_city_coverage.py \\
    --cities-file /tmp/envia-mstrate-all-cities.pipe \\
    --orders-file /tmp/envia-cities-orders.pipe \\
    -o reports/envia-city-coverage-2026-07-29.json
"""

from __future__ import annotations

import argparse
import csv
import json
import os
import re
import statistics
import sys
import time
import unicodedata
import urllib.error
import urllib.request
from concurrent.futures import ThreadPoolExecutor, as_completed
from pathlib import Path
from typing import Any

# Magento region name / ISO → Envia canonical state (mirrors RateRequestBuilder)
REGION_TO_ENVIA: dict[str, str] = {
    "ANTIOQUIA": "AN",
    "ATLANTICO": "AT",
    "ATLÁNTICO": "AT",
    "BOGOTA": "DC",
    "BOGOTÁ": "DC",
    "BOGOTA D.C.": "DC",
    "BOGOTA, D.C.": "DC",
    "DISTRITO CAPITAL": "DC",
    "CUNDINAMARCA": "CN",
    "VALLE DEL CAUCA": "VC",
    "VALLE": "VC",
    "SANTANDER": "SN",
    "NORTE DE SANTANDER": "NS",
    "BOLIVAR": "BL",
    "BOLÍVAR": "BL",
    "MAGDALENA": "MA",
    "CESAR": "CE",
    "CORDOBA": "CO",
    "CÓRDOBA": "CO",
    "NARINO": "NA",
    "NARIÑO": "NA",
    "CAUCA": "CU",
    "META": "ME",
    "RISARALDA": "RI",
    "CALDAS": "CL",
    "QUINDIO": "QU",
    "QUINDÍO": "QU",
    "BOYACA": "BY",
    "BOYACÁ": "BY",
    "CAQUETA": "CA",
    "CAQUETÁ": "CA",
    "PUTUMAYO": "PU",
    "CASANARE": "CS",
    "ARAUCA": "AR",
    "CHOCO": "CH",
    "CHOCÓ": "CH",
    "LA GUAJIRA": "LG",
    "GUAJIRA": "LG",
    "SUCRE": "SU",
    "HUILA": "HU",
    "TOLIMA": "TO",
    "GUAVIARE": "GA",
    "VICHADA": "VI",
    "VAUPES": "VA",
    "VAUPÉS": "VA",
    "GUAINIA": "GU",
    "GUAINÍA": "GU",
    "AMAZONAS": "AM",
    "SAN ANDRES": "SA",
    "SAN ANDRÉS": "SA",
    "ARCHIPIELAGO DE SAN ANDRES": "SA",
    # Envia canonical + Magento ISO suffixes
    "AN": "AN", "AT": "AT", "CN": "CN", "DC": "DC", "VC": "VC",
    "SN": "SN", "NS": "NS", "BL": "BL", "MA": "MA", "CE": "CE",
    "CO": "CO", "NA": "NA", "CU": "CU", "ME": "ME", "RI": "RI",
    "CL": "CL", "QU": "QU", "BY": "BY", "CA": "CA", "PU": "PU",
    "CS": "CS", "AR": "AR", "CH": "CH", "LG": "LG", "SU": "SU",
    "HU": "HU", "TO": "TO", "GA": "GA", "VA": "VA", "VI": "VI",
    "GU": "GU", "SA": "SA", "AM": "AM",
    # Legacy wrong codes → correct Envia
    "ST": "SN", "QD": "QU", "CQ": "CA", "GV": "GA",
    "VP": "VA", "VD": "VI", "GN": "GU",
}

# Capitals / hubs always included (even if missing from input files)
EXTRA_DESTINATIONS: list[dict[str, str]] = [
    {"city": "Bogota", "region": "Cundinamarca", "source": "capital"},
    {"city": "Medellin", "region": "Antioquia", "source": "capital"},
    {"city": "Cali", "region": "Valle del Cauca", "source": "capital"},
    {"city": "Barranquilla", "region": "Atlantico", "source": "capital"},
    {"city": "Cartagena", "region": "Bolivar", "source": "capital"},
    {"city": "Bucaramanga", "region": "Santander", "source": "capital"},
    {"city": "Cucuta", "region": "Norte de Santander", "source": "capital"},
    {"city": "Pereira", "region": "Risaralda", "source": "capital"},
    {"city": "Manizales", "region": "Caldas", "source": "capital"},
    {"city": "Armenia", "region": "Quindio", "source": "capital"},
    {"city": "Ibague", "region": "Tolima", "source": "capital"},
    {"city": "Neiva", "region": "Huila", "source": "capital"},
    {"city": "Villavicencio", "region": "Meta", "source": "capital"},
    {"city": "Pasto", "region": "Narino", "source": "capital"},
    {"city": "Popayan", "region": "Cauca", "source": "capital"},
    {"city": "Monteria", "region": "Cordoba", "source": "capital"},
    {"city": "Sincelejo", "region": "Sucre", "source": "capital"},
    {"city": "Valledupar", "region": "Cesar", "source": "capital"},
    {"city": "Santa Marta", "region": "Magdalena", "source": "capital"},
    {"city": "Riohacha", "region": "La Guajira", "source": "capital"},
    {"city": "Tunja", "region": "Boyaca", "source": "capital"},
    {"city": "Florencia", "region": "Caqueta", "source": "capital"},
    {"city": "Yopal", "region": "Casanare", "source": "capital"},
    {"city": "Arauca", "region": "Arauca", "source": "capital"},
    {"city": "Mocoa", "region": "Putumayo", "source": "capital"},
    {"city": "Quibdo", "region": "Choco", "source": "capital"},
    {"city": "San Jose Del Guaviare", "region": "Guaviare", "source": "capital"},
    {"city": "Leticia", "region": "Amazonas", "source": "capital"},
    {"city": "Pitalito", "region": "Huila", "source": "hub"},
    {"city": "Garzon", "region": "Huila", "source": "hub"},
    {"city": "Palmira", "region": "Valle del Cauca", "source": "hub"},
    {"city": "Bello", "region": "Antioquia", "source": "hub"},
    {"city": "Envigado", "region": "Antioquia", "source": "hub"},
    {"city": "Soacha", "region": "Cundinamarca", "source": "hub"},
    {"city": "Soledad", "region": "Atlantico", "source": "hub"},
    {"city": "Dosquebradas", "region": "Risaralda", "source": "hub"},
]


def strip_accents(s: str) -> str:
    nfkd = unicodedata.normalize("NFKD", s)
    return "".join(c for c in nfkd if not unicodedata.combining(c))


def ascii_safe(s: str) -> str:
    return strip_accents(s).encode("ascii", "ignore").decode("ascii")


def normalize_city(city: str) -> str:
    city = ascii_safe(city.strip())
    city = re.sub(r"\s*,\s*D\.?\s*C\.?\s*$", "", city, flags=re.I)
    city = re.sub(r"\s+D\.?\s*C\.?\s*$", "", city, flags=re.I)
    return city.strip()


def map_region(region: str) -> str | None:
    raw = region.strip()
    if not raw:
        return None
    key = strip_accents(raw).upper()
    key = re.sub(r"\s+", " ", key).strip()
    if key in REGION_TO_ENVIA:
        return REGION_TO_ENVIA[key]
    # try without accents on map keys
    for k, v in REGION_TO_ENVIA.items():
        if strip_accents(k).upper() == key:
            return v
    return None


def http_json(
    method: str,
    url: str,
    body: dict[str, Any] | None,
    token: str | None,
    timeout: float,
) -> tuple[int, Any, float]:
    data = None if body is None else json.dumps(body).encode("utf-8")
    headers = {
        "Content-Type": "application/json",
        "Accept": "application/json",
        "User-Agent": "DepositoTrujillo-EnviaCityCoverage/1.0 (+https://www.depositotrujillo.co)",
    }
    if token:
        headers["Authorization"] = f"Bearer {token}"
    req = urllib.request.Request(url, data=data, headers=headers, method=method)
    t0 = time.perf_counter()
    try:
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            raw = resp.read().decode("utf-8")
            elapsed = time.perf_counter() - t0
            return resp.status, json.loads(raw) if raw else {}, elapsed
    except urllib.error.HTTPError as e:
        elapsed = time.perf_counter() - t0
        try:
            err_body = e.read().decode("utf-8")
            parsed: Any = json.loads(err_body) if err_body else {"error": str(e)}
        except Exception:
            parsed = {"error": str(e)}
        return e.code, parsed, elapsed
    except Exception as e:
        elapsed = time.perf_counter() - t0
        return 0, {"error": str(e)}, elapsed


def locate(base: str, city: str, state: str, timeout: float) -> tuple[dict[str, str] | None, float, Any]:
    status, payload, elapsed = http_json(
        "POST",
        f"{base}/locate",
        {"city": city, "state": state, "country": "CO"},
        token=None,
        timeout=timeout,
    )
    if status == 200 and isinstance(payload, dict):
        if payload.get("city"):
            return (
                {
                    "city": str(payload["city"]),
                    "name": str(payload.get("name", city)),
                    "state": str(payload.get("state", state)),
                },
                elapsed,
                payload,
            )
        data = payload.get("data")
        if isinstance(data, dict) and data.get("city"):
            return (
                {
                    "city": str(data["city"]),
                    "name": str(data.get("name", city)),
                    "state": str(data.get("state", state)),
                },
                elapsed,
                payload,
            )
        if isinstance(data, list) and data:
            first = data[0]
            if isinstance(first, dict) and first.get("city"):
                return (
                    {
                        "city": str(first["city"]),
                        "name": str(first.get("name", city)),
                        "state": str(first.get("state", state)),
                    },
                    elapsed,
                    payload,
                )
    return None, elapsed, payload


def rate_one(
    base: str,
    token: str,
    origin: dict[str, str],
    destination: dict[str, str],
    carrier: str,
    weight: float,
    timeout: float,
) -> dict[str, Any]:
    body = {
        "origin": origin,
        "destination": destination,
        "packages": [
            {
                "type": "box",
                "content": "Coverage test",
                "amount": 1,
                "declaredValue": 50000,
                "lengthUnit": "CM",
                "weightUnit": "KG",
                "weight": weight,
                "dimensions": {"length": 30, "width": 20, "height": 15},
            }
        ],
        "shipment": {"type": 1, "carrier": carrier},
    }
    status, payload, elapsed = http_json("POST", f"{base}/ship/rate/", body, token, timeout)
    rates: list[dict[str, Any]] = []
    if status == 200 and isinstance(payload, dict):
        data = payload.get("data") or []
        if isinstance(data, list):
            for row in data:
                if isinstance(row, dict) and "totalPrice" in row:
                    rates.append(
                        {
                            "carrier": row.get("carrier", carrier),
                            "service": row.get("service"),
                            "price": row.get("totalPrice"),
                            "currency": row.get("currency"),
                            "eta": row.get("deliveryEstimate"),
                        }
                    )
    return {
        "carrier": carrier,
        "http_status": status,
        "elapsed_s": round(elapsed, 3),
        "n_rates": len(rates),
        "rates": rates,
        "error_snip": None
        if rates
        else (json.dumps(payload, ensure_ascii=False)[:240] if payload else "empty"),
    }


def fallback_postal(dane: str) -> str:
    known = {
        "11001000": "110111",
        "05001000": "050001",
        "76001000": "760001",
        "41001000": "410010",
        "08001000": "080001",
        "13001000": "130001",
    }
    if dane in known:
        return known[dane]
    digits = re.sub(r"\D", "", dane)
    if len(digits) >= 6:
        return digits[:6]
    return "110111"


def load_pipe_cities(path: Path, source: str) -> list[dict[str, Any]]:
    out: list[dict[str, Any]] = []
    if not path.exists():
        return out
    raw = path.read_bytes()
    text = raw.decode("utf-8", errors="replace")
    for line in text.splitlines():
        line = line.strip()
        if not line or line.lower().startswith("city") or "|" not in line:
            continue
        parts = line.split("|")
        if len(parts) < 2:
            continue
        city, region = parts[0].strip(), parts[1].strip()
        n = 1
        src = source
        if len(parts) >= 4:
            src = parts[2].strip() or source
            try:
                n = int(parts[3])
            except ValueError:
                n = 1
        if not city:
            continue
        out.append({"city": city, "region": region, "source": src, "order_count": n})
    return out


def dedupe_cities(rows: list[dict[str, Any]]) -> list[dict[str, Any]]:
    """Prefer orders > capital > hub > mstrate; keep highest order_count."""
    best: dict[tuple[str, str], dict[str, Any]] = {}
    prio = {"orders": 4, "capital": 3, "hub": 2, "mstrate": 1, "extra": 1}

    for r in rows:
        city_n = normalize_city(r["city"])
        region_n = strip_accents(r["region"]).upper().strip()
        key = (city_n.lower(), region_n)
        score = prio.get(r.get("source", "mstrate"), 0) * 10000 + int(r.get("order_count") or 0)
        prev = best.get(key)
        if prev is None or score > prev["_score"]:
            best[key] = {**r, "city_norm": city_n, "region_norm": region_n, "_score": score}
    return sorted(best.values(), key=lambda x: (-x["_score"], x["region_norm"], x["city_norm"]))


def validate_one(
    base: str,
    token: str,
    origin: dict[str, str],
    dest: dict[str, Any],
    carriers: list[str],
    weight: float,
    timeout: float,
    multi_carrier: bool,
) -> dict[str, Any]:
    city_raw = dest["city"]
    city = dest.get("city_norm") or normalize_city(city_raw)
    region = dest["region"]
    state = map_region(region)
    sources = dest.get("source", "mstrate")
    order_count = int(dest.get("order_count") or 0)

    row: dict[str, Any] = {
        "city": city_raw,
        "city_norm": city,
        "region": region,
        "state": state,
        "source": sources,
        "order_count": order_count,
        "ok": False,
        "phase": "init",
    }

    if not state:
        row["phase"] = "region_map"
        row["error"] = f"unknown region: {region}"
        return row

    if not city:
        row["phase"] = "city_empty"
        row["error"] = "empty city"
        return row

    located, le, raw = locate(base, city, state, timeout)
    row["locate_s"] = round(le, 3)
    if not located:
        # retry with original (accented) ascii-stripped differently
        if city_raw != city:
            located, le2, raw = locate(base, ascii_safe(city_raw), state, timeout)
            row["locate_s"] = round(le + le2, 3)
        if not located:
            row["phase"] = "locate"
            row["error"] = json.dumps(raw, ensure_ascii=False)[:300]
            return row

    row["dane"] = located["city"]
    row["envia_name"] = located.get("name")
    row["envia_state"] = located.get("state")

    destination = {
        "name": "Customer",
        "phone": "3000000000",
        "street": "Calle 10",
        "city": located["city"],
        "state": located["state"],
        "country": "CO",
        "postalCode": fallback_postal(located["city"]),
    }

    use_carriers = carriers if multi_carrier else carriers[:1]
    results: list[dict[str, Any]] = []
    t0 = time.perf_counter()
    with ThreadPoolExecutor(max_workers=min(4, len(use_carriers))) as ex:
        futs = [
            ex.submit(rate_one, base, token, origin, destination, c, weight, timeout)
            for c in use_carriers
        ]
        for fut in as_completed(futs):
            results.append(fut.result())
    wall = time.perf_counter() - t0

    prices = []
    for r in results:
        for rate in r.get("rates") or []:
            prices.append(
                {
                    "carrier": rate.get("carrier"),
                    "service": rate.get("service"),
                    "price": rate.get("price"),
                    "eta": rate.get("eta"),
                }
            )

    ok = any(r.get("n_rates", 0) > 0 for r in results)
    row["phase"] = "rate"
    row["ok"] = ok
    row["rate_wall_s"] = round(wall, 3)
    row["n_rates"] = len(prices)
    row["carriers_ok"] = sorted({str(p["carrier"]) for p in prices if p.get("carrier")})
    row["cheapest"] = min((float(p["price"]) for p in prices if p.get("price") is not None), default=None)
    row["prices"] = prices
    row["per_carrier"] = [
        {
            "carrier": r["carrier"],
            "http_status": r["http_status"],
            "n_rates": r["n_rates"],
            "elapsed_s": r["elapsed_s"],
            "error_snip": r.get("error_snip") if r["n_rates"] == 0 else None,
        }
        for r in results
    ]
    if not ok:
        row["error"] = "; ".join(
            f"{r['carrier']}:{r.get('error_snip')}" for r in results if r["n_rates"] == 0
        )[:400]
    return row


def write_markdown(summary: dict[str, Any], path: Path) -> None:
    rows: list[dict[str, Any]] = summary["rows"]
    ok_rows = [r for r in rows if r.get("ok")]
    fail_locate = [r for r in rows if r.get("phase") == "locate"]
    fail_rate = [r for r in rows if r.get("phase") == "rate" and not r.get("ok")]
    fail_region = [r for r in rows if r.get("phase") == "region_map"]
    order_rows = [r for r in rows if r.get("source") == "orders"]
    order_ok = [r for r in order_rows if r.get("ok")]

    lines = [
        f"# Envia city coverage — {summary.get('date', '')}",
        "",
        "## Scope",
        "",
        f"- Origin: **{summary.get('origin_label')}** (DANE `{summary.get('origin_dane')}`)",
        f"- API: `{summary.get('base_url')}`",
        f"- Carriers: {', '.join(summary.get('carriers') or [])}",
        f"- Package: **{summary.get('weight_kg')} kg** box 30×20×15 cm",
        f"- Cities tested: **{summary.get('n_cities')}**",
        "",
        "## Summary",
        "",
        f"| Metric | Count |",
        f"|--------|------:|",
        f"| **OK (locate + ≥1 rate)** | **{len(ok_rows)}** |",
        f"| Locate fail | {len(fail_locate)} |",
        f"| Rate fail (located, no quote) | {len(fail_rate)} |",
        f"| Unknown region map | {len(fail_region)} |",
        f"| Order destinations OK | {len(order_ok)} / {len(order_rows)} |",
        f"| Capitals/hubs OK | "
        f"{sum(1 for r in rows if r.get('source') in ('capital','hub') and r.get('ok'))} / "
        f"{sum(1 for r in rows if r.get('source') in ('capital','hub'))} |",
        "",
        f"Coverage rate: **{100.0 * len(ok_rows) / max(1, len(rows)):.1f}%**",
        "",
        "## Order destinations (real demand)",
        "",
        "| City | Region | Orders | Status | Cheapest COP | Carriers |",
        "|------|--------|-------:|--------|-------------:|----------|",
    ]
    for r in sorted(order_rows, key=lambda x: (-int(x.get("order_count") or 0), x.get("city", ""))):
        st = "OK" if r.get("ok") else f"FAIL ({r.get('phase')})"
        cheap = r.get("cheapest")
        cheap_s = f"{cheap:,.0f}" if isinstance(cheap, (int, float)) else "—"
        carriers = ", ".join(r.get("carriers_ok") or []) or "—"
        lines.append(
            f"| {r.get('city')} | {r.get('region')} | {r.get('order_count', 0)} | {st} | {cheap_s} | {carriers} |"
        )

    lines += [
        "",
        "## Capitals & hubs",
        "",
        "| City | Region | Status | Cheapest COP | Carriers |",
        "|------|--------|--------|-------------:|----------|",
    ]
    for r in sorted(
        [x for x in rows if x.get("source") in ("capital", "hub")],
        key=lambda x: (x.get("region", ""), x.get("city", "")),
    ):
        st = "OK" if r.get("ok") else f"FAIL ({r.get('phase')})"
        cheap = r.get("cheapest")
        cheap_s = f"{cheap:,.0f}" if isinstance(cheap, (int, float)) else "—"
        carriers = ", ".join(r.get("carriers_ok") or []) or "—"
        lines.append(
            f"| {r.get('city')} | {r.get('region')} | {st} | {cheap_s} | {carriers} |"
        )

    lines += [
        "",
        "## Failures (locate / rate / region)",
        "",
    ]
    fails = [r for r in rows if not r.get("ok")]
    if not fails:
        lines.append("_None — all tested cities returned at least one Envia rate._")
    else:
        lines += [
            "| City | Region | Phase | Error (snip) |",
            "|------|--------|-------|--------------|",
        ]
        for r in sorted(fails, key=lambda x: (x.get("phase", ""), x.get("region", ""), x.get("city", ""))):
            err = (r.get("error") or "")[:120].replace("|", "/")
            lines.append(
                f"| {r.get('city')} | {r.get('region')} | {r.get('phase')} | {err} |"
            )

    # Per-region rollup
    by_reg: dict[str, dict[str, int]] = {}
    for r in rows:
        reg = r.get("region") or "?"
        by_reg.setdefault(reg, {"ok": 0, "fail": 0, "total": 0})
        by_reg[reg]["total"] += 1
        if r.get("ok"):
            by_reg[reg]["ok"] += 1
        else:
            by_reg[reg]["fail"] += 1

    lines += [
        "",
        "## By department (region)",
        "",
        "| Region | OK | Fail | Total | % OK |",
        "|--------|---:|-----:|------:|-----:|",
    ]
    for reg in sorted(by_reg.keys()):
        s = by_reg[reg]
        pct = 100.0 * s["ok"] / max(1, s["total"])
        lines.append(f"| {reg} | {s['ok']} | {s['fail']} | {s['total']} | {pct:.0f}% |")

    lines += [
        "",
        "## Notes",
        "",
        "- OK means Envia `/locate` resolved DANE + at least one carrier returned a `/ship/rate/` quote.",
        "- This mirrors Magento `DepositoTrujillo_EnviaShipping` (empty postcode → DANE-derived postal).",
        "- Failures still fall back to **mstrate** in checkout (hybrid).",
        f"- Full JSON: `{summary.get('json_path', '')}`",
        "",
    ]
    path.write_text("\n".join(lines), encoding="utf-8")


def main() -> int:
    parser = argparse.ArgumentParser(description="Envia city coverage matrix")
    parser.add_argument("--token", default=os.environ.get("ENVIA_TOKEN", ""))
    parser.add_argument("--prod", action="store_true", default=True)
    parser.add_argument("--sandbox", action="store_true")
    parser.add_argument(
        "--carriers",
        default="servientrega,interrapidisimo,coordinadora",
    )
    parser.add_argument("--weight", type=float, default=1.0)
    parser.add_argument("--timeout", type=float, default=10.0)
    parser.add_argument("--workers", type=int, default=6, help="Parallel cities")
    parser.add_argument("--cities-file", type=Path, help="city|region pipe file (mstrate)")
    parser.add_argument("--orders-file", type=Path, help="city|region|orders|n pipe file")
    parser.add_argument("--limit", type=int, default=0, help="Cap cities (0=all)")
    parser.add_argument(
        "--multi-carrier-all",
        action="store_true",
        help="Rate all carriers for every city (slower). Default: multi only for orders/capitals",
    )
    parser.add_argument(
        "-o",
        "--output",
        type=Path,
        default=Path("reports/envia-city-coverage.json"),
    )
    parser.add_argument(
        "--md",
        type=Path,
        default=None,
        help="Markdown report path (default: same stem as -o)",
    )
    parser.add_argument(
        "--progress",
        type=Path,
        default=Path("/tmp/envia-city-coverage-progress.jsonl"),
    )
    args = parser.parse_args()

    token = args.token.strip()
    if not token and Path("/tmp/envia_token_local.txt").exists():
        token = Path("/tmp/envia_token_local.txt").read_text().strip()
    if not token:
        print("ERROR: set ENVIA_TOKEN or --token", file=sys.stderr)
        return 2

    base = "https://api-test.envia.com" if args.sandbox else "https://api.envia.com"
    carriers = [c.strip().lower() for c in args.carriers.split(",") if c.strip()]

    cities: list[dict[str, Any]] = []
    if args.orders_file:
        cities.extend(load_pipe_cities(args.orders_file, "orders"))
    if args.cities_file:
        cities.extend(load_pipe_cities(args.cities_file, "mstrate"))
    cities.extend(EXTRA_DESTINATIONS)

    cities = dedupe_cities(cities)
    if args.limit and args.limit > 0:
        # keep all orders/capitals first, then fill with mstrate
        priority = [c for c in cities if c.get("source") in ("orders", "capital", "hub")]
        rest = [c for c in cities if c.get("source") not in ("orders", "capital", "hub")]
        cities = (priority + rest)[: args.limit]

    print(f"Base: {base}")
    print(f"Carriers: {carriers}")
    print(f"Cities to test: {len(cities)}")

    # Origin Neiva
    origin_loc, le, raw = locate(base, "Neiva", "HU", args.timeout)
    if not origin_loc:
        print("ERROR: origin locate failed", raw)
        return 1
    origin = {
        "name": "Deposito Trujillo",
        "phone": "3168688799",
        "street": "calle 4 #2 20",
        "city": origin_loc["city"],
        "state": origin_loc["state"],
        "country": "CO",
        "postalCode": "410010",
    }
    print(f"Origin DANE={origin['city']} state={origin['state']} ({le:.2f}s)")

    if args.progress.exists():
        args.progress.unlink()

    results: list[dict[str, Any]] = []
    done = 0
    t_start = time.perf_counter()

    def needs_multi(d: dict[str, Any]) -> bool:
        if args.multi_carrier_all:
            return True
        return d.get("source") in ("orders", "capital", "hub")

    def work(d: dict[str, Any]) -> dict[str, Any]:
        return validate_one(
            base,
            token,
            origin,
            d,
            carriers,
            args.weight,
            args.timeout,
            multi_carrier=needs_multi(d),
        )

    with ThreadPoolExecutor(max_workers=max(1, args.workers)) as ex:
        futs = {ex.submit(work, d): d for d in cities}
        for fut in as_completed(futs):
            row = fut.result()
            results.append(row)
            done += 1
            with args.progress.open("a", encoding="utf-8") as pf:
                pf.write(json.dumps(row, ensure_ascii=False) + "\n")
            flag = "OK" if row.get("ok") else f"FAIL/{row.get('phase')}"
            cheap = row.get("cheapest")
            cheap_s = f"{cheap:.0f}" if isinstance(cheap, (int, float)) else "-"
            print(
                f"[{done}/{len(cities)}] {flag:12} {row.get('city_norm') or row.get('city')} "
                f"({row.get('region')}) cheap={cheap_s}",
                flush=True,
            )

    elapsed = time.perf_counter() - t_start
    results.sort(key=lambda r: (not r.get("ok"), r.get("region", ""), r.get("city", "")))

    summary = {
        "date": time.strftime("%Y-%m-%d"),
        "base_url": base,
        "carriers": carriers,
        "weight_kg": args.weight,
        "origin_label": "Neiva, HU",
        "origin_dane": origin["city"],
        "n_cities": len(results),
        "ok": sum(1 for r in results if r.get("ok")),
        "fail": sum(1 for r in results if not r.get("ok")),
        "locate_fail": sum(1 for r in results if r.get("phase") == "locate"),
        "rate_fail": sum(1 for r in results if r.get("phase") == "rate" and not r.get("ok")),
        "region_fail": sum(1 for r in results if r.get("phase") == "region_map"),
        "elapsed_s": round(elapsed, 1),
        "rows": results,
    }

    args.output.parent.mkdir(parents=True, exist_ok=True)
    with args.output.open("w", encoding="utf-8") as f:
        json.dump(summary, f, indent=2, ensure_ascii=False)
    summary["json_path"] = str(args.output)

    md_path = args.md or args.output.with_suffix(".md")
    write_markdown(summary, md_path)

    print("\n=== SUMMARY ===")
    print(
        json.dumps(
            {k: summary[k] for k in summary if k != "rows"},
            indent=2,
            ensure_ascii=False,
        )
    )
    print(f"JSON: {args.output}")
    print(f"MD:   {md_path}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
