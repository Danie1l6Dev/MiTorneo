# Reglas de negocio — MiTorneo

Documento vivo. Registra qué reglas de negocio implementadas en el sistema
fueron **confirmadas explícitamente por el cliente** y cuáles fueron
**decisiones tomadas por el desarrollo** (por practicidad, por el estado de
la beta en producción, o simplemente porque nunca se habló el tema). El
objetivo es que una futura revisión de código no trate una regla ya
confirmada como sospechosa, ni de por sentada una que en realidad nunca se
validó.

Origen: revisión de negocio hecha el 2026-09-16, con las respuestas del
cliente relayadas el mismo día.

Estados usados:

- ✅ **Confirmada por el cliente**
- 🛠️ **Decisión de desarrollo** (no la pidió el cliente, pero tiene una
  razón concreta registrada)
- ❓ **Abierta** — ni confirmada ni descartada

## Sanciones y disciplina

| # | Regla | Estado | Dónde vive en el código |
|---|-------|--------|--------------------------|
| 1 | Una multa económica (`fine_amount`) solo puede registrarse para un Director Técnico, nunca para un jugador. | ✅ | `SanctionResolveRequest::withValidator()` |
| 2 | Una doble amarilla en el mismo partido se resuelve automáticamente en 1 fecha de suspensión, sin pasar por comité. | ✅ | `Sanction::classifyCardTally()`, `SanctionService` |
| 3 | Una roja directa nunca asume su propia duración: queda **Pendiente** hasta que el Comité Directivo la resuelve. | ✅ | `Sanction::classifyCardTally()`, `SanctionStatus::Pending` |
| 4 | Una sanción sigue a su sujeto (jugador o DT) a través de equipos, clubes distintos e incluso torneos futuros — no se limita al torneo/categoría donde se originó. | ✅ | `Sanction::teamMatchSequence()` / `subjectTeamIds()` |
| 5 | Tope máximo de 50 fechas por sanción. | ✅ | `SanctionResolveRequest` (`matches_banned`: `max:50`) |

## Expulsión de equipos

| # | Regla | Estado | Dónde vive en el código |
|---|-------|--------|--------------------------|
| 6 | Expulsar un equipo del torneo fuerza un walkover **0-3** en todos sus partidos aún no jugados. | ✅ | `TeamExpulsionService::expel()` |

## Tabla de posiciones y desempates

| # | Regla | Estado | Dónde vive en el código |
|---|-------|--------|--------------------------|
| 7 | Puntaje: 3 puntos por victoria, 1 por empate, 0 por derrota (fijo, no configurable por categoría). | ✅ | `StandingsService::calculate()` |
| 8 | Orden de desempate: puntos → diferencia de gol → goles a favor → enfrentamiento directo entre el grupo empatado. Sin fair play ni sorteo. En cruces de eliminación directa a doble partido: marcador global + tiempo extra + penales, **sin regla de gol de visitante**. | ✅ | `StandingsService::order()`, `TournamentMatch::tieWinnerTeamId()` |

## Fases eliminatorias

| # | Regla | Estado | Dónde vive en el código |
|---|-------|--------|--------------------------|
| 9 | Semifinal necesita exactamente 4 clasificados, Final exactamente 2 (fijo). Actualmente no existe partido de 3er/4to puesto en ningún lado del sistema. | ❓ Abierta (ver ítem 14) | `PhaseEligibilityService::FIXED_QUALIFIER_TARGETS` |
| 14 | Partido por el 3er/4to puesto: el cliente no dijo que no lo hubiera, pero tampoco lo pidió. Candidato a funcionalidad opcional ("plus") al crear una fase eliminatoria — no implementar sin que se pida explícitamente. | ❓ Abierta | No implementado |

## Elegibilidad de jugadores

| # | Regla | Estado | Dónde vive en el código |
|---|-------|--------|--------------------------|
| 10 | Sin techo de edad para "jugar hacia arriba": un jugador joven siempre puede pasar a una categoría de mayores, sin límite. Confirmado con el ejemplo del cliente (jugador de 15-16 años en primera división, caso real tipo Lamine Yamal). | ✅ | `Player::ageEligibleForCategory()` / `promotionCandidateTeams()` |
| 11 | Categorías mixtas: las chicas pueden ser hasta `female_extra_birth_years` años mayores que el corte de edad de los varones. | ✅ | `categories.female_extra_birth_years`, `Player::ageEligibleForCategory()` |

## Registro de eventos de partido (goles/tarjetas)

| # | Regla | Estado | Dónde vive en el código |
|---|-------|--------|--------------------------|
| 12 | El marcador oficial (`home_score`/`away_score`) y los eventos de gol quedan totalmente desacoplados: un desajuste entre ambos solo muestra una advertencia, nunca bloquea. | 🛠️ Decisión de desarrollo — el primer beta del sistema salió a producción antes de que existiera el módulo de eventos, y el cliente necesitaba seguir cargando marcadores sin que el nuevo módulo bloqueara ese flujo. Revisar si esta razón sigue vigente antes de endurecer la regla. | `TournamentMatchController`, `MatchEventRequest`/`MatchEventBatchRequest` |
| 13 | El minuto del evento (`match_events.minute`) es opcional y no se muestra en ninguna parte de la interfaz. | 🛠️ Decisión de desarrollo — no hace falta llevar el minuto a minuto por el momento, no fue un pedido explícito de no hacerlo. Si en el futuro se pide un acta de partido con minutos, este es el campo a reactivar. | `match_events` (migración), `MatchEventRequest` |

## Convenciones de este documento

- Cada vez que una revisión de negocio detecte una regla implícita nueva, agregarla acá con su número siguiente y su estado.
- Una regla marcada ✅ no debe cuestionarse ni "corregirse" sin que el cliente la cambie explícitamente primero.
- Una regla marcada 🛠️ es candidata a revisar/endurecer si cambian las circunstancias que la motivaron (ver la columna de razón).
- Una regla marcada ❓ nunca debe implementarse por iniciativa propia — hay que confirmar alcance con el cliente antes de tocar código.
