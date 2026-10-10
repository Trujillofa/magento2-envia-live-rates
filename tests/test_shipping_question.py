"""Shipping questions route to Envia and never invent a price."""

from __future__ import annotations

import json
import re
import sys
from pathlib import Path

import pytest

TOOLS = Path(__file__).resolve().parents[1] / "tools"
sys.path.insert(0, str(TOOLS))

from embedding_rank import NATIVE_DIM, llama_embed  # noqa: E402
from shipping_question import route_message  # noqa: E402


def lexical_embed(text: str) -> list[float]:
    vector = [0.0] * 256
    for token in re.findall(r"[0-9a-záéíóúñ]+", text.casefold()):
        bucket = 0
        for char in token:
            bucket = (bucket * 33 + ord(char)) % 256
        vector[bucket] += 1.0
    return vector


def test_shipping_question_names_city_and_packages_without_a_price() -> None:
    route = route_message("¿Cuánto vale el envío a Florencia de 3 bultos?", lexical_embed)
    assert route["action"] == "envia_live_rate"
    assert route["city"] == "Florencia"
    assert route["packages"] == 3
    assert route["price"] is None
    assert "pesos" not in route


def test_product_question_is_not_a_shipping_rate() -> None:
    route = route_message("precio del cemento gris por bulto", lexical_embed)
    assert route["action"] == "not_shipping"
    assert route["intent"] == "product_quote"
    assert route["price"] is None


def test_llama_request_omits_dimensions_and_requires_768() -> None:
    captured: list[dict[str, object]] = []

    class Response:
        def __enter__(self) -> Response:
            return self

        def __exit__(self, *args: object) -> bool:
            return False

        def read(self) -> bytes:
            return json.dumps({"data": [{"embedding": [0.25] * NATIVE_DIM}]}).encode()

    def urlopen(request: object, timeout: float) -> Response:
        assert timeout == 30.0
        captured.append(json.loads(request.data.decode()))  # type: ignore[attr-defined]
        return Response()

    vector = llama_embed("hola", base_url="http://127.0.0.1:8081", urlopen=urlopen)
    assert len(vector) == NATIVE_DIM
    assert "dimensions" not in captured[0]
    assert captured[0]["input"] == ["hola"]


def test_non_finite_vector_is_refused() -> None:
    class Response:
        def __enter__(self) -> Response:
            return self

        def __exit__(self, *args: object) -> bool:
            return False

        def read(self) -> bytes:
            bad = [0.1] * NATIVE_DIM
            bad[0] = float("nan")
            return json.dumps({"data": [{"embedding": bad}]}).encode()

    with pytest.raises(Exception, match="non-finite"):
        llama_embed("hola", base_url="http://127.0.0.1:8081", urlopen=lambda *_a, **_k: Response())
