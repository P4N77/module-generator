# sodeker/module-generator

Generador de módulos DDD para el ecosistema **Sódeker** (Laravel 11/12 + Inertia/Vue
sobre plantilla Velzon, multi-tenant con repositorio **Suite**).

Un solo comando, `php artisan make:module`, genera un módulo CRUD completo,
**alineado 1:1 con el estándar de maestras de Suite** (referencia
`CommunicationChannels` / `EmailTypes`):

- **Domain**: `Entities` (con `create()`/`update()`/`delete()`), `ValueObjects`
  (`{Modulo}Status`, enum `1`/`0`), `Repositories` (interfaces), `Exceptions`
  (extienden `ResourceNotFoundException`).
- **Application**: `Commands`, `Handlers` (CQRS, incluye `Validate{Modulo}CodeHandler`
  si el módulo lleva código), `DTOs` (`{Modulo}DTO`, `Save{Modulo}DTO`,
  `{Modulo}CollectionDTO` con `meta` paginado), `Services`.
- **Infrastructure**: `Models` (Eloquent, `fillable` sin timestamps),
  `Repositories` (Eloquent, con `codeExists()`), `Http` (`Controllers`,
  `Requests` con normalización/unicidad, `Routes`).
- **DataBridge**: contratos Shared `List{Plural}Contract` + `Match{Plural}RowContract`
  con su DTO, `List{Plural}Service` y repositorio de listado (paginación/búsqueda
  opcional), para exponer el listado del módulo a otros módulos sin acoplarse.
- **Frontend**: páginas Inertia/Vue `Index/Create/Edit/Show` (badge de estado,
  switch, `AccessDeniedCard`, validación de código, props en camelCase).
- **Campo `status`** obligatorio en toda la cadena; **`code`** opcional (se pregunta).
- **Dos tipos de módulo**: *usuario* (permisos Casbin por acción + menú) o
  *interno* (guard de la cuenta Sodeker/Developer, sin Casbin ni menú).
- **Suite**: migración (`database/migrations/tenant/{proyecto}/{Modulo}/`), un test
  Pest (`tests/Unit/{Plural}/List{Plural}ServiceTest.php`) y, opcionalmente, un
  seeder, con selección interactiva del proyecto destino.
- Registra el `ServiceProvider` del módulo en `config/app.php` e imprime los
  **pasos manuales** de permisos/menú (seeders + `SuiteConfigModeResolver`).

## Instalación

El repo es **público**, así que no necesitas token, SSH ni ser colaborador. Solo
hay que declarar el repositorio (no está en Packagist) e instalar:

```bash
# 1. Declarar el repositorio (sin editar el JSON a mano). "no-api" evita la API
#    de GitHub y clona por HTTPS directo (sin límites de rate ni token).
composer config repositories.module-generator \
  '{"type":"vcs","url":"https://github.com/P4N77/module-generator.git","no-api":true}'

# 2. Requerir el paquete (es una herramienta de desarrollo => --dev)
composer require --dev sodeker/module-generator:^1.0.3
```

El `ServiceProvider` se auto-descubre (Laravel package discovery).

## Uso

```bash
# Módulo simple (tabla sin prefijo => "novelties")
php artisan make:module Novelty

# Con prefijo de tabla (=> "irs_novelties")
php artisan make:module irs/Novelty
```

El comando pregunta de forma interactiva:

1. **¿El módulo lleva código (`code`)?** Si sí, agrega la columna única, el
   endpoint `validate-code`, `codeExists()` y la validación de unicidad en el
   frontend. Si no, el módulo es solo `description` + `status`.
2. **Tipo de módulo**: *usuario* (Casbin + menú) o *interno* (guard Sodeker/Developer,
   sin menú, acceso solo por URL).
3. **App destino** (`suite/sat/iris/tut/econnect/f16/fintegra`) y **nombre visible
   en español** — solo para módulos de usuario; alimentan los pasos manuales de
   permisos/menú.
4. **URL del índice en español** (ej. `/tipos-email`).
5. **Ruta de Suite** (absoluta o relativa al proyecto; default configurable).
   Si la ruta no es válida, **reintenta**; y puedes escribir **`local`** para
   generar la migración dentro del propio módulo (`Infrastructure/Database/Migrations`,
   que el provider ya carga) en vez de en Suite.
6. **Proyecto de migraciones** (lista las carpetas de `migrations/tenant` + opción `otro`).
7. **¿Agregar seeder?** Si sí, se crea en el mismo proyecto que la migración.

Al terminar, imprime los **pasos manuales** para dejar el módulo operativo:
entrada en `TenantAppsSeeder`, permisos en `PermissionsTableSeeder` y ruta en
`SuiteConfigModeResolver::ROUTES` (para módulos de usuario).

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
| `route_middleware` | — | **Ya no se usa**: las rutas se generan con el patrón estándar de Suite (grupos Casbin por acción en módulos de usuario; guard interno en módulos internos). |
| `pages_path` | `Pages` | Carpeta de páginas Inertia bajo `resources/js`. |
| `suite_default_path` | `env('MODULE_GENERATOR_SUITE_PATH', '../Suite')` | Ruta por defecto del repo Suite en el prompt. En Docker, define `MODULE_GENERATOR_SUITE_PATH` en el `.env`. |

## Dependencias del proyecto consumidor

El código generado asume que existen en el proyecto:

- `App\Shared\Infrastructure\Database\Repositories\BaseSearchRepository` (motor de
  filtros/paginación compartido del ecosistema).
- `App\Shared\Domain\Exceptions\ResourceNotFoundException` (base de las excepciones).
- `Sodeker\LaravelCasbin\Domain\Contracts\PermissionServiceInterface` (módulos de usuario).
- `App\Modules\Tenants\Domain\DevelopTenantMembershipRule` con las constantes
  `ALLOWED_EMAIL` / `ALLOWED_ROLE_CODE` (guard de los módulos internos).
- Componentes Vue de la plantilla Velzon (`@/Layouts/main.vue`,
  `@/Components/page-header.vue`, `@/Components/DataTable.vue`,
  `@/Components/AccessDeniedCard.vue`) y composables (`useFetchPetition`, `useSweetAlert`).
- Middleware `tenant.selected`, `tenant` y `casbin:{modulo},{accion}` (multi-tenant + RBAC).
- `config/app.php` con el array `providers` en estilo legacy (para el auto-registro).
