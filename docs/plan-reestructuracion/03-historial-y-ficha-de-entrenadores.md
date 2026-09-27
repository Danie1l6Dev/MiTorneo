# Tema 03 — Historial, ficha, transferencia y búsqueda de entrenadores

**Origen:** pedido del usuario (2026-09-27): llevar a los directores técnicos
todo lo que ya existe para jugadores — historial de planteles con fechas
reales, ficha con estadísticas y sanciones, "Transferir entrenador" y
"Buscar entrenador" en el sidebar. Investigado y estructurado en la misma
conversación; **no implementado todavía** a pedido explícito del usuario —
queda documentado acá, esperando que revise las preguntas abiertas antes de
tocar código.

## Qué existe hoy para jugadores (lo que se replica)

- `player_team_history`: una fila por estadía de un jugador en un plantel
  (`started_on`/`ended_on`, motivos de alta/baja, `is_estimated`), escrita
  únicamente por `PlayerRosterService` — el único punto donde puede
  cambiar el plantel de un jugador (`PlayerRosterHistoryGuardTest` falla si
  algo más lo toca).
- `PlayerHistoryBackfillService` + comandos `players:backfill-history`
  (`--dry-run`, solo inserta, todo marcado `is_estimated`) y
  `players:check-history` (reporte de desajustes, solo lectura). El pasado
  de un jugador sin historial se deduce de sus goles/tarjetas/sanciones.
- `PlayerProfileService` + `PlayerProfileController`: ficha de solo lectura
  (datos personales, plantel(es) actuales, estadísticas por torneo,
  sanciones, línea de tiempo de clubes/planteles con lo real + lo deducido
  marcado "Estimado").
- `PlayerTransferController`/`PlayerTransferRequest`: mueve al jugador a
  otro club en un paso (fecha, nota, dorsal opcional), registrado en su
  historial vía `PlayerRosterService::moveToClub()`.
- `PlayerSearchController` + `pages/players/index.blade.php` ("Buscar
  jugador" en el sidebar): catálogo completo del organizador, filtrado en
  el navegador mientras se escribe (nombre, documento, club actual o
  pasado).

## Diferencia clave que cambia el diseño

Hoy `Coach belongsTo Team` — **un DT pertenece a un solo equipo fijo**, sin
el equivalente al pivote `player_team` que permite a un jugador estar en
varios planteles a la vez. Este plan asume, salvo que la primera pregunta
abierta diga lo contrario, que un DT dirige **un plantel a la vez** (nunca
dos simultáneos) y solo agrega el historial de esas estadías + la
transferencia entre ellas — sin agregar el pivote de "planteles extra" que
tiene `Player`.

---

## Acciones

- [ ] **T03-01** — Migración `coach_team_history` (expand-only, aditiva):
  espejo de `player_team_history` sin lo que no aplica a un DT (sin
  `jersey_number`, sin `group_name` — un DT no juega en un grupo). Columnas:
  `id`, `coach_id`, `team_id` (nullable, `nullOnDelete`), `club_id`
  (nullable, `nullOnDelete`), `club_name`, `team_name`, `category_name`,
  `started_on`, `ended_on`, `start_reason`, `end_reason`, `is_estimated`,
  `notes`, timestamps. Reusa los enums existentes `RosterStartReason`/
  `RosterEndReason` (sus labels ya son genéricos, ninguno menciona
  "jugador"/"plantel" de forma que no aplique a un DT) — sin necesidad de
  enums nuevos.

- [ ] **T03-02** — Modelo `CoachTeamHistory` (mismo shape que
  `PlayerTeamHistory`: `scopeOpen()`, `isOpen()`, `clubLabel()`) +
  `Coach::teamHistory(): HasMany`.

- [ ] **T03-03** — `CoachRosterService`, el único punto de escritura para
  altas/transferencias/bajas de DT (mismo espíritu que
  `PlayerRosterService`, con guard test equivalente
  `CoachRosterHistoryGuardTest`). Más simple que el de jugadores porque no
  hay multi-plantel ni promoción por edad:
  - `enrollNew(Coach $coach)`: abre la primera línea al registrar un DT
    nuevo (`CoachController::store` pasa a usar esto en vez de
    `$team->coaches()->create()` directo).
  - `moveToClub(Coach $coach, Team $to, ?CarbonInterface $on, ?string $notes)`:
    cierra la línea abierta, reasigna `coach->team_id`, abre la nueva línea
    — usado por T03-06.
  - `closeTeam(Team $team)`: cierra la línea abierta de su DT cuando el
    plantel se elimina (mismo patrón que
    `PlayerRosterService::closeTeam()` — hay que ubicar el mismo punto de
    `Team::hasRecordsThatWouldBeLost()`/eliminación donde hoy se llama para
    jugadores y agregar la llamada equivalente para el DT del equipo).
  - `CoachController::toggleActive` (desactivar) pasa a cerrar la línea
    abierta con motivo `Removed`; reactivar (ya validado que no haya otro
    DT activo en el equipo) abre una línea nueva.

- [ ] **T03-04** — `CoachHistoryBackfillService` + comandos
  `coaches:backfill-history` (`--dry-run`, `--owner=`) y
  `coaches:check-history`, espejo de los de jugadores. El pasado de un DT
  sin historial se deduce de sus tarjetas (`MatchEvent` con `coach_id`) y
  sanciones (`Sanction` con `coach_id`) — no hay goles/asistencias que
  mirar. **Hace falta correrlo en producción una vez**, igual que se hizo
  para jugadores, ya que ya existen DT reales sin historial.

- [ ] **T03-05** — `CoachProfileService` + `CoachProfileController` (ficha
  de solo lectura, `coaches.show`): datos personales (nombre, documento),
  plantel actual, totales (amarillas, rojas, sanciones, torneos — sin
  goles/asistencias), desglose por torneo, historial de sanciones, línea de
  tiempo de clubes/planteles (real + deducido marcado "Estimado", mismo
  criterio que jugadores). Vista `pages/coaches/show.blade.php`.

- [ ] **T03-06** — "Transferir entrenador": `CoachTransferController` +
  `CoachTransferRequest` + vista `pages/coaches/transfer.blade.php`. Más
  simple que la de jugadores: sin regla de edad, sin dorsal, sin elegir
  varios planteles (uno solo, porque un DT no tiene "planteles extra"). El
  mismo flujo cubre tanto mover a otro club como cambiar de plantel dentro
  del mismo club (no hay noción de "plantel adicional" para un DT que
  justifique separarlo). Bloquea si el plantel destino ya tiene un DT
  activo (mismo chequeo que `CoachController::guardSingleActiveCoach()`).
  Registra el cambio vía `CoachRosterService::moveToClub()`.

- [ ] **T03-07** — "Buscar entrenador": `CoachSearchController` + vista
  `pages/coaches/index.blade.php`, espejo exacto de `PlayerSearchController`/
  `pages/players/index.blade.php` — catálogo completo del organizador,
  filtrado en el navegador (nombre, documento, club actual o pasado).

- [ ] **T03-08** — Rutas nuevas (mismo bloque de `routes/tournaments.php`
  donde están las de jugadores): `coaches` (búsqueda), `coaches/{coach}`
  (ficha — cuidado con el choque con las rutas ya existentes
  `coaches/{coach}/edit`, deben quedar en el orden correcto, mismo patrón
  que jugadores con `players/search`), `coaches/{coach}/transfer` (create/
  store). Ítem nuevo en el sidebar ("Buscar entrenador", ícono a definir)
  junto al de "Buscar jugador".

- [ ] **T03-09** — Política (`CoachPolicy`): agregar `viewAny()` (para
  `coaches.index`, hoy no existe en `CoachPolicy` — sí existe en
  `PlayerPolicy`).

- [ ] **T03-10** (fuera de alcance por ahora, condicional a la Pregunta 1) —
  Si el cliente confirma que un DT SÍ puede dirigir más de un plantel a la
  vez: agregar un pivote `coach_team` (como `player_team`) y ampliar
  `CoachRosterService` con `addTeam()`/`removeTeam()`, replicando el
  sistema dual de `Player` (`team_id` primario + pivote de "planteles
  extra"). Bastante más trabajo que el resto de este tema — no se hace
  hasta tener la confirmación.

---

## Preguntas abiertas

1. **¿Un DT puede dirigir más de una categoría/plantel de un club al mismo
   tiempo?** El usuario no lo sabe con certeza y va a consultarlo. Este
   plan asume que NO (uno a la vez, T03-01 a T03-09) porque es el diseño
   más simple y no rompe el esquema actual; si la respuesta es que sí,
   se reabre con T03-10 antes de implementar nada.
2. **¿"Transferir entrenador" debe permitir elegir cualquier plantel de
   cualquier club (incluido el mismo club, otra categoría), o solo mover
   entre clubes distintos?** Propuesto arriba (T03-06): cualquier plantel,
   mismo flujo para ambos casos, ya que no hay regla de edad que complique
   la elección como con jugadores. A confirmar.
3. **¿Se agregan campos nuevos a `Coach`** (fecha de nacimiento, género,
   teléfono, licencia, foto — mencionados como "no están todavía, misma
   razón que Player" en el propio modelo) **o se mantiene solo
   `full_name` + `document_number`?** Este plan asume que se mantiene igual
   — no hay reglas de edad para un DT que justifiquen agregar
   `birth_date`/`gender` como en `Player`. A confirmar si el pedido incluía
   también estos campos.
