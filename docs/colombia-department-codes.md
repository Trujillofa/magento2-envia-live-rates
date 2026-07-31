# Colombia department → Envia state codes

Validated against Envia `POST /locate` (production). Magento ISO suffixes (after stripping `CO-`) and full names should map to the **Envia** column.

| Department | Magento ISO (example) | Envia state |
|------------|----------------------|-------------|
| Amazonas | AMA | AM |
| Antioquia | ANT | AN |
| Arauca | ARA | AR |
| Atlántico | ATL | AT |
| Bogotá D.C. | DC / CUN | DC or CN |
| Bolívar | BOL | BL |
| Boyacá | BOY | BY |
| Caldas | CAL | CL |
| Caquetá | CAQ | CA |
| Casanare | CAS | CS |
| Cauca | CAU | **CU** |
| Cesar | CES | CE |
| Chocó | CHO | CH |
| Córdoba | COR | CO |
| Cundinamarca | CUN | CN |
| Guainía | — | GU |
| Guaviare | GUV | **GA** |
| Huila | HUI | HU |
| La Guajira | LAG | LG |
| Magdalena | MAG | MA |
| Meta | MET | ME |
| Nariño | NAR | NA |
| Norte de Santander | NSA | NS |
| Putumayo | PUT | PU |
| Quindío | QUI | **QU** |
| Risaralda | RIS | RI |
| San Andrés | SAP | SA |
| Santander | SAN | **SN** (not ST) |
| Sucre | SUC | SU |
| Tolima | TOL | TO |
| Valle del Cauca | VAC | VC |
| Vaupés | VAU | VA |
| Vichada | VIC | VI |

Mapping is implemented in `Model/Api/RateRequestBuilder.php` (`mapColombiaDepartment`).
