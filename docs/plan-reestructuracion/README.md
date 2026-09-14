# Plan de reestructuración — MiTorneo

Documento vivo. Cada tema que me cuentes se documenta acá como una lista de
acciones antes de tocar código. Vos revisás cada acción y la marcás como:

- ✅ **Aprobada** — se implementa tal cual está escrita
- ✏️ **Modificada** — aprobada con cambios (anotar cuáles)
- ❌ **Denegada** — no se hace
- ❓ **Pendiente** — falta que la revises

No se toca código de ninguna acción hasta que su estado sea Aprobada o
Modificada. Como hay datos reales en producción, toda acción que cambie el
esquema de BD debe ir acompañada de su propia acción de **migración de
datos** (no solo migración de esquema) — estas se marcan explícitamente y
siguen siempre la [estrategia general de migración de datos](00-estrategia-migracion-datos.md)
(expandir → backfillear → verificar → cortar → contraer). Ningún tema
define su propio método de migración distinto sin que lo pidas vos.

## Temas

| # | Tema | Estado general |
|---|------|-----------------|
| 00 | [Estrategia general de migración de datos (aplica a todos los temas)](00-estrategia-migracion-datos.md) | ❓ Pendiente de revisión |
| 01 | [Categorías, clubes/equipos y jugadores como sistemas globales](01-clubes-equipos-categorias-globales.md) | ❓ Pendiente de revisión |
| 02 | [Un solo camino: categorías de torneo siempre del catálogo](02-unificacion-categorias-torneo.md) | ✅ Implementado y verificado contra datos reales, pendiente de correr en producción |

## Convenciones de este documento

- Cada acción tiene un ID único `T{tema}-{número}` (ej. `T01-03`) para poder
  referenciarla en conversación o en commits.
- "Esquema" = cambio de estructura de tablas/columnas.
- "Datos" = script/migración que mueve o transforma filas existentes para
  que sobrevivan al cambio de esquema sin pérdida.
- Las preguntas abiertas se listan al final de cada tema — son decisiones
  tuyas, no las resuelvo por mi cuenta.
