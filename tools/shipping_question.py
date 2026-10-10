"""Route a customer sentence to the Envia live-rate flow.

The embedding only picks the intent. City and package count are read from the
sentence. The price stays null because pesos come from Envia.
"""

from __future__ import annotations

import argparse
import json
import re
import sys
from collections.abc import Callable, Sequence

from embedding_rank import EmbedError, embedder_from_env, rank_documents

INTENTS: tuple[dict[str, str], ...] = (
    {
        "id": "envia_live_rate",
        "text": "costo del envío envio flete domicilio despacho a una ciudad cuantos bultos",
    },
    {
        "id": "product_quote",
        "text": "precio existencia cemento pintura sku catálogo catalogo de un producto",
    },
    {
        "id": "store_hours",
        "text": "horario de atención atencion a qué hora abren la ferretería",
    },
)

CITY_TOKENS: tuple[tuple[str, str], ...] = (
    ("florencia", "Florencia"),
    ("ibagué", "Ibagué"),
    ("ibague", "Ibagué"),
    ("espinal", "Espinal"),
    ("neiva", "Neiva"),
    ("bogotá", "Bogotá"),
    ("bogota", "Bogotá"),
)

_PACKAGES = re.compile(r"(\d+)\s*(bultos?|cajas?|unidades?)", re.IGNORECASE)


def city_in(message: str) -> str | None:
    folded = message.casefold()
    for token, label in CITY_TOKENS:
        if token in folded:
            return label
    return None


def packages_in(message: str) -> int | None:
    match = _PACKAGES.search(message)
    if match is None:
        return None
    return int(match.group(1))


def route_message(message: str, embed: Callable[[str], Sequence[float]]) -> dict[str, object]:
    ranked = rank_documents(message, INTENTS, embed, text_of=lambda row: row["text"])
    intent = ranked[0][1]["id"]
    if intent != "envia_live_rate":
        return {"action": "not_shipping", "intent": intent, "price": None}
    return {
        "action": "envia_live_rate",
        "intent": intent,
        "city": city_in(message),
        "packages": packages_in(message),
        "price": None,
    }


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Route a shipping question to Envia. No price.")
    parser.add_argument("message")
    args = parser.parse_args(argv)
    try:
        route = route_message(args.message, embedder_from_env())
    except EmbedError as exc:
        print(str(exc), file=sys.stderr)
        return 2
    print(json.dumps(route, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
