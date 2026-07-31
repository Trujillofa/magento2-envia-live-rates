# Magento 2 — Tarifas de envío en vivo con Envia

**Idioma:** [English](README.md) · **Español**

**Cotización multi-transportadora en el checkout** para Magento 2 usando la [API de envíos de Envia.com](https://docs.envia.com/docs/ecommerce-checkout).

El producto oficial de Envia “Cotización Automática / Live checkout rates” soporta Shopify, WooCommerce, Wix, etc. — **no Magento 2**. Este módulo implementa la misma capacidad como un **carrier personalizado de Magento**.

Probado en producción en una ferretería en Colombia ([depositotrujillo.co](https://www.depositotrujillo.co)): híbrido con tarifas de tabla, envío gratis por ciudad, resolución DANE y tope de peso paquete.

| | |
|---|---|
| **Módulo** | `DepositoTrujillo_EnviaShipping` |
| **Magento** | 2.4.x (PHP ≥ 8.1) |
| **Licencia** | MIT |
| **Por defecto** | Carrier **desactivado** hasta configurar token + origen |

---

## Características

- **Tarifas en vivo** en checkout vía `POST /ship/rate/` (multi-carrier: p. ej. Servientrega, InterRapidísimo, Coordinadora)
- **Códigos DANE Colombia** vía Envia `POST /locate` (ciudad + departamento → código municipal)
- **Híbrido con tarifas de tabla**: plugin opcional oculta Mirasvit `mstrate` (u otro carrier) cuando Envia devuelve tarifas
- **Peso máximo paquete** (por defecto 100 kg): por encima se omite Envia para dejar pasar flete/tabla
- **Recargo (markup)**: porcentaje o fijo + cargo de manejo
- **Un método por transportadora** (deduplica el servicio más barato)
- **Envío gratis por ciudad** opcional (`dtlocalfree`) — *no* es el Free Shipping del core de Magento (que no filtra por ciudad)
- Alternar **sandbox / producción**
- Token API cifrado en Magento
- Caché de respuestas de tarifa (TTL configurable)
- Etiquetas de admin en español (`es_CO`)

### No incluido (a propósito)

- Sincronización de pedidos con el panel Envia / Ecommerce Pro OAuth  
- Generación automática de guías (`/ship/generate/`)  
- Devolución de tracking a Magento  

Eso es un proyecto de **fulfillment** aparte. Este módulo es **solo cotización en checkout**.

---

## Arquitectura

```
¿Peso del carrito ≤ parcel_max_kg?
        │
        ├─ No  → omitir Envia → tarifa de tabla / flete (p. ej. mstrate)
        │
        └─ Sí  → locate DANE → POST /ship/rate/ por transportadora
                    │
                    ├─ ≥1 tarifa → mostrar Envia; ocultar mstrate (plugin opcional)
                    └─ fallo / vacío → mstrate (o tarifa de respaldo)
```

Opcional: ciudad de destino en lista de gratis → `dtlocalfree` a $0 y Envia omitido.

---

## Requisitos

- Magento Open Source / Adobe Commerce 2.4.x  
- PHP 8.1+  
- Cuenta Envia + **token API** ([envia.com](https://envia.com) / docs)  
- HTTPS saliente a `api.envia.com` o `api-test.envia.com`  
- **Pesos** de producto en el catálogo (kg) para cotizaciones correctas  

Híbrido opcional: Mirasvit Shipping Table (`mstrate`) u otro carrier de tabla. El plugin de ocultar apunta al código `mstrate` por defecto.

---

## Instalación

### Composer (recomendado si está en Packagist / VCS)

```bash
composer require depositotrujillo/module-envia-shipping
bin/magento module:enable DepositoTrujillo_EnviaShipping
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:clean config full_page layout block_html
```

### Manual (app/code)

```bash
# desde la raíz de Magento
mkdir -p app/code/DepositoTrujillo
git clone https://github.com/Trujillofa/magento2-envia-live-rates.git \
  app/code/DepositoTrujillo/EnviaShipping

bin/magento module:enable DepositoTrujillo_EnviaShipping
bin/magento setup:upgrade
bin/magento setup:di:compile
# Preferir cache:clean sobre cache:flush en hosting compartido de producción
bin/magento cache:clean config full_page layout block_html
```

### Repositorio path de Composer (ruta local)

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

Más detalle: [docs/INSTALL.md](docs/INSTALL.md) (inglés).

---

## Configuración

**Tiendas → Configuración → Ventas → Métodos de entrega**

### Envia.com (Live Rates)

| Ajuste | Notas |
|--------|--------|
| **Habilitado** | Dejar en **No** hasta configurar token + origen |
| Token API | Cifrado; desde la cuenta Envia |
| Sandbox | Sí = `api-test.envia.com` |
| Lista de transportadoras | Separadas por coma, p. ej. `servientrega,interrapidisimo,coordinadora` |
| Peso máx. paquete (kg) | Por encima se omite Envia (por defecto **100**) |
| Origen | Calle, ciudad, **departamento 2 letras CO** (p. ej. `HU`), código postal |
| Markup | Ninguno / porcentaje / fijo COP |
| Un método por transportadora | Recomendado **Sí** |
| Ocultar mstrate cuando Envia cotiza | UX híbrida |

### Envío gratis por ciudad / Local free

Segundo carrier opcional `dtlocalfree`:

- Activar solo si quieres **envío a domicilio gratis en ciudades listadas** (p. ej. `neiva`)
- El Free Shipping del core de Magento **no puede** filtrar por ciudad — no usarlo para “gratis solo en X”

---

## Notas Colombia

1. Envia espera **códigos DANE municipales** en el campo `city` para tarifas CO. El módulo llama `/locate` con ciudad legible + departamento.  
2. Las regiones Magento pueden ser `CO-CUN`, `CO-ANT`, nombres completos, etc. `RateRequestBuilder` las mapea a códigos Envia (p. ej. Santander → `SN`, no `ST`).  
3. Checkout CO sin código postal está permitido; se genera un fallback estable desde el DANE.  
4. Usa contenido de paquete en **ASCII** si ves tarifas vacías (casos edge con UTF-8 en algunas rutas).  
5. El cliente API envía un **User-Agent** real (Cloudflare puede bloquear UA vacío).

Ver `docs/colombia-department-codes.md` para el mapa de departamentos validado en producción.

---

## Híbrido con tarifas de tabla (mstrate)

Plugins:

- `HideMstrateWhenEnviaPlugin` — tras `collectRates`, elimina métodos con carrier `mstrate` si existe alguna tarifa `envia`  
- `HideMstrateWhenLocalFreePlugin` — igual cuando está `dtlocalfree`  

Si usas otro código de carrier de tabla, cambia la cadena `'mstrate'` en esos plugins (o abre un PR para un campo de config).

**Flete:** las transportadoras de paquete suelen topar ~100–500 kg por bulto. Mantén tarifas de tabla/flete para carritos pesados; no subas `parcel_max_kg` a toneladas esperando que Envia reemplace el flete.

---

## Scripts de diagnóstico

Python 3, sin Magento (solo API):

```bash
export ENVIA_TOKEN='tu-token'

# Latencia + precios de muestra
python3 scripts/envia_rate_spike.py --prod

# Matriz de cobertura de ciudades (formato: ciudad|departamento)
python3 scripts/envia_city_coverage.py --prod \
  --cities-file ruta/a/ciudades.pipe \
  -o cobertura.json
```

---

## Pruebas

Tests de lógica (sin bootstrap Magento):

```bash
python3 -m pytest tests/ -v
```

---

## Valores seguros por defecto

- `carriers/envia/active = 0`  
- `carriers/dtlocalfree/active = 0`  
- API sandbox por defecto  
- Preferir `bin/magento cache:clean …` — evita `cache:flush` en producción si materializas `var/view_preprocessed`  

---

## Lecciones de producción (resumen)

Puesto en producción en Magento 2.4.7 + hosting compartido:

- El mapa de departamentos debe coincidir con códigos **Envia** (no solo ISO)  
- Tope de paquete ~100 kg es un buen rango multi-carrier en checkout  
- Envío gratis por ciudad debe ser un **carrier propio**, no Free Shipping del core  
- Ocultar tarifas de tabla evita cotizaciones duplicadas confusas  

Más detalle: `docs/DESIGN.md`

---

## Roadmap / fuera de este repo

- [ ] Pedido → generar guía Envia tras el pago  
- [ ] Sincronización Ecommerce Pro Magento  
- [ ] Lista configurable de “códigos de carrier a ocultar” en admin  
- [ ] Multi-paquete / split de cajas para carritos pesados  

Las contribuciones son bienvenidas vía PR.

---

## Licencia

MIT — ver [LICENSE](LICENSE).

## Créditos

Desarrollado para la operación de [Depósito Trujillo](https://www.depositotrujillo.co).  
API de Envia © Envia.com — este proyecto **no** es un producto oficial de Envia.
