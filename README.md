# MiTorneo

Sistema de gestión de torneos deportivos: organizadores crean torneos,
categorías, clubes/planteles y jugadores; arman fases de competencia (liga,
grupos, eliminación directa), cargan resultados, eventos de partido
(goles/asistencias/tarjetas) y sanciones disciplinarias; y comparten un
portal público de solo lectura por torneo, sin necesidad de login.

Construido sobre Laravel + Livewire/Flux para uso real en producción por un
organizador (actualmente en uso por Faudis, liga de fútbol de Maicao,
Colombia).

## Stack

- **Backend:** PHP 8.4, Laravel 13, Laravel Fortify (auth + roles).
- **Frontend:** Livewire/Flux 2, Alpine.js (vía Livewire), Tailwind CSS 4, Vite.
- **Base de datos:** MySQL en producción, SQLite por defecto en desarrollo local.
- **Tests:** Pest.
- **Calidad:** Laravel Pint (estilo), Larastan/PHPStan (tipos).

## Requisitos

- PHP 8.4+
- Composer
- Node.js + npm

## Instalación

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite   # si usás SQLite (default en .env.example)
php artisan migrate --seed
npm install
npm run build
```

El seeder crea un usuario de prueba (`daniel@mitorneo.test` / `password`)
con tres torneos demo, cada uno en un estado distinto del flujo de
competencia (calendario por generar, sorteo de clasificados listo, cuadro de
eliminación directa completo) — ver [`database/seeders/DatabaseSeeder.php`](database/seeders/DatabaseSeeder.php).

O directamente con el script de Composer que hace todo lo anterior (sin seed):

```bash
composer run setup
```

## Desarrollo

```bash
composer run dev
```

Levanta en simultáneo el servidor de Laravel, el worker de colas y Vite en
modo watch.

## Tests y calidad de código

```bash
composer run test          # config:clear + lint:check + types:check + php artisan test
php artisan test           # solo los tests (Pest)
composer run lint          # aplica el formateo de Pint
composer run lint:check    # solo verifica, no modifica archivos
composer run types:check   # PHPStan / Larastan
```

## Documentación

- [`docs/reglas-de-negocio.md`](docs/reglas-de-negocio.md) — qué reglas de
  negocio del sistema (sanciones, desempates, elegibilidad de jugadores,
  etc.) están confirmadas explícitamente por el cliente, cuáles son
  decisiones de desarrollo y cuáles siguen abiertas. Consultar antes de
  "corregir" cualquier comportamiento que parezca una regla de negocio.
- [`docs/plan-reestructuracion/`](docs/plan-reestructuracion/README.md) —
  historial vivo de la reestructuración del modelo de datos (categorías/
  clubes/jugadores globales por organizador, en vez de anidados dentro de
  un torneo). Útil para entender por qué el esquema tiene columnas legacy
  (`tournament_id` en `categories`/`groups`/`teams`) conviviendo con las
  nuevas.
