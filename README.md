# sodeker/module-generator

Generador de módulos DDD para el ecosistema **Sódeker** (Laravel 11/12 + Inertia/Vue
sobre plantilla Velzon, multi-tenant con repositorio **Suite**).

Un solo comando, `php artisan make:module`, genera un módulo CRUD completo,
**alineado 1:1 con el estándar de maestras de Suite** (referencia
`CommunicationChannels` / `EmailTypes`), y puede **tomar las columnas del
`CREATE TABLE`** que exporta el modelador para propagarlas a todas las capas.

---

## Instalación

> **Importante:** el paquete **no está publicado en Packagist**. Hay que declarar
> el repositorio **antes** de requerirlo. Si se omite el paso 1, Composer falla con
> `could not be found in any version, there may be a typo in the package name`.

El repo es **público**, así que no necesitas token, SSH ni ser colaborador:

```bash
# 1. Declarar el repositorio (sin editar el JSON a mano). "no-api" evita la API
#    de GitHub y clona por HTTPS directo (sin límites de rate ni token).
composer config repositories.module-generator \
  '{"type":"vcs","url":"https://github.com/P4N77/module-generator.git","no-api":true}'

# 2. Requerir el paquete (es una herramienta de desarrollo => --dev)
composer require --dev sodeker/module-generator:^1.4
```

El paso 1 deja esto en el `composer.json` del proyecto (equivalente a escribirlo
a mano):

```json
"repositories": {
    "module-generator": {
        "type": "vcs",
        "url": "https://github.com/P4N77/module-generator.git",
        "no-api": true
    }
}
```

El `ServiceProvider` se auto-descubre (Laravel package discovery).

### Verificación y problemas comunes

```bash
# ¿Composer ve el paquete y sus versiones?
composer show sodeker/module-generator --all
# => versions : v1.4.0, v1.3.2, ... , dev-main
```

| Síntoma | Causa | Solución |
|---------|-------|----------|
| `could not be found in any version` | Falta el paso 1 (repositorio no declarado). | Ejecuta `composer config repositories.module-generator ...` y reintenta. |
| `Could not find a version ... satisfiable by your minimum-stability` | Se pidió una versión que no existe como tag. | Usa `^1.4` o `dev-main`. |
| `requires php ^8.2` / `illuminate/console ^12.0` | Proyecto en PHP < 8.2 o Laravel < 12. | El paquete solo soporta PHP 8.2+ y Laravel 12. |
| El comando `make:module` no aparece | El proyecto desactiva el package discovery. | Registra `Sodeker\ModuleGenerator\ModuleGeneratorServiceProvider` a mano. |

---

## Qué genera

- **Domain**: `Entities` (con `create()`/`update()`/`delete()`), `ValueObjects`
  (`{Modulo}Status`, enum `1`/`0`), `Repositories` (interfaces), `Exceptions`
  (extienden `ResourceNotFoundException`).
- **Application**: `Commands`, `Handlers` (CQRS, incluye `Validate{Modulo}CodeHandler`
  si el módulo lleva código), `DTOs` (`{Modulo}DTO`, `Save{Modulo}DTO`,
  `{Modulo}CollectionDTO` con `meta` paginado), `Services`.
- **Infrastructure**: `Models` (Eloquent, `fillable` sin timestamps),
  `Repositories` (Eloquent, con `codeExists()`), `Http` (`Controllers`,
  `Requests` con normalización/unicidad, `Routes`).
- **DataBridge** (contratos Shared para consumo desde otros módulos, sin acoplarse):
  - *Lectura*: `List{Plural}Contract` + `Match{Plural}RowContract` con su DTO,
    `List{Plural}Service`, `Match{Plural}RowService` y repositorio de listado
    (paginación/búsqueda opcional).
  - *Escritura*: `Create{Modulo}Contract`, `Update{Modulo}Contract`,
    `Delete{Modulo}Contract` con sus services correspondientes.
- **Frontend**: páginas Inertia/Vue `Index/Create/Edit/Show` (badge de estado,
  switch, `AccessDeniedCard`, validación de código, props en camelCase).
- **Campo `status`** obligatorio en toda la cadena; **`code`** opcional (se detecta
  del SQL o se pregunta).
- **Dos tipos de módulo**: *usuario* (permisos Casbin por acción + menú) o
  *interno* (guard de la cuenta Sodeker/Developer, sin Casbin ni menú).
- **Suite**: migración (`database/migrations/tenant/{proyecto}/{Modulo}/`), un test
  Pest (`tests/Unit/{Plural}/List{Plural}ServiceTest.php`) y, opcionalmente, un
  seeder, con selección interactiva del proyecto destino.
- Registra el `ServiceProvider` del módulo en `config/app.php` e imprime los
  **pasos manuales** de permisos/menú.

---

## Uso

```bash
# Módulo simple (tabla sin prefijo => "novelties")
php artisan make:module Novelty

# Con prefijo de tabla (=> "irs_novelties")
php artisan make:module irs/Novelty

# Tomando las columnas de un CREATE TABLE
php artisan make:module shd/Test --sql=docs/modelo/test.sql
php artisan make:module shd/Test --sql="CREATE TABLE test ( ... );"
```

Antes de empezar, el comando:

1. **Verifica la estructura base** del proyecto (raíz de módulos, base DataBridge
   y páginas Vue). Si falta alguna, avisa y pregunta si crea las carpetas.
2. **Aborta si el módulo ya existe** (módulo, contratos compartidos o vistas Vue),
   sin sobrescribir nada.

### Flujo interactivo

1. **SQL de la tabla.** Si no pasaste `--sql`, se abre un `textarea` para pegar el
   `CREATE TABLE` o el dump completo. **Déjalo vacío** para generar el módulo
   básico (solo `description` + `status`).
2. **¿El módulo lleva código (`code`)?** Solo se pregunta **si no hay SQL**; con SQL
   se detecta por la presencia de la columna `code`. Si lo lleva, agrega la columna
   única, el endpoint `validate-code`, `codeExists()` y la validación de unicidad
   en el frontend.
3. **Tipo de módulo**: *usuario* (Casbin + menú) o *interno* (guard Sodeker/Developer,
   sin menú, acceso solo por URL).
4. **App destino** (`suite/sat/iris/tut/econnect/f16/fintegra`) y **nombre visible
   en español** — solo para módulos de usuario; alimentan los pasos manuales.
5. **URL del índice en español** (ej. `/tipos-email`).
6. **Ruta de Suite** (absoluta o relativa al proyecto; default configurable).
   Si la ruta no es válida, **reintenta**; y puedes escribir **`local`** para
   generar la migración dentro del propio módulo
   (`Infrastructure/Database/Migrations`, que el provider ya carga) en vez de en Suite.
7. **Proyecto de migraciones** (lista las carpetas de `migrations/tenant` + opción `otro`).
8. **¿Agregar seeder?** Si sí, se crea en el mismo proyecto que la migración.

Los pasos 7 y 8 se omiten en modo `local`.

---

## Módulo a partir del SQL del modelador

`--sql` acepta, por orden de prioridad: **una ruta** a un archivo (`.sql`, `.txt`,
cualquiera legible; absoluta, relativa al directorio actual o a `base_path()`),
**el SQL en línea**, o el **pegado en consola** si no se pasa la opción. En modo no
interactivo (scripts/CI) sin `--sql`, simplemente no se usa SQL.

**Selección de la tabla.** Se parsean todos los `CREATE TABLE` del SQL y se busca la
del módulo probando el nombre con prefijo y sin él, en plural y en singular
(`shd_tests`, `shd_test`, `tests`, `test`). Si el dump trae un único `CREATE TABLE`,
se usa ese. Si hay varios y ninguno coincide, se muestra la lista para elegir (o
cancelar sin crear nada).

**Nombre real de la tabla.** El modelador exporta sin el prefijo de Suite: el nombre
final es el prefijo del comando + el nombre del SQL (`shd` + `test` => `shd_test`).

**Columnas → campos.** Se descartan:

- Las del esqueleto estándar, que el módulo ya genera: `id`, `uuid`, `code`,
  `description`, `status`, `created_by`, `updated_by`, `created_at`, `updated_at`,
  `deleted_at`.
- Las referenciadas por una cláusula `FOREIGN KEY` (quedan para una pasada posterior).
- Los tipos aún no soportados, para que las capas queden consistentes entre sí.

Tipos soportados en esta versión:

| SQL | Campo generado | Notas |
|-----|----------------|-------|
| `char`, `varchar`, `nvarchar`, `varchar2`, `nchar`, `character` | `string` | Respeta la longitud (`varchar(60)`). |
| `text`, `tinytext`, `mediumtext`, `longtext`, `ntext`, `clob` | `text` | Se renderiza como `textarea` en Vue. |
| `date` | `date` | |
| `decimal`, `numeric`, `dec`, `float`, `double`, `real`, `money` | `decimal` | Escala del tipo (`decimal(10,2)` => 2); viaja como `string`. |

Los tipos no listados (enteros, `datetime`, `boolean`, `enum`, `json`…) se ignoran.
El `NOT NULL` de cada columna determina si el campo es obligatorio.

Los campos detectados se propagan a **migración, modelo, entidad, DTOs, commands,
handlers, repositorio, form requests, controlador, vistas Vue y contratos
DataBridge**.

---

## Pasos manuales

Al terminar, el comando imprime lo que falta para dejar el módulo operativo.

Para **módulos de usuario**, en el repo Suite:

1. `database/seeders/Landlord/CasbinSeeders/ModulesTenantAppsSeeder.php` — entrada
   del módulo en el arreglo `$modules`.
2. `database/seeders/Landlord/CasbinSeeders/PermissionsTableSeeder.php` — permisos
   `['view', 'edit', 'create', 'delete']` bajo el slug de la app.
3. La ruta navegable:
   - App `suite`: `app/Http/Support/SuiteConfigModeResolver.php` (const `ROUTES`).
   - Apps hijas: `app/Http/Middleware/HandleInertiaRequests.php`
     (const `CHILD_APP_MODULE_ROUTES`, bajo la clave de la app).

Cada línea se imprime lista para copiar y pegar, con el `app_id` y el código del
módulo ya resueltos.

Para **módulos internos** no hay pasos: sin menú ni Casbin, el acceso queda
restringido en el controlador a la cuenta Sodeker con rol Developer y se ingresa
únicamente por la URL del índice.

---

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

---

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

## Requisitos

- PHP **8.2+**
- Laravel **12** (`illuminate/console` / `illuminate/support` `^12.0`)
