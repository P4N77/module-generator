<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Namespace raíz de los módulos
    |--------------------------------------------------------------------------
    | El comando genera App\Modules\{Modulo}\... Se asume que el namespace raíz
    | de la app (App\) mapea a app/ (convención por defecto de Laravel).
    */
    'module_namespace' => 'App\\Modules',

    /*
    |--------------------------------------------------------------------------
    | Namespace de los contratos compartidos (DataBridge)
    |--------------------------------------------------------------------------
    | Donde se publican los contratos List/Match que exponen el listado del
    | módulo a otros módulos.
    */
    'shared_contracts_namespace' => 'App\\Shared\\Contracts',

    /*
    |--------------------------------------------------------------------------
    | Prefijo de tabla por defecto
    |--------------------------------------------------------------------------
    | Se usa cuando NO se invoca el comando con la sintaxis "prefijo/Modulo".
    | Vacío => sin prefijo. Ej: 'irs' => irs_novelties.
    */
    'table_prefix' => '',

    /*
    |--------------------------------------------------------------------------
    | Conexión Eloquent de los modelos del módulo
    |--------------------------------------------------------------------------
    | En apps multi-tenant suele ser 'tenant'. Para apps de una sola BD, null
    | deja la conexión por defecto.
    */
    'connection' => 'tenant',

    /*
    |--------------------------------------------------------------------------
    | Middleware del grupo de rutas del módulo
    |--------------------------------------------------------------------------
    | Cada entrada se renderiza tal cual en el archivo de rutas generado. Las
    | entradas que contienen "(" o "::" se emiten como expresión PHP (sin
    | comillas); el resto se emiten como string.
    */
    'route_middleware' => [
        'web',
        'auth:sanctum',
        "config('jetstream.auth_session')",
        'verified',
        'tenant.selected',
        'tenant',
    ],

    /*
    |--------------------------------------------------------------------------
    | Carpeta de páginas Inertia/Vue
    |--------------------------------------------------------------------------
    | Relativa a resource_path('js'). Las páginas se generan en
    | resource_path('js/{pages_path}/{Modulo}').
    */
    'pages_path' => 'Pages',

    /*
    |--------------------------------------------------------------------------
    | Ruta por defecto del repositorio Suite
    |--------------------------------------------------------------------------
    | Donde viven las migraciones/seeders (database/migrations|seeders/tenant).
    | Absoluta o relativa a base_path(). Se puede sobreescribir en el prompt.
    */
    'suite_default_path' => '../Suite',
];
