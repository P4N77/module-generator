# sodeker/module-generator

Generador de módulos DDD para el ecosistema **Sódeker** (Laravel 11/12 + Inertia/Vue
sobre plantilla Velzon, multi-tenant con repositorio **Suite**).

Un solo comando, `php artisan make:module`, genera un módulo CRUD completo:

- **Domain**: `Entities`, `Repositories` (interfaces), `Exceptions`.
- **Application**: `Commands`, `Handlers` (CQRS), `DTOs`, `Services`.
- **Infrastructure**: `Models` (Eloquent), `Repositories` (Eloquent), `Http`
  (`Controllers`, `Requests`, `Routes`).
- **DataBridge**: contratos Shared `List{Plural}Contract` + `Match{Plural}RowContract`
  con su DTO, services y repositorio de listado (paginación/búsqueda opcional),
  para exponer el listado del módulo a otros módulos sin acoplarse.
- **Frontend**: páginas Inertia/Vue `Index/Create/Edit/Show`.
- **Suite**: migración (en `database/migrations/tenant/{proyecto}/{Modulo}/`) y,
  opcionalmente, un seeder (`database/seeders/tenant/{proyecto}/{Plural}Seeder.php`),
  con selección interactiva del proyecto destino.
- Registra el `ServiceProvider` del módulo en `config/app.php`.

## Instalación

El repo es **público**, así que no necesitas token, SSH ni ser colaborador. Solo
hay que declarar el repositorio (no está en Packagist) e instalar:

```bash
# 1. Declarar el repositorio (sin editar el JSON a mano). "no-api" evita la API
#    de GitHub y clona por HTTPS directo (sin límites de rate ni token).
composer config repositories.module-generator \
  '{"type":"vcs","url":"https://github.com/P4N77/module-generator.git","no-api":true}'

# 2. Requerir el paquete (es una herramienta de desarrollo => --dev)
composer require --dev sodeker/module-generator:^1.0
```

El `ServiceProvider` se auto-descubre (Laravel package discovery).

> En Docker/CI corre estos comandos **dentro** del contenedor (donde está el
> `composer.json`).

> Nota sobre rate limit: si omites `"no-api": true`, Composer usa la API de
> GitHub, que sin token limita a ~60 peticiones/hora. Con `"no-api": true` (o
> configurando un token con `composer config --global --auth github-oauth.github.com <TOKEN>`)
> se evita ese límite.

### Desarrollo local del propio paquete (path repository)

Si trabajas sobre el paquete junto al proyecto consumidor:

```jsonc
"repositories": [
    { "type": "path", "url": "../sodeker-module-generator", "options": { "symlink": true } }
]
```
```bash
composer require --dev sodeker/module-generator:@dev
```

## Uso

```bash
# Módulo simple (tabla sin prefijo => "novelties")
php artisan make:module Novelty

# Con prefijo de tabla (=> "irs_novelties")
php artisan make:module irs/Novelty
```

El comando pregunta de forma interactiva:

1. **Ruta de Suite** (absoluta o relativa al proyecto; default configurable).
2. **Proyecto de migraciones** (lista las carpetas de `migrations/tenant` + opción `otro`).
3. **¿Agregar seeder?** Si sí, se crea en el mismo proyecto que la migración.

## Configuración

Publica el config para sobreescribir las convenciones por proyecto:

```bash
php artisan vendor:publish --tag=module-generator-config
```

`config/module-generator.php`:

| Clave | Default | Descripción |
|-------|---------|-------------|
| `module_namespace` | `App\Modules` | Namespace raíz de los módulos. |
| `shared_contracts_namespace` | `App\Shared\Contracts` | Namespace de los contratos DataBridge. |
| `table_prefix` | `''` | Prefijo de tabla por defecto (si no se usa `prefijo/Modulo`). |
| `connection` | `tenant` | Conexión Eloquent de los modelos (`null` = default). |
| `route_middleware` | `[web, auth:sanctum, config('jetstream.auth_session'), verified, tenant.selected, tenant]` | Middleware del grupo de rutas. Entradas con `(` o `::` se emiten como expresión PHP. |
| `pages_path` | `Pages` | Carpeta de páginas Inertia bajo `resources/js`. |
| `suite_default_path` | `../Suite` | Ruta por defecto del repo Suite en el prompt. |

## Dependencias del proyecto consumidor

El código generado asume que existen en el proyecto:

- `App\Shared\Infrastructure\Database\Repositories\BaseSearchRepository` (motor de
  filtros/paginación compartido del ecosistema).
- Componentes Vue de la plantilla Velzon (`@/Layouts/main.vue`,
  `@/Components/page-header.vue`, `@/Components/DataTable.vue`) y composables
  (`useFetchPetition`, `useSweetAlert`).
- Middleware `tenant.selected` y `tenant` (multi-tenant) si se conservan los defaults.
- `config/app.php` con el array `providers` en estilo legacy (para el auto-registro).
