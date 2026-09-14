# Tema 01 — Categorías, clubes/equipos y jugadores como sistemas globales

**Origen:** el cliente detectó que un mismo jugador que puede jugar en más de
una categoría (por condición física) debe cargarse dos veces hoy, porque
`Team`, `Category` y `Player` están anidados exclusivamente dentro de un
`Tournament`. No hay identidad global de club/jugador que se reutilice entre
torneos.

> Las 5 preguntas abiertas de la primera versión de este documento ya se
> respondieron (quedan documentadas en "Decisiones confirmadas" al final).
> Esta versión reescribe el modelo y renumera las acciones con esas
> respuestas ya incorporadas — nada estaba aprobado todavía, así que no hay
> pérdida de contexto.

## Modelo actual (para referencia)

```
Tournament (user_id = organizador dueño del torneo)
 └─ Category   (tournament_id, name único por torneo, uses_groups)
     └─ Group  (tournament_id, category_id, name único por categoría)
         └─ Team (tournament_id, category_id, group_id)
             ├─ Player (team_id, full_name, document_number, jersey_number)
             └─ Coach  (team_id, full_name, document_number)
```

Todo (`Category`, `Group`, `Team`) nace y muere con el torneo. No hay forma
de decir "este es el mismo club/jugador que ya jugó otro torneo", y tampoco
existe un catálogo reutilizable entre torneos del mismo organizador.

## Modelo propuesto (confirmado con el cliente)

**Clave:** "global" acá significa *global para el usuario organizador*, no
global a toda la app — cada organizador maneja su propio catálogo de
categorías y clubes, sin mezclarse con el de otros organizadores
(decisión #2). Todo lo nuevo de abajo lleva `user_id`.

```
Category (GLOBAL por usuario, ya no pertenece a un torneo)
 ├─ user_id (organizador dueño del catálogo)
 ├─ birth_year_from / birth_year_to  (ej. Baby: 2020–2021 ... Juvenil: 2007–2008)
 ├─ uses_groups (bool)
 └─ Group (GLOBAL, cuelga de Category, no de Tournament)

Club (GLOBAL por usuario — entidad NUEVA, ej. "Nilmar")
 ├─ user_id
 └─ name

Team/Plantel (cuelga de Club + Category [+ Group])
 ├─ club_id, category_id, group_id (nullable)
 └─ un club puede tener 0, 1 o 2 planteles por categoría, según cuántos
    grupos de esa categoría fieldee. Ejemplo real que diste:
      Nilmar + Cebollita (usa grupos A/B) → 1 solo plantel, quedó en grupo B
      Nilmar + Infantil  (usa grupos A/B) → 2 planteles: "Nilmar (A)" y "Nilmar (B)"

Player (identidad única — nombre/documento/fecha de nacimiento se cargan UNA vez)
 ├─ birth_date (NUEVO campo, hoy no existe)
 └─ puede estar vinculado a VARIOS Team/Plantel a la vez (tabla intermedia
    player_team, con jersey_number opcional por cada vínculo) — así es como
    un mismo jugador queda realmente en la nómina de dos categorías
    distintas sin volver a cargarlo. Cada vínculo nuevo está limitado por:
     - su birth_date debe caer dentro del rango de la categoría de ESE
       Team, o de una categoría MÁS GRANDE que la que le corresponde por
       edad — nunca una más chica
     - sin birth_date cargado, no se puede agregar NINGÚN vínculo nuevo

Tournament
 └─ selecciona qué Category(s) del catálogo del organizador incluye (ya no
    se "crean" categorías dentro del torneo, se eligen)
     └─ por cada categoría [+ grupo], selecciona qué Clubes/Planteles (de
        los que ya fieldan esa categoría/grupo) participan — estadísticas,
        posiciones, calendario y jugadores inscritos siguen siendo
        exclusivos de ESE torneo (decisión #2), el catálogo solo se
        reutiliza, no se comparten resultados entre torneos.
```

Esto confirma la lectura B que ya había asumido para "equipo = 1 fila por
categoría/grupo", pero agrega una pieza que faltaba: **`Club` es una
entidad propia**, separada del plantel. El sidebar va a mostrar clubes (ej.
"Nilmar"), y dentro de cada club sus planteles por categoría/grupo — no un
llano por-categoría como estaba planteado antes.

---

## Acciones — Esquema

- [~] **T01-01** — Quitar `tournament_id` de `categories`, agregar
  `user_id` (organizador dueño del catálogo). `unique(['tournament_id',
  'name'])` pasa a `unique(['user_id', 'name'])`.
  **🔶 Parcial (2026-09-11):** se agregó `user_id` (nullable por ahora, vía
  T01-14/Expandir). `tournament_id` y el cambio de índice único quedan para
  T01-21 (Contraer), recién cuando el backfill haya poblado `user_id` en
  todas las filas y se verifique.

- [x] **T01-02** — Agregar a `categories`: `birth_year_from` y
  `birth_year_to` (enteros). Con los ejemplos dados (Baby 2020–2021 ...
  Juvenil 2007–2008, que es la categoría más grande que manejan) ambos
  campos van con valor en la práctica; se dejan nullable en el esquema por
  las dudas, pero no se espera ninguna fila con `NULL` hoy.
  **✅ Implementado (2026-09-11)**, columnas nullable.

- [~] **T01-03** — Quitar `tournament_id` de `groups`. `Group` pasa a
  colgar solo de `category_id` (heredando el `user_id` de su categoría, sin
  columna propia). Se mantiene `unique(['category_id','name'])`.
  **🔶 Parcial (2026-09-11):** al igual que `Category`, un grupo global se
  crea como fila NUEVA (no se repunta ninguna fila vieja) apuntando a la
  `Category` global correspondiente — el backfill (T01-15/16) tiene que
  poder insertar esas filas nuevas con `tournament_id = NULL`, así que se
  volvió nullable en el paso Expandir (antes era obligatoria). El resto
  (borrar la columna del todo) queda para T01-21 (Contraer).

- [x] **T01-04** — Crear tabla `clubs` (NUEVA): `id`, `user_id`, `name`,
  timestamps. `unique(['user_id','name'])`.
  **✅ Implementado (2026-09-11)** — `database/migrations/2026_09_11_100000_create_clubs_table.php`,
  modelo `App\Models\Club`.

- [~] **T01-05** — Quitar `tournament_id` de `teams`, agregar `club_id`
  (FK a `clubs`, NOT NULL). `Team` pasa a representar el **plantel** de un
  club en una categoría (+grupo si aplica), ya no el club en sí. Mantiene
  `category_id`, `group_id` (nullable) y `name` tal como hoy.
  `unique(['club_id','category_id','group_id','name'])` — **corregido**
  (ver decisión #8): un club SÍ puede tener más de un plantel en la misma
  categoría/grupo (ej. dos plantillas porque no entran todos los chicos en
  una sola), diferenciados por `name`. No alcanza con
  `unique(['club_id','category_id','group_id'])` solo, eso asumía como
  mucho un plantel por combinación y ya vimos en datos reales que no es
  así.
  **🔶 Parcial (2026-09-11):** se agregó `club_id` (nullable por ahora).
  `tournament_id`, el NOT NULL de `club_id` y el índice único quedan para
  T01-21 (Contraer), después del backfill+verificación.

- [x] **T01-06** — Crear tabla pivote `tournament_category` (`tournament_id`,
  `category_id`) para registrar qué categorías del catálogo del organizador
  incluye cada torneo. Reemplaza lo que hoy hace `categories.tournament_id`.
  **✅ Implementado (2026-09-11)**.

- [x] **T01-07** — Crear tabla pivote `tournament_team` (`tournament_id`,
  `team_id`) para registrar qué planteles participan en el torneo dentro de
  su categoría/grupo. Reemplaza lo que hoy hace `teams.tournament_id`.
  Nota: esto es distinto de `competition_phase_team` (que ya existe y
  filtra equipos *dentro de una fase*, ej. "los 2 primeros de cada grupo") —
  `tournament_team` es el universo completo de planteles inscritos al
  torneo/categoría, `competition_phase_team` es un subconjunto de esos para
  una fase puntual.
  **✅ Implementado (2026-09-11)**.

- [x] **T01-08** — Agregar `birth_date` (date, **nullable**) a `players` —
  nullable porque los jugadores ya cargados esta semana no lo tienen
  (decisión #4, actualizada: sin `birth_date` el jugador se queda
  únicamente con la categoría que ya trae del backfill — ver T01-11 y
  T01-18 — no se le puede sumar ninguna categoría nueva hasta cargarlo).
  **✅ Implementado (2026-09-11)**.

- [x] **T01-09** — ~~Crear tabla pivote `category_player`~~ **corregido a
  `player_team`** (`player_id`, `team_id`, `jersey_number` nullable): un
  jugador puede estar vinculado a **varios** planteles a la vez (uno por
  categoría/grupo en la que realmente juegue), cada vínculo con su propio
  dorsal opcional. Reemplaza `players.team_id` (FK única, se mantiene por
  ahora — se quita recién en una futura migración Contraer de este
  sub-tema). Ver decisión #6 (por qué `category_player` no alcanzaba).
  **✅ Implementado (2026-09-11, corregido)** —
  `database/migrations/2026_09_11_100006_create_player_team_table.php`,
  `Player::teams()` / `Team::globalPlayers()`.

- [x] **T01-10** — `jersey_number` se mueve conceptualmente a la fila de
  `player_team` (nullable, igual que hoy) — un jugador puede tener un
  dorsal distinto en cada plantel al que pertenece. `document_number` y
  `full_name`/`birth_date` quedan en `Player` (se cargan una sola vez, no
  por plantel). La columna `players.jersey_number` legacy se mantiene sin
  tocar hasta el Contraer de este sub-tema.
  **✅ Implementado (2026-09-11)** vía T01-09.

## Acciones — Reglas de negocio / validación

- [x] **T01-11** — Regla de elegibilidad por edad: dado `birth_date` de un
  jugador, calcular su categoría "natural" por año de nacimiento y permitir
  vincularlo (vía `player_team`) a un plantel de esa categoría o de
  cualquier categoría de rango **mayor** (jugadores más grandes), nunca de
  rango **menor**. Implementado como `Player::ageEligibleForCategory()`
  (una sola comparación: `birth_date.year >= category.birth_year_to`, que
  cubre "encaja justo" y "juega arriba" a la vez) + validación en
  `PlayerRequest::withValidator()` al vincular un jugador a un plantel — no
  como constraint de BD.
  - **Sin `birth_date`**: no se puede crear ningún vínculo `player_team`
    nuevo para ese jugador (bloqueado con mensaje explícito). El jugador
    conserva únicamente el vínculo que ya trae del backfill (T01-17/T01-18),
    hasta que se le cargue la fecha de nacimiento.
  - **Decisión de implementación (no confirmada explícitamente antes, la
    tomé por consistencia con el resto del sistema):** esta regla y el
    bloqueo por falta de `birth_date` aplican solo al vincular a un
    **segundo** plantel en adelante — el primer plantel de un jugador
    nuevo nunca se bloquea por esto (igual que hoy, que tampoco pide fecha
    de nacimiento para el alta inicial). Si preferís que también se exija
    en el primer alta, se ajusta fácil.
  - **Sin `birth_year_to` en la categoría destino:** no se bloquea nada
    (no hay con qué comparar) — importante en la práctica porque las
    categorías reales de Faudis todavía no tienen el rango de años
    cargado.
  **✅ Implementado y probado (2026-09-12)**, incluyendo contra un plantel
  real de Faudis (vinculación exitosa de un jugador a dos planteles reales
  sin duplicar sus datos).

- [x] **T01-12** — Validación al crear un plantel (Team): el club debe
  existir en el catálogo del organizador, la categoría también, y si la
  categoría tiene `uses_groups = true`, hay que elegir a qué grupo
  pertenece ese plantel puntual. Un club puede no tener plantel en todos
  los grupos de una categoría (ej. Nilmar solo tiene plantel en el grupo B
  de Cebollita, no en el A) — no forzar a completar todos los grupos.
  **✅ Implementado y probado** — `ClubTeamRequest` (categoría propia,
  grupo requerido/prohibido según `uses_groups`) + `TeamPolicy::create`
  (el club debe ser del organizer). Marcado retroactivamente: ya estaba
  hecho desde T01-26, solo faltaba tildarlo acá; se agregó además el test
  que faltaba para el caso "club ajeno" (`test_a_user_cannot_create_a_plantel_under_another_organizers_club`,
  2026-09-12).

- [x] **T01-13** — Validación al armar un torneo: al elegir categorías para
  el torneo, el selector de planteles por categoría (paso siguiente) solo
  debe listar planteles que ya existan para esa categoría/grupo en el
  catálogo del organizador — no cualquier plantel de cualquier categoría.
  **✅ Implementado y probado (2026-09-12)** — ver T01-25
  (`TournamentCategoryController::editTeams/updateTeams`, filtra siempre
  por `category_id`).

## Acciones — Migración de datos existentes (producción)

Sigue el patrón de [00 — Estrategia general de migración de
datos](00-estrategia-migracion-datos.md). A diferencia de la primera
versión de este documento, acá **ya no hay ambigüedad de identidad que
resolver a mano**: el cliente confirmó que un mismo nombre de categoría
(decisión #2) o de club (decisión #3) es la misma entidad real, siempre que
pertenezca al mismo organizador (`user_id`). El backfill puede fusionar por
`(user_id, name)` exacto de forma determinística — el modo reporte queda
igual como chequeo visual antes de escribir, no porque haga falta decidir
caso por caso.

**Fusión de planteles entre torneos (decisión #6):** cuando el mismo
club+categoría+grupo aparece en más de un torneo (ej. "Nilmar (A)" en
Infantil, en dos torneos distintos), hoy son 2 filas `Team` con jugadores
cargados por separado en cada una. El backfill las fusiona en **un solo**
`Team` global — los jugadores de ambas quedan vinculados (vía `player_team`)
a ese mismo plantel. Justificación: solo hay un usuario real en producción
con datos que importan (Faudis Enrique Palacio Cortina), y fusionar no
pierde nada — `MatchParticipant`/`MatchEvent` siguen referenciando
`player_id` directo (no la lista de plantel), y el sistema ya tolera
jugadores activos/inactivos mezclados históricamente bajo un mismo
`team_id` (así maneja hoy la reutilización de dorsal). El reporte
`--dry-run` (T01-15) igual lista estos casos para que los veas antes de que
se ejecuten.

- [x] **T01-14** — *(Expandir)* Crear las tablas/columnas nuevas de este
  tema (`clubs`, `categories.user_id` + `birth_year_from/to`,
  `tournament_category`, `tournament_team`, `players.birth_date` nullable,
  `category_player`) sin tocar ni quitar todavía `tournament_id` de
  `categories`/`groups`/`teams`. La app sigue funcionando igual que hoy en
  este paso.
  **✅ Implementado (2026-09-11)**: 7 migraciones nuevas +
  modelo `Club` + relaciones nuevas en `Category`, `Team`, `Player`,
  `Tournament` (`globalCategories()`, `globalTeams()`, etc., en paralelo a
  las relaciones legacy que la app sigue usando). Corrido contra la BD
  local (sqlite) y los 401 tests existentes siguen pasando sin cambios —
  nada de esto se corrió todavía contra producción.
  **2026-09-11 — corrección 1:** la pivote de jugadores se rehízo de
  `category_player` a `player_team` (ver decisión #7 más abajo) antes de
  seguir — con `category_player` un jugador nunca llegaba a aparecer en el
  plantel real de una segunda categoría, no resolvía el problema original
  del cliente.
  **2026-09-11 — corrección 2:** `groups.tournament_id` se volvió nullable
  (T01-03) porque el backfill necesita crear grupos globales nuevos sin
  torneo — se me había pasado que `Group` necesita el mismo tratamiento que
  `Category`.
  **2026-09-11 — corrección 3:** lo mismo se me había pasado para
  `categories.tournament_id` y `teams.tournament_id` (ambos seguían siendo
  `NOT NULL`) — lo detectó la primera corrida de prueba del comando de
  backfill contra datos locales (falló con un error de integridad,
  restauré el backup y corregí antes de seguir). Los tres quedaron
  nullable vía las migraciones `2026_09_11_100007` y `2026_09_11_100008`.

- [x] **T01-15** — *(Backfillear, modo reporte)* Comando Artisan
  `tournaments:backfill-global-catalog --dry-run` que:
  - agrupa las `categories` actuales por `(tournament.user_id, name)` y
    propone 1 `Category` global por grupo;
  - agrupa los `groups` actuales por `(categoría global, name)` y propone 1
    `Group` global por grupo;
  - agrupa los `teams` actuales por `(club_owner.user_id, name)` y propone
    1 `Club` global por grupo, con un `Team`/plantel por cada
    `(category_id, group_id)` distinto que ese nombre tuvo en algún torneo
    de ese organizador — **fusionando** en un solo `Team` los casos donde
    el mismo club+categoría+grupo aparece en más de un torneo (decisión
    #6), y listando esos casos de fusión explícitamente en el reporte;
  - lista además "posibles clubes relacionados" (nombres que comparten una
    base antes de un sufijo tipo " (A)"/" - B") como aviso informativo
    únicamente — **nunca** los fusiona solo, porque esa regla de nombre no
    la confirmaste;
  - acepta `--aliases=archivo.json` con un mapeo `{"user_id": {"nombre
    legacy": "nombre canónico del club"}}` **confirmado a mano** (nunca
    adivinado) para los casos reales encontrados (decisión #9) — el alias
    solo cambia a qué `Club` se asocia un plantel, nunca la identidad del
    `Team` en sí (que sigue distinguiéndose por su nombre legacy, ver
    decisión #8);
  - imprime el reporte para revisión antes de escribir nada.
  **✅ Implementado y probado (2026-09-11)** contra los datos demo locales
  (sqlite) **y contra una copia real de producción** (dump de Faudis
  restaurado en un MySQL descartable vía Docker — ver detalle en T01-16):
  reporte correcto, sin fusiones falsas, y con `--aliases=club-aliases-faudis.json`
  aplicado, "NILMAR"/"NILMAR (A)"/"NILMAR (B)" (y las otras 3 familias
  confirmadas) se ven correctamente como 1 solo club con sus plantillas
  separadas intactas.

- [x] **T01-16** — *(Backfillear, ejecución)* Mismo comando sin
  `--dry-run`: crea/reutiliza `Category` y `Club` globales según el
  reporte de T01-15, crea los `Team` (planteles, ya fusionados entre
  torneos donde aplique, y nunca entre sí aunque compartan club+categoría+
  grupo — decisión #8) con su `club_id` + `category_id` + `group_id` +
  `name`, puebla `tournament_category` y `tournament_team` a partir de
  `categories.tournament_id` / `teams.tournament_id`, y crea una fila
  `player_team` por cada jugador existente apuntando al `Team` global que
  le corresponde (con su `jersey_number` legacy copiado ahí) — sin borrar
  todavía `tournament_id` ni `players.team_id`/`players.jersey_number`.
  Idempotente: correrlo dos veces no duplica filas.
  **✅ Implementado y probado (2026-09-11)** en dos rondas:
  1. Contra una copia de la BD local demo (backup restaurado después): 26
     clubes, 6 categorías, 41 planteles, 581 vínculos `player_team` (uno
     por jugador, ningún huérfano). Corrida una segunda vez para confirmar
     idempotencia: todo "reutilizado"/"ya vinculado", cero filas nuevas.
  2. Contra una copia real del backup de producción (importado a un MySQL
     descartable en Docker, nunca tocó la base real): las 9 migraciones
     corrieron sin errores sobre datos reales, el `--dry-run` con
     `--aliases` mostró exactamente el resultado esperado (28 clubes para
     el organizador real en vez de 41 nombres crudos, con Nilmar/Pantera
     Negras/Maicao F.C/Jair Pinto correctamente unificados y sus
     plantillas de Baby — que no usa grupos — intactas por separado).
     Encontrado y corregido en el camino: el bug de la decisión #8 (dos
     plantillas del mismo club+categoría+grupo colapsando en una), que
     solo salió a la luz al ver datos reales, no con los datos de prueba
     locales.
  **2026-09-12 — ejecutado de verdad, pero contra la copia local
  persistente (MySQL en Docker con el backup restaurado), no contra
  producción todavía:** `tournaments:backfill-global-catalog
  --aliases=docs/plan-reestructuracion/club-aliases-faudis.json` (sin
  `--dry-run`) corrió limpio → 14 categorías globales, 17 grupos, 50
  clubes, 131 planteles (ninguno perdido ni fusionado de más), 115
  vínculos `player_team` (uno por jugador). `tournaments:verify-global-catalog`
  dio ✅ sin diferencias. Repetido una segunda vez para confirmar
  idempotencia: cero filas nuevas. Nilmar/Pantera Negras/Maicao F.C en
  Baby quedaron con sus 2 plantillas separadas, como debía ser.
  **Todavía no se corrió contra la base de producción real** (Clever
  Cloud) — sigue pendiente un backup fresco al momento de ejecutar ahí, y
  tu confirmación explícita aparte para ese paso puntual.

- [x] **T01-17** — *(Backfillear)* Ya cubierto por T01-16: cada jugador
  existente ya tiene hoy un `team_id` → se traduce directo a su fila
  `player_team` (mismo plantel que ya tenía, dorsal incluido), sin esperar
  el dato de `birth_date`.
  **✅ Implementado (2026-09-11)** — ver T01-16.

- [x] **T01-18** — *(Backfillear, dato faltante)* `players.birth_date` no
  existe hoy en ninguna fila, queda `NULL` para los jugadores ya cargados.
  Decisión #4 (actualizada): se muestra un aviso/banner de "dato
  incompleto" en su ficha, y **además** se bloquea vincularlo a planteles
  nuevos (T01-11) hasta que se complete a mano — el jugador se queda
  únicamente con el vínculo `player_team` que ya le pobló T01-16/17 a
  partir de su plantel actual.
  **✅ Implementado y probado** — `Player::ageEligibleForCategory()`
  devuelve `false` sin `birth_date` (bloquea el vínculo nuevo en el
  servidor, no solo en la UI); banner de advertencia en
  `pages/players/edit.blade.php` ("Falta la fecha de nacimiento... no se
  puede sumar a ningún otro plantel/categoría") + ícono ⚠ agregado en
  T01-27 en los listados de clubes/planteles. Marcado retroactivamente:
  ya estaba hecho, solo faltaba tildarlo acá.

- [x] **T01-19** — *(Verificar)* Comando `tournaments:verify-global-catalog`
  que compara, por cada torneo existente: mismo set de categorías antes/
  después (vía `tournament_category`), mismo set de planteles antes/después
  (vía `tournament_team`), y que cada `player` tiene su fila `player_team`
  correspondiente (incluyendo los casos fusionados de la decisión #6, sin
  perder a nadie). No se avanza a Cortar hasta que esto dé sin diferencias.
  **✅ Implementado y probado (2026-09-11)**: corre `--dry-run` del
  backfill de nuevo y falla si algo todavía figura como "se crearía"/"se
  vincularía" en vez de "reutilizado"/"ya vinculado", más un chequeo
  directo de conteos legacy-vs-global por torneo. Probado en ambos
  sentidos contra el sqlite local: falla (1252 problemas) antes de
  backfillear, pasa limpio después. `Coach` queda fuera de este chequeo a
  propósito -- no se tocó en este tema (el pedido original era solo sobre
  jugadores), sigue funcionando 100% legacy sin cambios.

- [~] **T01-20** — *(Cortar)* Cambiar el código de la app para leer y
  escribir contra el esquema nuevo. **Hecho para Clubes, Categorías,
  Jugadores e Inscripción de torneo (T01-22 a T01-27)** — todo ese código
  ya vive exclusivamente contra el catálogo global. **Deliberadamente sin
  hacer todavía** para fases/calendario/standings:
  `PhaseEligibilityService`, `GenerateLeagueScheduleRequest` y el resto de
  la generación de partidos siguen resolviendo el roster elegible a partir
  del `category_id` legacy (torneo implícito), no de `tournament_team`.
  **Decisión confirmada explícitamente (2026-09-12):** el cliente,
  consultado de nuevo antes de pasar a producción, eligió mantener este
  recorte — la app sigue funcionando en paralelo (categorías legacy con
  fases de siempre + categorías del catálogo que hoy solo sirven para
  inscripción) hasta que se pida explícitamente ampliar esto. Las
  columnas `tournament_id` de `categories`/`groups`/`teams` siguen
  existiendo y en uso por el lado legacy — no son solo una red de
  seguridad residual.

- [ ] **T01-21** — *(Contraer — no se ejecuta todavía)* Migración aparte
  que elimina `tournament_id` de `categories`/`groups`/`teams`. **No
  aplica todavía ni tiene fecha**: mientras T01-20 no se complete del
  lado de fases/standings, esas columnas siguen siendo necesarias para el
  flujo legacy. Se propone solo cuando lo pidas explícitamente y después
  de que ese trabajo esté hecho.

## Acciones — Backend (controllers/services/requests)

Archivos que hoy asumen `Category`/`Team` viviendo bajo un `Tournament` y
que quedan impactados (relevados, no exhaustivo — se detalla al aprobar
esquema):

- `CategoryController`, `CategoryRequest` — pasan de "crear categoría en
  este torneo" a un CRUD global (por usuario) de categorías + selector al
  armar un torneo. Como `Group` cuelga directo de `Category` (no del
  torneo), la alta/edición de una categoría global es también donde se
  definen sus grupos (si `uses_groups = true`) — una sola vez, reutilizados
  por cualquier torneo que incluya esa categoría, no por torneo.
- `TeamController` — se divide conceptualmente en dos: CRUD de `Club`
  (alta simple: nombre) y alta de `Team`/plantel dentro de un club
  (elegir categoría, y grupo — de los ya definidos en esa categoría — si
  aplica) + selector de inscripción por torneo.
- `GroupController` — deja de depender de `tournament_id`; pasa a
  administrarse desde la pantalla de la categoría global, no desde un
  torneo puntual.
- `PhaseEligibilityService`, `GenerateLeagueScheduleRequest` — hoy arman el
  universo de equipos elegibles a partir de `category_id` (+ torneo
  implícito); pasan a filtrar también por `tournament_team`.
- `PlayerController`/`PlayerRequest` (o el nombre actual) — agrega
  `birth_date` (nullable, con banner de dato incompleto si falta) +
  selector de categorías con la regla de elegibilidad; el selector se
  deshabilita por completo si el jugador no tiene `birth_date`.

- [x] **T01-22** — `CategoryController` se dividió en el CRUD global
  (`index`/`create`/`store`/`show`/`edit`/`update`/`destroy`/
  `toggle-status`, rutas planas `categories.*`) y dos métodos legacy
  renombrados (`createForTournament`/`storeForTournament`, mismas rutas
  `tournaments.categories.create/store` de siempre) que se mantienen vivos
  para no romper torneos ya en curso. `TeamController` sumó
  `createForClub`/`storeForClub` (rutas `clubs.teams.*`) sin tocar su
  `create`/`store` legacy. `GroupController` no necesitó cambios: ya
  seteaba `group->tournament_id = $category->tournament_id`, que ahora
  simplemente guarda `null` para una categoría global. Políticas
  (`CategoryPolicy`, `GroupPolicy`, `TeamPolicy`, `PlayerPolicy`,
  `CoachPolicy`) reescritas para usar un método `ownerId()` nuevo en
  `Category`/`Group`/`Team`/`Club` en vez de `->tournament->user_id`
  directo, que rompía con `tournament_id = null`.
  **✅ Implementado y probado (2026-09-12)**. Un bug real de este mismo
  estilo (`$category->tournament->name` en el breadcrumb de
  `categories.show`, sin proteger contra `tournament_id = null`) recién
  salió a la luz al probar la vista contra una categoría global real de tu
  propia cuenta en la copia local — arreglado ahí y en `groups.show` /
  `categories.edit` / `teams.show` (mismos breadcrumbs). Quedó también
  bloqueada por política (no solo oculta en la vista) la creación de fases
  sobre una categoría global, ya que las fases siguen siendo un concepto
  ligado al torneo hasta el paso T01-24/25.

## Acciones — Frontend / navegación

- [x] **T01-23** — Agregado "Clubes" y "Categorías" al sidebar, fuera del
  contexto de un torneo.
  **✅ Implementado (2026-09-12)**.
- [x] **T01-24** — "Inscripción" de un torneo desde el catálogo: elegir
  qué categorías globales del organizador participan en este torneo
  (`tournaments/{tournament}/global-categories/create`, checkboxes sobre
  las categorías que todavía no están agregadas), vía el pivote
  `tournament_category`. Nuevo `TournamentCategoryController` (create,
  store, destroy) — deliberadamente separado del alta legacy
  `tournaments.categories.create/store`, que sigue siendo la única vía
  para crear una categoría **con** fases/calendario propios.
  **✅ Implementado y probado (2026-09-12)**.
- [x] **T01-25** — Por cada categoría del catálogo agregada a un torneo,
  elegir qué planteles existentes participan
  (`tournaments/{tournament}/global-categories/{category}/teams`,
  checkboxes agrupados por grupo, vía el pivote `tournament_team`).
  Guardar reemplaza la selección completa de esa categoría en ese torneo
  (no toca la de otras categorías). Ambas rutas alcanzables desde una
  nueva sección "Categorías del catálogo" en la ficha del torneo, debajo
  de la sección legacy "Categorías".
  **✅ Implementado y probado (2026-09-12)**.

  **Alcance elegido explícitamente por el cliente:** frente a la opción de
  ir más allá (fases/calendario/standings acotados al torneo para estas
  categorías del catálogo), el cliente eligió la más chica y segura:
  **"Solo la inscripción (elegir categorías y planteles del catálogo)"**.
  Por eso esta categoría, una vez agregada a un torneo, **todavía no puede
  tener fases** — ese texto está también en la propia UI. Enseñar a
  `PhaseEligibilityService` y al resto del flujo de fases/partidos/
  standings a resolver el roster de una categoría vía `tournament_team`
  en vez de su roster global completo es un paso aparte, no implementado
  todavía, a pedido explícito.

  El backfill (`GlobalCatalogBackfillService`) ya deja pobladas estas
  pivotes automáticamente para los torneos legacy migrados — cada
  categoría/plantel legacy queda vinculada a su equivalente canónico del
  catálogo en `tournament_category`/`tournament_team` sin que el
  organizador tenga que hacer nada. Verificado con los 5 torneos/9
  categorías reales de Faudis: la ficha del torneo #5 ya mostraba sus 9
  categorías con conteo correcto de planteles inscritos, y un ciclo real
  quitar → volver a agregar categoría + planteles sobre JUVENIL (5
  planteles reales) funcionó de punta a punta, revertido después a su
  estado exacto original.
  12 tests nuevos (456 en total).
- [x] **T01-26** — Alta de Club: nombre + (opcional) checkboxes de
  categorías en las que juega de una vez (`/clubs/create`) — por cada
  categoría marcada (o cada grupo marcado, si esa categoría usa grupos) se
  crea de una vez un plantel con el nombre del club, sin tener que volver a
  entrar después. Alta de Plantel dentro de un club ya existente
  (`/clubs/{club}/teams/create`): elegir categoría (del catálogo propio) y,
  si usa grupos, a qué grupo — con un selector de categoría vía Alpine que
  muestra el selector de grupo correspondiente. Corregido en el camino: los
  `<select name="group_id">` ocultos de las categorías no elegidas se
  deshabilitan (`x-bind:disabled`) para que el navegador nunca envíe el
  grupo de una categoría distinta a la seleccionada.
  **✅ Implementado y probado (2026-09-12)**, incluyendo un ciclo completo
  real por HTTP (crear club → crear plantel → aparece en su categoría) y
  contra el club real "NILMAR" de Faudis (10 planteles, incluida la
  fusión Baby-A/Baby-B) renderizando correctamente.
  **2026-09-13 — a pedido del cliente:**
  1. La vista de Clubes (`/clubs`) se rehizo de cards a **tabla/lista**,
     organizada **por categoría** (y por grupo dentro de ella) en vez de
     por club — un club con planteles en varias categorías aparece en cada
     sección correspondiente, no una sola vez. Nuevo componente
     `x-ui.clubs-table`. La ficha de un club (`/clubs/{club}`) ahora
     también subdivide sus planteles por grupo, no solo por categoría.
  2. El alta de club ganó los checkboxes de categorías/grupos iniciales
     descrita arriba (no estaba en el diseño original de T01-26).
  Ambos probados con datos reales (Faudis: "LEMZ SPORT" aparece
  correctamente en sus 5 categorías distintas) y con 3 tests nuevos.
- [x] **T01-27** — Alta de jugador rehecha como "buscar o crear" en un solo
  formulario, desde la ficha del plantel de destino (`teams/{team}/players/create`,
  ruta compartida con el alta legacy, que sigue igual): se pide documento +
  nombre + fecha de nacimiento (opcional) + dorsal para ESE plantel.
  - Si el documento ya existe en algún plantel/club/torneo de este mismo
    organizador (jugador ya cargado antes), se **vincula** vía
    `player_team` sin duplicar nada — nombre y fecha de nacimiento
    tipeados de nuevo se ignoran (se conservan los ya guardados), aplica
    la regla de elegibilidad T01-11.
  - Si no existe, se crea un jugador nuevo de la forma "vieja"
    (`players.team_id` apuntando directo a este plantel) — es su primer y
    único plantel por ahora, no necesita el pivote todavía.
  - El dorsal ahora se valida contra **ambas** fuentes (columna legacy
    `players.jersey_number` y pivote `player_team.jersey_number`) para el
    mismo plantel, así nunca se pisan entre sí.
  - Aviso visible de "falta fecha de nacimiento" agregado tanto en cada
    fila de jugador (ícono ⚠) como en su ficha de edición.
  - **Simplificación consciente respecto al enunciado original:** no
    agregué el botón "agregar a otro plantel" *desde la ficha del
    jugador* — el mismo resultado se logra yendo al plantel de destino y
    tipeando su documento ahí. Evita construir un selector de plantel
    desde la vista del jugador para un beneficio marginal; si preferís
    tenerlo también desde ahí, es un agregado chico a futuro.
  **✅ Implementado y probado (2026-09-12)**: 7 tests nuevos (430 en total)
  + prueba real de punta a punta contra dos planteles reales de Nilmar —
  mismo jugador vinculado a ambos sin duplicarse, nombre repetido
  ignorado, dorsal validado correctamente por plantel.

  **2026-09-13 — segunda vuelta a pedido del cliente: alta a nivel de
  club con checkboxes por categoría.** El botón "Agregar jugador" de un
  plantel global ahora lleva a una nueva pantalla a nivel de **club**
  (`/clubs/{club}/players/create`), no del plantel puntual:
  - Un checkbox por cada plantel del club (agrupados por categoría/grupo),
    para inscribir al jugador en varias categorías **en un solo envío**.
  - Los checkboxes están **deshabilitados hasta que se carga la fecha de
    nacimiento** (reactivo con Alpine), y entre los habilitados, solo
    quedan tildables los que corresponden a categorías donde puede jugar
    según su edad (igual o superior a la que le toca) — el cálculo se
    rehace en el navegador con cada tecla en el campo de fecha, usando el
    `birth_year_to` de cada categoría ya cargado en la página.
  - **Cambio de comportamiento consciente:** en este formulario la fecha
    de nacimiento pasó a ser **obligatoria** (a diferencia del resto del
    sistema, donde sigue opcional) — sin ella no hay con qué habilitar
    ningún checkbox, así que no tiene sentido dejarla vacía acá. El
    formulario viejo a nivel de un solo plantel (`teams/{team}/players/create`)
    se mantiene intacto para cuando se quiere agregar rápido a un solo
    plantel sin saber todavía la fecha de nacimiento.
  - El primer plantel marcado queda como su plantel "principal"
    (`players.team_id` directo); el resto se vincula vía `player_team` —
    misma lógica de antes, ahora aplicada a varios de una sola vez.
  - La validación de elegibilidad se re-verifica en el servidor
    (`ClubPlayerRequest`) para cada plantel tildado — el cálculo del
    navegador es solo para la experiencia, nunca la única barrera.
  - Se centralizó la búsqueda de "¿este documento ya existe?" en
    `Player::findForOrganizer()`, reutilizada ahora por los tres lugares
    que la necesitaban (antes duplicada).
  Probado con 4 tests nuevos (440 en total) + de punta a punta contra
  datos reales de Faudis (LEMZ SPORT): un jugador nuevo quedó inscrito en
  Infantil y Pre-Juvenil en un solo envío, y un intento de sumarlo a una
  categoría no elegible (por edad) se bloqueó correctamente sin crear
  nada.

  **2026-09-13 — tercera vuelta, ícono de advertencia + cierre del hueco
  en la edición de jugador (ambos a pedido del cliente):**
  1. **Ícono ⚠** en los planteles (y en el encabezado de cada categoría
     desplegable) que tengan algún jugador sin fecha de nacimiento — tanto
     en `/clubs` como en la ficha de un club. Nuevo método
     `Team::idsWithIncompletePlayers()` (revisa ambas fuentes, `team_id`
     legacy y pivote `player_team`, sin N+1). Con los datos reales de
     Faudis detectó correctamente 8 planteles (los únicos 7-8 que
     realmente tienen jugadores cargados hoy, los 112 sin fecha de
     nacimiento).
  2. **Bug real que reportaste:** al completar la fecha de nacimiento de
     un jugador del backfill desde su ficha de edición, no aparecía forma
     de sumarlo a otra categoría — la elegibilidad ya funcionaba, pero
     no había ningún checkbox ahí para aprovecharla. Agregado
     `Player::candidateTeamsForEnrollment()` (planteles del mismo club a
     los que todavía no está anotado) + checkboxes en la ficha de edición,
     mismo patrón reactivo (deshabilitados hasta cargar la fecha, y entre
     los habilitados solo los que corresponden por edad). Un solo envío
     ahora completa la fecha **y** lo suma a la categoría nueva.
  Ambos con desplegables animados (`x-collapse` de Alpine, ya incluido en
  Livewire, en vez del `<details>` nativo sin animación) y checkboxes
  usando `flux:checkbox` en vez de `<input>` crudo, a pedido del cliente.
  Probado con 6 tests nuevos (444 en total) + de punta a punta contra un
  jugador real de Faudis sin fecha de nacimiento (Pablo Jimenez, Lemz
  Sport): se le cargó la fecha y se lo sumó a Pre-Juvenil en un solo
  envío, después revertido para no alterar sus datos reales.

---

## Decisiones confirmadas

1. **Club vs. Plantel** — existe un `Club` (ej. "Nilmar") que puede tener
   **varios planteles**, uno por combinación categoría+grupo en la que
   participe (0, 1 o hasta el número de grupos de esa categoría). Ejemplo
   real dado: Nilmar tiene 1 plantel en Cebollita (quedó en grupo B) y 2
   planteles en Infantil ("Nilmar (A)" y "Nilmar (B)"). Esto agregó la
   entidad `Club` que no estaba en la primera versión del plan.

2. **Identidad de categoría entre torneos** — mismo nombre = misma
   categoría, siempre y cuando sea del mismo organizador (`user_id`) —
   cada organizador tiene su propio catálogo, no se comparte entre
   usuarios. Estadísticas/posiciones/calendario/inscritos siguen siendo
   por torneo, solo el catálogo (nombre, rango de edad, si usa grupos) se
   reutiliza.

3. **Identidad de club entre torneos** — mismo nombre = mismo club (misma
   lógica de scope por `user_id` que categorías).

4. **Jugadores sin fecha de nacimiento** — `birth_date` queda opcional
   (nullable) y se marca con un aviso/banner de dato incompleto en la
   ficha del jugador. **Actualizado:** además, mientras falte el dato, el
   jugador **no puede sumarse a ninguna categoría nueva** — se queda
   únicamente con la categoría que ya trae del backfill (heredada de su
   plantel actual), hasta que se cargue la fecha de nacimiento.

5. **Categoría más grande sin tope** — no existe una categoría "libre" sin
   tope — la más grande que manejan es Juvenil (2007–2008). En la práctica
   todas las categorías tienen `birth_year_from` y `birth_year_to`
   definidos.

   **2026-09-12 — rango real cargado, ahora vía el backfill:** el cliente
   compartió la tabla oficial de LIFUTGUA (régimen de edades firmado por
   Faudis, campeonato 2026) con el año de nacimiento exacto de las 9
   categorías reales, guardada en
   [`faudis-rango-edades-2026.json`](faudis-rango-edades-2026.json).
   Primero se cargó a mano (tinker) en la copia local para probar la regla
   de elegibilidad; después, a pedido del cliente, se integró al comando
   de backfill como una opción más (`--ages=`), con el mismo patrón que
   `--aliases=` — un archivo confirmado a mano, nunca adivinado, aplicado
   tanto al crear una categoría nueva como al reutilizar una que ya
   existía (auto-reparable: si el archivo de edades llega después de que
   la categoría ya se creó, alcanza con volver a correr el comando).
   Confirmado con `Player::ageEligibleForCategory()` contra un jugador de
   prueba de cada edad (un nacido en 2012 puede Infantil/Pre-Juvenil/
   Juvenil, no las más chicas) y con el comando real corrido dos veces
   contra la copia local (`tournaments:backfill-global-catalog --ages=...`)
   sin duplicar ni pisar nada. **Falta correrlo contra la base de
   producción real** cuando llegue ese momento — mismo comando, mismos
   archivos.

   De paso se encontró y corrigió un efecto secundario real: un jugador
   dado de alta **después** del corte al esquema nuevo (vía el flujo de
   Jugadores, T01-27) tiene su `team_id` apuntando directo a un plantel
   global, sin fila en `player_team` — el backfill/verificación lo
   marcaban por error como "sin resolver". Corregido en
   `GlobalCatalogBackfillService::backfillPlayerLinks()` y
   `VerifyGlobalCatalog::checkNoOrphanPlayers()` para reconocer ese caso
   como correcto. 3 tests nuevos (433 en total).

6. **Fusión de planteles entre torneos** — cuando el mismo club+categoría+
   grupo aparece en más de un torneo, se fusiona en un solo `Team` global
   (con todos sus jugadores vinculados vía `player_team`), en vez de crear
   una fila nueva por torneo. Justificación: solo hay un usuario real en
   producción con datos que importan (Faudis Enrique Palacio Cortina, el
   resto son cuentas de prueba/demo/admin), y la fusión no pierde
   información histórica de partidos (que referencia `player_id`
   directo).

7. **Jugador vinculado a varios planteles, no un solo `team_id`** —
   corrección sobre la primera versión de este documento: se había
   diseñado `category_player` (solo "elegibilidad" abstracta), pero eso no
   lograba que el jugador apareciera realmente en la nómina de una segunda
   categoría — no resolvía el problema que reportó el cliente. Se
   reemplazó por `player_team` (muchos a muchos real, con dorsal opcional
   por vínculo), que si permite que un mismo jugador esté en dos planteles
   a la vez sin cargarlo dos veces.

8. **Un club puede tener más de un plantel en la misma categoría/grupo**
   — corrección encontrada al revisar el dump real: la categoría "Baby" de
   Faudis no usa grupos (compite en una sola tabla), pero un club como
   Nilmar igual puede necesitar **dos plantillas** ahí porque no le entran
   todos los chicos en una sola — algo que puede pasar en cualquier
   categoría/grupo, no solo en las que no usan grupos. El backfill (y el
   esquema, T01-05) identifican un plantel por `(club, categoría, grupo,
   **nombre del plantel**)`, no solo `(club, categoría, grupo)` — así dos
   plantillas del mismo club nunca colapsan en una sola por compartir club/
   categoría/grupo.

9. **Nombres de clubes inconsistentes en los datos reales de Faudis** —
   el dump real mostró 3 tipos de variantes de nombre, confirmadas por
   Daniel:
   - Mismo club, plantillas distintas por el motivo de la decisión #8 (ej.
     "NILMAR", "NILMAR (A)", "NILMAR (B)") → se fusionan como un solo
     `Club`, cada plantilla se mantiene separada como `Team` propio.
   - Mismo club, solo cambia el uso del punto en la sigla (ej. "GUAJIRA
     F.C" / "GUAJIRA FC") → se fusionan como un solo `Club`.
   - Nombre parecido por compartir el nombre del pueblo (Maicao), pero son
     clubes genuinamente distintos ("MAICAO", "MAICAITO", "DPTVO
     MAICAITO", "UNION MAICAO", "SPORTING MAICAO") → **nunca** se
     fusionan.
   El mapeo exacto confirmado para el organizador de Faudis (`user_id=4`
   en el dump que se probó) queda documentado en
   [`club-aliases-faudis.json`](club-aliases-faudis.json) — es un mapeo
   explícito confirmado a mano, el backfill nunca adivina esto solo (ver
   T01-15/16).
