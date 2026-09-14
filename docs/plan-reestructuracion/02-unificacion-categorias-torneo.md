# Tema 02 — Un solo camino: categorías de torneo SIEMPRE del catálogo

**Origen:** Tema 01 dejó, a propósito, dos sistemas de categoría conviviendo
en un torneo: las "propias" (creadas directo en el torneo, con fases/
calendario/resultados — el camino de siempre) y las "del catálogo"
(inscripción vía `tournament_category`/`tournament_team`, sin fases todavía
— T01-24/25). Era el andamiaje intermedio correcto para no arriesgar
producción de una. El cliente ahora pidió cerrar esa duplicación: **un
torneo nunca crea su propia categoría — solo elige, del catálogo del
organizador, cuáles participan.** Condición explícita: lo que ya está en
producción (fases armadas, calendarios ya generados, resultados guardados)
tiene que quedar funcionando exactamente igual.

## Hallazgo clave (por qué esto es más seguro de lo que parece)

`CompetitionPhase`, `Group` y `Team` ya tienen su propia columna
`tournament_id`, además de `category_id` — nunca dependieron de que una
categoría perteneciera en exclusiva a un torneo para saber de qué torneo
son. Eso permite **promover una categoría/grupo/plantel existente al
catálogo global cambiándole nada más que `tournament_id → NULL` y
`user_id → dueño`, conservando su mismo id** — cero cambios en las filas de
`competition_phases`, `matches`, `match_events`, `match_participants`,
`sanctions`, `league_schedules`: todas siguen apuntando exactamente a la
misma `category_id`/`team_id` de siempre.

Esto es distinto (y más simple/seguro) que lo que hizo el backfill de Tema
01 (`GlobalCatalogBackfillService`): ese creaba una fila **nueva** en
paralelo y solo las *vinculaba* con un pivote, dejando la fila legacy
intacta pero separada. Acá la idea es la fila legacy **se convierte** en la
global — no hay dos filas para la misma categoría real.

## Chequeo contra datos reales

Un solo caso en toda la base tiene el mismo nombre de categoría repetido
entre dos torneos del mismo organizador — y es en los datos de prueba de
Daniel (`Teterito`, torneos demo 1 y 4), no en los de Faudis. **En
producción real no hay ningún caso de "esta categoría ya vive en más de un
torneo"** que obligue a fusionar de verdad fases/calendarios de dos torneos
distintos en una sola categoría. Igual el plan deja un camino explícito
para ese caso (T02-01) por si aparece alguno que no se vio en este chequeo,
o para el futuro.

---

## Acciones — Migración de datos (producción)

Sigue el patrón de [00 — Estrategia general de migración de
datos](00-estrategia-migracion-datos.md). A diferencia del backfill de Tema
01, acá el modo `--execute` si muta filas legacy (les cambia
`tournament_id`/`user_id`/`club_id`) — nunca les cambia el `id`, así que
ninguna fila que las referencia (`competition_phases`, `matches`,
`match_events`, `match_participants`, `sanctions`, `league_schedules`,
`players.team_id`, `player_team`, `competition_phase_team`) necesita
tocarse.

- [x] **T02-01** — Comando Artisan `tournaments:promote-categories-to-catalog`
  (con `--dry-run` por defecto, igual que T01-15/16), por cada organizador:
  1. Por cada `Category` con `tournament_id` no nulo: si es la primera (o
     única) con ese `(user_id, name)`, se promueve **en el lugar**
     (`tournament_id = NULL`, `user_id = dueño`) y se crea su fila
     `tournament_category` apuntando al torneo del que vino. Si ya existe
     otra categoría global con ese mismo `(user_id, name)` (el caso raro de
     arriba), se promueve igual pero con el nombre desambiguado
     (`"{name} ({nombre del torneo})"`) — nunca se fusiona con la ya
     promovida, para no mezclar fases/calendarios de dos torneos distintos
     bajo una sola categoría. Reportado explícitamente en el dry-run para
     que lo veas antes de que se ejecute.
  2. Por cada `Group` de una categoría recién promovida: `tournament_id = NULL`
     (ya cuelga de `category_id`, que no cambia).
  3. Por cada `Team` de una categoría recién promovida: se le resuelve/crea
     su `Club` (misma lógica de alias por nombre ya usada en
     `GlobalCatalogBackfillService::backfillClubsAndTeams`, reutilizada acá
     tal cual) y se le setea `club_id` + `tournament_id = NULL` — también en
     el lugar, mismo id de `Team`.
  4. Se crea la fila `tournament_team` correspondiente (torneo original +
     este plantel) para que T01-25 ("Elegir planteles") muestre de entrada
     exactamente el roster que el torneo ya tenía.
  5. `Player`/`player_team`/`competition_phase_team`/resultados: **no se
     tocan** — siguen apuntando al mismo `team_id`/`category_id` de
     siempre, que ahora resulta ser global en vez de propio del torneo.
  Idempotente: correrlo de nuevo sobre filas ya promovidas no hace nada
  (detecta `tournament_id` ya nulo y lo reporta como "ya promovida").
  **✅ Implementado y probado (2026-09-12)**: `CategoryTournamentPromotionService`
  + comando `tournaments:promote-categories-to-catalog` (con `--dry-run`,
  `--aliases`, `--ages`, mismo formato que T01-15/16). 11 tests nuevos,
  incluyendo el caso de dos torneos con el mismo nombre de categoría
  (nunca se fusionan, se desambigua con el nombre del torneo) y que una
  fase + su partido sobreviven la promoción con exactamente el mismo id.

- [x] **T02-02** — *(Verificar)* Comando nuevo
  `tournaments:verify-category-promotion --snapshot=archivo.json` (antes de
  T02-01) y `--compare=archivo.json` (después) — guarda/compara un
  fingerprint de cada fase (torneo, categoría, orden, campeón) y cada
  partido (fase, equipos, marcador) por id. No se avanza a T02-04 (sacar la
  UI legacy) hasta que esto dé sin diferencias contra la copia local de
  Faudis.
  **✅ Implementado y probado (2026-09-12)**: 2 tests nuevos (detecta un
  resultado cambiado, no reporta nada cuando no cambió nada).

  **Corrida real completa contra la copia local de Faudis (2026-09-13):**
  se limpiaron primero los datos que había dejado el backfill viejo de
  Tema 01 en la copia local (nunca existieron en producción real -- se
  guardó un dump completo antes por las dudas), dejándola en el mismo
  punto de partida que tiene hoy la producción real. Con eso:
  1. `--snapshot` antes de promover (13 fases, 702 partidos reales).
  2. `--dry-run` de T02-01 revisado a mano: 15 categorías (9 de Faudis con
     sus rangos de edad reales, el resto de datos de prueba de Daniel),
     ninguna desambiguada, los alias de club (NILMAR, MAICAO F.C, JAIR
     PINTO, PANTERAS NEGRAS) resolviendo bien y sin fusionar los planteles
     (A)/(B) entre sí.
  3. T02-01 ejecutado de verdad.
  4. `--compare` contra el snapshot: **sin diferencias** -- las 13 fases y
     702 partidos quedaron con el mismo torneo, categoría, equipos y
     marcador exactos.
  5. Navegación real (con sesión de Faudis) de la ficha del torneo, la
     ficha de una categoría con fases reales (TETERITO, 32 partidos), la
     ficha de esa fase, "Elegir planteles", un grupo, un plantel, el portal
     público sin sesión, y las sanciones -- encontró y corrigió **3 bugs
     reales** que ningún test unitario había visto (arreglados y con test
     de regresión cada uno, ver T02-03/T02-07):
     - La sección "Fases" de la ficha de categoría quedaba oculta para
       toda categoría promovida (condición vieja de Tema 01 que ya no
       aplica) -- esto **escondía fases y partidos reales ya jugados**,
       aunque no los tocaba.
     - La ficha de una fase (`phases/show.blade.php`) leía
       `$phase->category->tournament->name` -- con la categoría promovida
       esto **rompía la página con un error 500** en cualquier fase
       existente.
     - `SanctionPolicy` autorizaba vía `$sanction->team->tournament->user_id`
       -- con el plantel promovido esto **rompía con un error 500** al
       ver o resolver cualquier sanción.
  Los tres se debían al mismo patrón: código que llegaba al torneo
  atravesando `category`/`team` en vez de usar la columna `tournament_id`
  propia de la fase/partido/sanción (que nunca cambia). Se revisó todo el
  código y las vistas en busca de ese mismo patrón (`grep` de
  `->tournament->` en `app/` y `resources/views/`) para no dejar ningún
  otro caso escondido.

- [x] **T02-07** — *(no estaba en la versión original, apareció en la
  corrida real de arriba)* Corregidos los 3 bugs de la lista de T02-02:
  quitado el `@if ($category->tournament_id)` que ocultaba la sección
  "Fases" en `categories/show.blade.php`; `phases/show.blade.php` usa
  `$phase->tournament` en vez de `$phase->category->tournament`;
  `SanctionPolicy` usa `$sanction->match->tournament` en vez de
  `$sanction->team->tournament`. También se revisaron (sin necesitar
  cambios, por estar ya protegidos con `?->` o una condición previa)
  `categories/edit.blade.php`, `groups/show.blade.php` y
  `teams/show.blade.php`.
  **✅ Implementado y probado (2026-09-13)**: 3 tests nuevos, cada uno
  reproduce el bug real encontrado (categoría promovida con fase oculta,
  página de fase que crasheaba, sanción que crasheaba) antes de la
  corrección.

## Acciones — Motor de competencia (código)

Hoy varios métodos asumen "una categoría le pertenece a un solo torneo" y
filtran solo por `category_id` — válido mientras esa suposición era cierta,
deja de serlo en cuanto una categoría es del catálogo y (a futuro) puede
estar inscrita en más de un torneo a la vez.

- [x] **T02-03** — `PhaseEligibilityService`: acotar por torneo además de
  categoría en los métodos que hoy solo miran `category_id`:
  - `canCreateFirstPhase()` — hoy `$category->competitionPhases()->doesntExist()`
    a secas; debe ser "no existe ya una fase de ESTE torneo para esta
    categoría" (`category_id` + `tournament_id`).
  - `firstPhaseTypeOptions()` — hoy cuenta `$category->teams()->count()`
    (todos los planteles de la categoría, sin importar torneo); debe contar
    solo los planteles **inscritos en este torneo** vía `tournament_team`.
  - `nextPhase()`/`previousPhase()` — hoy encadenan fases solo por
    `category_id`+`order`; deben acotar también por `tournament_id`, para
    que dos torneos usando la misma categoría del catálogo no mezclen sus
    cadenas de fases.
  **✅ Implementado y probado (2026-09-12)**, con alcance mayor al
  documentado originalmente — al implementarlo aparecieron 3 roturas reales
  que el análisis inicial no había visto:
  1. `CompetitionPhaseController::store()` seteaba
     `$phase->tournament_id = $category->tournament_id` directo — con toda
     categoría promovida (`tournament_id` siempre nulo), esto hubiera creado
     **toda fase nueva sin torneo asignado**, en cualquier torneo, no solo
     en el caso raro de categoría compartida. Arreglado agregando
     `CompetitionPhaseController::resolveTournament()` (usa el
     `tournament_id` legacy si todavía existe; si no, resuelve por el
     pivote `tournament_category`, y rechaza con 422 si la categoría está
     en más de un torneo — soportar una fase por torneo para una categoría
     realmente compartida queda como trabajo futuro explícito, no
     implementado).
  2. `CompetitionPhasePolicy::create()` bloqueaba directamente crear una
     fase si `category->tournament_id` era nulo — con todo promovido,
     hubiera bloqueado crear fases en todos lados. Arreglado: ahora exige
     dueño (`ownerId()`) + que la categoría esté atada a algún torneo
     (legacy o vía pivote).
  3. `PublicCategoryController` (portal público) comparaba
     `category->tournament_id !== $tournament->id` — con todo promovido
     esa comparación siempre falla → 404 en la página pública de
     **cualquier** categoría. Ver T02-06.
  Se agregó `PhaseEligibilityService::eligibleTeams()` (con el mismo
  soporte dual legacy/catálogo) para no duplicar esa lógica entre el
  servicio y el controller. 5 tests nuevos que fijan el comportamiento con
  una categoría realmente compartida por 2 torneos (cadenas de fases
  independientes, conteo de equipos acotado, y el 422 de "todavía
  ambiguo").

- [x] **T02-04** — Sacar de rutas/UI la creación de categoría "propia" de un
  torneo: `tournaments.categories.create/store` (y su vista) dejan de
  existir — el único camino para que un torneo tenga una categoría pasa a
  ser T01-24 (elegir del catálogo), tanto para torneos nuevos como viejos.
  `CategoryController::createForTournament/storeForTournament` se eliminan
  (ya no queda nada que los use tras T02-01).
  **✅ Implementado y probado (2026-09-12)**: rutas y métodos eliminados,
  vista `categories/create-for-tournament.blade.php` borrada,
  `CategoryPolicy::create()` simplificada (ya no recibe un `Tournament`
  opcional). 2 tests existentes actualizados al nuevo flujo (crear
  categoría en el catálogo + inscribirla, en vez de crearla directo en el
  torneo).

- [x] **T02-05** — Unificar la ficha del torneo (`tournaments/show.blade.php`):
  una sola sección "Categorías" (ya no "Categorías" + "Categorías del
  catálogo" separadas), que es simplemente lo que hoy arma T01-24/25, con
  el conteo de planteles y acceso a fases igual que antes.
  **✅ Implementado y probado (2026-09-12)**: sección única, cada fila
  enlaza a la ficha de la categoría (`categories.show`, donde ya se
  gestionan sus fases) y a "Elegir planteles". Los stat-pills del
  encabezado (categorías/equipos) pasan a contar `globalCategories`/
  `globalTeams` en vez de las relaciones legacy (que tras T02-01 siempre
  dan 0). Ya no queda ningún texto de "todavía no soporta fases" -- ahora
  sí las soporta.

- [x] **T02-06** — *(no estaba en la versión original de este documento,
  apareció al implementar T02-03)* Portal público
  (`PublicCategoryController`): el chequeo de pertenencia y las relaciones
  `teams`/`competitionPhases` que carga (sin acotar por torneo) se
  reescribieron con el mismo soporte dual legacy/catálogo que el resto de
  T02-03, para que la página pública de una categoría siga funcionando
  igual hoy y no filtre datos de otro torneo el día que una categoría se
  comparta entre dos.
  **✅ Implementado y probado (2026-09-12)**: incluido en los 5 tests de
  T02-03 (uno prueba específicamente que el portal público de un torneo
  solo muestra SU roster cuando la categoría está compartida).

- [x] **T02-09** — *(a pedido del cliente, después de ver el diseño de
  T02-05 en cards)* La sección "Categorías" de la ficha del torneo vuelve
  al diseño de cards (ícono, nombre, plantel inscrito, badge de estado)
  en vez de la lista de filas. Implementado con markup propio (no el
  componente compartido `x-ui.entity-card`) usando el patrón "enlace
  estirado": un `<a>` invisible cubre toda la card para poder hacer click
  en cualquier parte y navegar a la categoría, mientras "Elegir planteles"
  y "Quitar" quedan como botones reales por encima (`z-10`) — así todo
  vive visualmente dentro de la card sin anidar un `<button>` dentro de un
  `<a>` (HTML inválido, y el click no sale nunca bien).
  **✅ Implementado y probado (2026-09-13)**.

- [x] **T02-10** — *(a pedido del cliente)* Una vez que una categoría ya
  tiene una fase iniciada en un torneo, su plantel (`tournament_team`) para
  ese torneo queda **bloqueado**: no se puede agregar/quitar equipos
  (`TournamentCategoryController::updateTeams`) ni quitar la categoría del
  torneo (`::destroy`), porque el cuadro/calendario/grupos de esa fase ya
  se armaron sobre esa lista exacta de equipos -- cambiarla después dejaría
  partidos apuntando a un equipo que ya no está "en" el torneo, o una fase
  sin uno que sí está.
  - Server-side: ambos métodos rechazan la acción con un mensaje claro
    (`back()->with('error', ...)`) si `CompetitionPhase` ya tiene una fila
    para ese `(tournament_id, category_id)`.
  - UI: la ficha del torneo muestra el badge "Fase iniciada" y oculta el
    botón "Quitar" para esas categorías; el botón cambia de "Elegir
    planteles" a "Ver planteles". La pantalla de planteles
    (`tournaments/categories/teams.blade.php`) muestra un aviso y los
    checkboxes quedan deshabilitados sin botón de guardar.
  **✅ Implementado y probado (2026-09-13)**: 4 tests nuevos + verificado
  en vivo contra las 9 categorías reales de Faudis (todas ya tienen fase,
  todas aparecen bloqueadas correctamente).

- [x] **T02-11** — *(encontrado en una auditoría propia antes de
  desplegar, al preguntar "¿no falta nada más antes de producción?")*
  Varios lugares del motor de competencia armaban el roster de una
  categoría con `Category::teams()` a secas (todo lo que tiene esa
  categoría en el catálogo) en vez del roster que ESTE torneo inscribió
  (`tournament_team`) cuando no hay grupos ni un roster de fase propio:
  `StandingsService::tablesForPhase()`, `PhaseBoardService` (vista de
  calendario sin grupo), `LeagueScheduleController::store()` (genera los
  partidos reales) y `GenerateLeagueScheduleRequest` (validación del
  mínimo de equipos). Esto **no depende de que una categoría esté
  compartida entre 2 torneos** -- pasa apenas el catálogo de una categoría
  tenga más planteles que los elegidos para este torneo (dejar uno afuera
  a propósito en "Elegir planteles", o sumar un plantel nuevo al club
  después de haber elegido el roster). Sin el fix, ese plantel de más se
  cuela en el próximo calendario o tabla de posiciones que se genere.
  Solución: nuevo método `Category::teamsForTournament(Tournament)` (el
  mismo criterio dual legacy/catálogo que ya usaba
  `PhaseEligibilityService::eligibleTeams()`, que ahora delega a él) usado
  en los 4 lugares de arriba.
  **✅ Implementado y probado (2026-09-13)**: 2 tests nuevos (calendario y
  tabla de posiciones excluyen correctamente un plantel no inscripto).
  Chequeado contra los datos reales de Faudis: hoy sus 9 categorías tienen
  el catálogo y lo inscripto exactamente iguales (0 casos actuales), pero
  el fix protege cualquier alta de plantel futura.

- [x] **T02-12** — *(mismo barrido de auditoría que T02-11, este sí urgente)*
  `PublicTournamentController` -- la ficha pública de un TORNEO (no la de
  una categoría, ya arreglada en T02-06) seguía leyendo
  `$tournament->categories`/`teams_count` (la relación legacy) directo. En
  cuanto un torneo se promueve, esa relación queda **siempre vacía** --
  la página pública que cualquiera puede abrir sin iniciar sesión hubiera
  mostrado "este torneo todavía no tiene categorías" para cualquiera de
  tus 9 categorías reales de Faudis. Arreglado con el mismo patrón dual
  legacy/catálogo que el resto de T02-03/06 (un torneo es enteramente uno
  u otro, nunca mitad y mitad, porque T02-01 promueve todas sus categorías
  en una sola transacción).
  **✅ Implementado y probado (2026-09-13)**: 1 test nuevo + verificado en
  vivo contra el enlace público real del torneo de Faudis (sin iniciar
  sesión): ahora sí muestra BABY, CEBOLLITA, TETERITO, etc.

## Estado general

Todo lo documentado arriba está implementado, probado por la suite
automática (486 tests, 0 fallas) **y verificado de punta a punta contra una
copia real de los datos de producción de Faudis** (ver T02-02): categorías,
fases, calendario y resultados reales quedaron exactamente iguales después
de promover, y los bugs que solo aparecen con datos reales o con una
auditoría deliberada (nunca visibles con datos de prueba sintéticos
sueltos) ya están corregidos y cubiertos por tests. No queda ningún trabajo
de código pendiente para lo que está en alcance de este tema.

**Lo único que falta es decidir cuándo correr esto contra producción
real** — la promoción (T02-01) todavía no se ejecutó ahí, solo en la copia
local. Cuando se apruebe explícitamente, el paso es: backup de producción →
`tournaments:verify-category-promotion --snapshot` → `tournaments:promote-categories-to-catalog`
(revisando el `--dry-run` primero) → `--compare` contra el snapshot.

## Preguntas abiertas

Ninguna sobre el diseño — el caso de nombre repetido entre torneos (que
motivaría una decisión de fusionar o no) no aparece en los datos reales de
producción; si aparece alguno al correr el dry-run de T02-01 contra la
copia local completa, se te muestra ahí antes de decidir.

Sí queda pendiente, explícitamente fuera de alcance por ahora: una
categoría realmente compartida por 2+ torneos hoy puede recibir *inscripción*
en todos ellos, pero **crear una fase nueva para ella solo funciona
mientras esté en un solo torneo** (T02-03 lo rechaza con un error claro en
el caso ambiguo, en vez de adivinar). Ampliar esto es trabajo futuro, a
pedir explícitamente cuando haga falta.
