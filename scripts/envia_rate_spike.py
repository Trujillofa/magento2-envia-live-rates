#!/usr/bin/env python3
"""
Phase 0 spike: Envia live rate latency + sample prices (sandbox or prod).

Does NOT touch Magento. Needs ENVIA_TOKEN (or --token).

Usage:
  export ENVIA_TOKEN='…'
  python3 scripts/diagnostics/envia_rate_spike.py --sandbox
  python3 scripts/diagnostics/envia_rate_spike.py --sandbox --carriers servientrega,interrapidisimo

Outputs JSON summary: p50/p95 latency, rates per city/weight, locate errors.
"""

from __future__ import annotations

import argparse
import json
import os
import statistics
import sys
import time
import urllib.error
import urllib.request
from concurrent.futures import ThreadPoolExecutor, as_completed
from typing import Any

# Sample destinations (CO) — city name + 2-letter department + postal
DESTINATIONS: list[dict[str, str]] = [
    {"label": "Bogota", "city": "Bogota", "state": "CN", "postalCode": "110111"},
    {"label": "Medellin", "city": "Medellin", "state": "AN", "postalCode": "050001"},
    {"label": "Cali", "city": "Cali", "state": "VC", "postalCode": "760001"},
    {"label": "Neiva", "city": "Neiva", "state": "HU", "postalCode": "410001"},
    {"label": "Aracataca", "city": "Aracataca", "state": "MA", "postalCode": "472020"},
]

PACKAGES: list[dict[str, Any]] = [
    {"label": "1kg_small", "weight": 1.0, "dims": {"length": 20, "width": 15, "height": 10}},
    {"label": "10kg", "weight": 10.0, "dims": {"length": 40, "width": 30, "height": 25}},
    {"label": "25kg", "weight": 25.0, "dims": {"length": 50, "width": 40, "height": 40}},
]


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
        # Cloudflare blocks empty/python-urllib signatures (error 1010)
        "User-Agent": "DepositoTrujillo-EnviaSpike/1.0 (+https://www.depositotrujillo.co)",
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
    if status == 200 and isinstance(payload, dict) and payload.get("city"):
        return (
            {
                "city": str(payload["city"]),
                "name": str(payload.get("name", city)),
                "state": str(payload.get("state", state)),
            },
            elapsed,
            payload,
        )
    # nested
    if status == 200 and isinstance(payload, dict):
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
    return None, elapsed, payload


def rate_one(
    base: str,
    token: str,
    origin: dict[str, str],
    destination: dict[str, str],
    package: dict[str, Any],
    carrier: str,
    timeout: float,
) -> dict[str, Any]:
    body = {
        "origin": origin,
        "destination": destination,
        "packages": [
            {
                "type": "box",
                "content": "Spike test",
                "amount": 1,
                "declaredValue": 50000,
                "lengthUnit": "CM",
                "weightUnit": "KG",
                "weight": package["weight"],
                "dimensions": package["dims"],
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
                            "serviceDescription": row.get("serviceDescription"),
                            "totalPrice": row.get("totalPrice"),
                            "currency": row.get("currency"),
                            "deliveryEstimate": row.get("deliveryEstimate"),
                        }
                    )
    return {
        "carrier": carrier,
        "http_status": status,
        "elapsed_s": round(elapsed, 3),
        "rates": rates,
        "error": None if rates else payload,
    }


def main() -> int:
    parser = argparse.ArgumentParser(description="Envia rate spike (Phase 0)")
    parser.add_argument("--sandbox", action="store_true", help="Use api-test.envia.com")
    parser.add_argument("--prod", action="store_true", help="Use api.envia.com")
    parser.add_argument(
        "--token",
        default=os.environ.get("ENVIA_TOKEN", ""),
        help="API token (or ENVIA_TOKEN env)",
    )
    parser.add_argument(
        "--carriers",
        default="servientrega,interrapidisimo,coordinadora",
        help="Comma-separated Envia carrier slugs",
    )
    parser.add_argument("--timeout", type=float, default=8.0)
    parser.add_argument(
        "--origin-city",
        default=os.environ.get("ENVIA_ORIGIN_CITY", "Neiva"),
        help="Origin city name for locate",
    )
    parser.add_argument(
        "--origin-state",
        default=os.environ.get("ENVIA_ORIGIN_STATE", "HU"),
        help="Origin 2-letter CO department",
    )
    parser.add_argument(
        "--origin-postal",
        default=os.environ.get("ENVIA_ORIGIN_POSTAL", "410001"),
    )
    parser.add_argument(
        "--origin-street",
        default=os.environ.get("ENVIA_ORIGIN_STREET", "Calle 1 #1-1"),
    )
    parser.add_argument("-o", "--output", help="Write JSON report path")
    args = parser.parse_args()

    if not args.token:
        print("ERROR: set ENVIA_TOKEN or pass --token", file=sys.stderr)
        return 2

    if args.prod:
        base = "https://api.envia.com"
    else:
        base = "https://api-test.envia.com"

    carriers = [c.strip().lower() for c in args.carriers.split(",") if c.strip()]
    latencies: list[float] = []
    rows: list[dict[str, Any]] = []

    print(f"Base URL: {base}")
    print(f"Carriers: {carriers}")
    print(f"Locating origin {args.origin_city}/{args.origin_state}…")

    origin_loc, loc_elapsed, loc_raw = locate(
        base, args.origin_city, args.origin_state, args.timeout
    )
    latencies.append(loc_elapsed)
    if not origin_loc:
        print("ERROR: origin locate failed:", json.dumps(loc_raw, ensure_ascii=False)[:400])
        return 1

    origin = {
        "name": "Depósito Trujillo Spike",
        "phone": "3000000000",
        "street": args.origin_street,
        "city": origin_loc["city"],
        "state": origin_loc["state"],
        "country": "CO",
        "postalCode": args.origin_postal,
    }
    print(f"Origin DANE city={origin['city']} state={origin['state']} ({loc_elapsed:.3f}s)")

    dest_cache: dict[str, dict[str, str] | None] = {}

    for dest in DESTINATIONS:
        key = f"{dest['city']}|{dest['state']}"
        if key not in dest_cache:
            located, le, raw = locate(base, dest["city"], dest["state"], args.timeout)
            latencies.append(le)
            dest_cache[key] = located
            if not located:
                rows.append(
                    {
                        "destination": dest["label"],
                        "phase": "locate",
                        "ok": False,
                        "elapsed_s": round(le, 3),
                        "error": raw,
                    }
                )
                print(f"  LOCATE FAIL {dest['label']}: {le:.3f}s")
                continue
            print(f"  LOCATE OK {dest['label']} → {located['city']} ({le:.3f}s)")

        located = dest_cache[key]
        if not located:
            continue

        destination = {
            "name": "Customer",
            "phone": "3000000000",
            "street": "Calle 10",
            "city": located["city"],
            "state": located["state"],
            "country": "CO",
            "postalCode": dest["postalCode"],
        }

        for pkg in PACKAGES:
            # Parallel per-carrier for this dest+pkg
            t0 = time.perf_counter()
            results: list[dict[str, Any]] = []
            with ThreadPoolExecutor(max_workers=min(4, len(carriers))) as ex:
                futs = {
                    ex.submit(
                        rate_one,
                        base,
                        args.token,
                        origin,
                        destination,
                        pkg,
                        carrier,
                        args.timeout,
                    ): carrier
                    for carrier in carriers
                }
                for fut in as_completed(futs):
                    results.append(fut.result())
            wall = time.perf_counter() - t0
            latencies.append(wall)

            prices = []
            for r in results:
                for rate in r.get("rates") or []:
                    prices.append(
                        {
                            "carrier": rate.get("carrier"),
                            "service": rate.get("service"),
                            "price": rate.get("totalPrice"),
                            "eta": rate.get("deliveryEstimate"),
                        }
                    )

            ok = any(r.get("rates") for r in results)
            row = {
                "destination": dest["label"],
                "package": pkg["label"],
                "weight_kg": pkg["weight"],
                "wall_s": round(wall, 3),
                "ok": ok,
                "prices": prices,
                "per_carrier": [
                    {
                        "carrier": r["carrier"],
                        "http_status": r["http_status"],
                        "elapsed_s": r["elapsed_s"],
                        "n_rates": len(r.get("rates") or []),
                    }
                    for r in results
                ],
            }
            rows.append(row)
            status = "OK" if ok else "FAIL"
            cheapest = min((float(p["price"]) for p in prices), default=None)
            print(
                f"  RATE {status} {dest['label']} {pkg['label']}: "
                f"wall={wall:.3f}s n={len(prices)} cheapest={cheapest}"
            )

    latencies_sorted = sorted(latencies)
    n = len(latencies_sorted)
    p50 = latencies_sorted[n // 2] if n else 0
    p95 = latencies_sorted[int(n * 0.95)] if n else 0

    summary = {
        "base_url": base,
        "carriers": carriers,
        "origin_dane": origin.get("city"),
        "n_samples": len(rows),
        "latency_s": {
            "p50": round(p50, 3),
            "p95": round(p95, 3),
            "max": round(max(latencies), 3) if latencies else 0,
            "mean": round(statistics.mean(latencies), 3) if latencies else 0,
        },
        "rate_ok": sum(1 for r in rows if r.get("ok")),
        "rate_fail": sum(1 for r in rows if r.get("phase") != "locate" and not r.get("ok")),
        "locate_fail": sum(1 for r in rows if r.get("phase") == "locate" and not r.get("ok")),
        "rows": rows,
    }

    print("\n=== SUMMARY ===")
    print(json.dumps({k: summary[k] for k in summary if k != "rows"}, indent=2))

    if args.output:
        with open(args.output, "w", encoding="utf-8") as f:
            json.dump(summary, f, indent=2, ensure_ascii=False)
        print(f"Wrote {args.output}")

    # Exit 0 even with some fails — spike is diagnostic
    return 0


if __name__ == "__main__":
    sys.exit(main())
