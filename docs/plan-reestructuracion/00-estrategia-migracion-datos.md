# Estrategia general de migración de datos (producción)

Esto aplica a **todo** tema del plan que cambie esquema, no solo al Tema 01.
Cada tema referencia esta estrategia en vez de repetirla.

Ya hay datos reales cargados por el cliente en producción. Ninguna acción de
"Esquema" se implementa sin su acción hermana de "Datos" que garantice que
lo existente sobrevive intacto. Regla dura: **nunca** un `down()`/DROP de
columna o tabla vieja en el mismo deploy que crea lo nuevo.

## Patrón: expandir → backfillear → verificar → cortar → contraer

Cada cambio de esquema con datos existentes se parte en 5 pasos, cada uno
su propio deploy/migración:

1. **Expandir** — crear tablas/columnas nuevas, siempre nullable o con
   default, sin tocar ni borrar nada de lo viejo. La app sigue funcionando
   100% igual que antes; esto es solo agregar estructura en paralelo.

2. **Backfillear** — un Artisan command dedicado (no un método `up()` de
   migración) que lee las tablas viejas y llena las nuevas.
   - Recorrido en chunks (`chunkById`) para no bloquear tablas grandes.
   - **Idempotente**: correrlo dos veces no duplica ni rompe nada (se puede
     re-ejecutar tranquilo si se corta a mitad de camino).
   - Donde haya ambigüedad de identidad (ver preguntas abiertas de cada
     tema, ej. "¿es el mismo club en dos torneos?"), el comando corre
     primero en **modo reporte** (`--dry-run`): imprime qué va a fusionar/
     crear sin escribir nada, para que lo revises antes de ejecutar en
     serio. Nunca fusiona en silencio por nombre exacto sin que lo veas
     antes.
   - Se prueba primero contra una copia de la base de producción (dump
     restaurado en local/staging), nunca directo en producción.

3. **Verificar** — un segundo comando (o el mismo con `--verify`) que
   compara conteos y relaciones entre el esquema viejo y el nuevo (ej. "cada
   `team_id` de `players` sigue apuntando a un equipo con el mismo
   `category_id` que tenía antes"). No se avanza al paso 4 hasta que esto
   cierre en cero diferencias inesperadas.

4. **Cortar (cutover)** — recién acá se cambia el código de la app para
   leer/escribir del esquema nuevo. Las columnas viejas quedan en la tabla
   pero sin uso (deprecadas), como red de seguridad para poder volver atrás
   rápido si algo se rompe en producción.

5. **Contraer** — pasado un período de estabilidad en producción (a
   definir por vos, ej. "después de correr una jornada completa de
   partidos sin problemas"), una migración aparte elimina las columnas/
   tablas viejas ya deprecadas. Esta es la única migración que hace DROP,
   y solo se propone cuando ya pediste explícitamente pasar a este paso.

## Antes de correr cualquier backfill en producción

- Dump completo de la base de producción (backup) inmediato antes de
  correr el comando de backfill ahí.
- Correr primero contra un dump restaurado en local para validar el
  reporte de `--dry-run` y afinar reglas de fusión/identidad.
- Ventana de mantenimiento si el backfill toca tablas con las que el
  cliente puede estar interactuando en simultáneo (ej. cargando jugadores
  mientras corre el script) — evita condiciones de carrera entre el script
  y un `INSERT` manual del cliente.

## Cómo se referencia esto en cada tema

En vez de escribir el paso a paso completo en cada tema, las acciones de
"Datos" de cada tema simplemente dicen a qué paso (Expandir / Backfillear /
Verificar / Cortar / Contraer) corresponden, y esta guía define el cómo.
