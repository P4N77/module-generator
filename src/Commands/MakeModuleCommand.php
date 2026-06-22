<?php

declare(strict_types=1);

namespace Sodeker\ModuleGenerator\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class MakeModuleCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'make:module {name : The name of the module}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new DDD module with complete folder structure';

    /** Sentinela: generar la migración dentro del módulo en vez de en Suite. */
    private const LOCAL_TARGET = 'local';

    /** Namespace raíz de los módulos (config: module-generator.module_namespace). */
    private string $moduleNs = 'App\\Modules';

    /** Namespace de los contratos compartidos (config: module-generator.shared_contracts_namespace). */
    private string $sharedNs = 'App\\Shared\\Contracts';

    /** Conexión Eloquent de los modelos (config: module-generator.connection). */
    private ?string $connection = 'tenant';

    /** Carpeta de páginas Inertia/Vue bajo resource_path('js'). */
    private string $pagesPath = 'Pages';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // Configuración (con valores por defecto del ecosistema Sódeker).
        $this->moduleNs = trim((string) config('module-generator.module_namespace', 'App\\Modules'), '\\');
        $this->sharedNs = trim((string) config('module-generator.shared_contracts_namespace', 'App\\Shared\\Contracts'), '\\');
        $connection = config('module-generator.connection', 'tenant');
        $this->connection = $connection === null ? null : (string) $connection;
        $this->pagesPath = trim((string) config('module-generator.pages_path', 'Pages'), '/');

        $rawName = $this->argument('name');

        // Detectar prefijo opcional con el formato "prefijo/nombreModulo" (ej. irs/novelties).
        // Si no se indica, se usa el prefijo por defecto de la config (puede ser vacío).
        $prefix = trim((string) config('module-generator.table_prefix', ''));
        $prefix = $prefix !== '' ? Str::lower($prefix) : null;
        if (str_contains($rawName, '/')) {
            [$prefix, $rawName] = explode('/', $rawName, 2);
            $prefix = Str::lower(trim($prefix));
        }

        $moduleName = $rawName;
        $moduleNamePlural = Str::plural($moduleName);
        $moduleNameSingular = Str::singular($moduleName);

        // Normalizar nombres
        $moduleName = Str::studly($moduleName);
        $moduleNamePlural = Str::studly($moduleNamePlural);
        $moduleNameSingular = Str::studly($moduleNameSingular);
        $moduleNameLower = Str::snake($moduleName);
        $moduleNamePluralLower = Str::snake($moduleNamePlural);
        $moduleNameSingularLower = Str::snake($moduleNameSingular);

        // Nombre de la tabla: con prefijo "{prefix}_{module}" o solo "{module}"
        $tableName = $prefix !== null
            ? "{$prefix}_{$moduleNamePluralLower}"
            : $moduleNamePluralLower;

        $basePath = $this->nsToPath($this->moduleNs)."/{$moduleNamePlural}";

        // Verificar si el módulo ya existe
        if (File::exists($basePath)) {
            $this->error("Module '{$moduleNamePlural}' already exists!");
            return Command::FAILURE;
        }

        $this->info("Creating DDD module: {$moduleNamePlural}");

        // ===== Migración + (opcional) seeder =====
        // En Suite (si está accesible) o, como respaldo (p.ej. en contenedores
        // donde Suite no está montado), dentro del propio módulo.
        $suitePath = $this->resolveSuiteBasePath();
        if ($suitePath === null) {
            return Command::FAILURE;
        }

        if ($suitePath === self::LOCAL_TARGET) {
            // Respaldo local: migración dentro del módulo (la carga el provider).
            $localMigrationsPath = "{$basePath}/Infrastructure/Database/Migrations";
            File::ensureDirectoryExists($localMigrationsPath);
            $this->writeMigrationFile($localMigrationsPath, $tableName);
            $this->line("Created: Infrastructure/Database/Migrations (migración local; muévela a Suite cuando esté disponible)");
        } else {
            $migrationProject = $this->chooseProject(
                "{$suitePath}/database/migrations/tenant",
                'migraciones'
            );
            $this->createMigration($suitePath, $migrationProject, $moduleNamePlural, $tableName);

            if ($this->confirm('¿Desea agregar un seeder para este módulo?', false)) {
                // El seeder vive en el mismo proyecto que la migración.
                $this->createSeeder($suitePath, $migrationProject, $moduleNamePlural, $tableName);
            }
        }

        // ===== Estructura del módulo =====
        $modelsPath = "{$basePath}/Infrastructure/Database/Models";
        File::makeDirectory($modelsPath, 0755, true);
        $this->line("Created: {$modelsPath}");

        $this->createModel($modelsPath, $moduleNamePlural, $moduleNameSingular, $tableName);

        $entitiesPath = "{$basePath}/Domain/Entities";
        File::makeDirectory($entitiesPath, 0755, true);
        $this->line("Created: {$entitiesPath}");

        $this->createDomainEntity($entitiesPath, $moduleNamePlural, $moduleNameSingular);

        $dtosPath = "{$basePath}/Application/DTOs";
        File::makeDirectory($dtosPath, 0755, true);
        $this->line("Created: {$dtosPath}");

        $this->createDto($dtosPath, $moduleNamePlural, $moduleNameSingular);
        $this->createCollectionDto($dtosPath, $moduleNamePlural, $moduleNameSingular);

        $repositoriesPath = "{$basePath}/Domain/Repositories";
        File::makeDirectory($repositoriesPath, 0755, true);
        $this->line("Created: {$repositoriesPath}");

        $this->createRepositoryInterface($basePath, $moduleNamePlural, $moduleNameSingular);

        $commandsPath = "{$basePath}/Application/Commands";
        File::makeDirectory($commandsPath, 0755, true);
        $this->line("Created: {$commandsPath}");

        $this->createCommands($commandsPath, $moduleNamePlural, $moduleNameSingular);

        $handlersPath = "{$basePath}/Application/Handlers";
        File::makeDirectory($handlersPath, 0755, true);
        $this->line("Created: {$handlersPath}");

        $this->createHandlers($handlersPath, $moduleNamePlural, $moduleNameSingular);

        $repositoryImplPath = "{$basePath}/Infrastructure/Database/Repositories";
        File::makeDirectory($repositoryImplPath, 0755, true);
        $this->line("Created: {$repositoryImplPath}");

        $this->createRepositoryImplementation($basePath, $moduleNamePlural, $moduleNameSingular, $moduleNameLower, $moduleNamePluralLower);

        $exceptionsPath = "{$basePath}/Domain/Exceptions";
        File::makeDirectory($exceptionsPath, 0755, true);
        $this->line("Created: {$exceptionsPath}");

        $this->createNotFoundException($basePath, $moduleNamePlural, $moduleNameSingular);

        $requestsPath = "{$basePath}/Infrastructure/Http/Requests";
        File::makeDirectory($requestsPath, 0755, true);
        $this->line("Created: {$requestsPath}");

        $this->createRequests($requestsPath, $moduleNamePlural, $moduleNameSingular);

        $controllersPath = "{$basePath}/Infrastructure/Http/Controllers";
        File::makeDirectory($controllersPath, 0755, true);
        $this->line("Created: {$controllersPath}");

        $this->createController($basePath, $moduleNamePlural, $moduleNameSingular, $moduleNamePluralLower, $moduleNameSingularLower);

        $this->createVueFront($moduleNamePlural, $moduleNamePluralLower, $moduleNameSingularLower);

        $routesPath = "{$basePath}/Infrastructure/Http/Routes";
        File::makeDirectory($routesPath, 0755, true);
        $this->line("Created: {$routesPath}");

        $this->createRoutesFile($basePath, $moduleNamePlural, $moduleNameLower, $moduleNamePluralLower, $moduleNameSingular);

        $this->createDataBridge($basePath, $moduleNamePlural, $moduleNameSingular, $tableName);

        $this->createServiceProvider($basePath, $moduleNamePlural, $moduleNameLower, $moduleNameSingular);

        $this->registerProviderInConfig($moduleNamePlural);

        // Limpieza de cachés para que Laravel detecte el nuevo provider y rutas
        $this->newLine();
        $this->info("Limpiando cachés...");
        $this->call('optimize:clear');

        $this->newLine();
        $this->info("Archivos del módulo '{$moduleNamePlural}' creados correctamente!");
        $this->line("Backend: app/Modules/{$moduleNamePlural}/  |  Frontend: resources/js/Pages/{$moduleNamePlural}/");
        $this->newLine();
        $this->info("Ejecute 'php artisan migrate' y cargue la vista en {APP_URL}/{$moduleNamePluralLower}");

        return Command::SUCCESS;
    }
    
    /**
     * Resuelve la ruta base del repositorio Suite. Reintenta ante rutas
     * inválidas (útil en Docker donde Suite puede estar montado en otra ruta) y
     * permite escribir 'local' para generar la migración dentro del módulo.
     *
     * @return string Ruta canónica de Suite, self::LOCAL_TARGET (modo local) o
     *                null para abortar.
     */
    private function resolveSuiteBasePath(): ?string
    {
        $default = (string) config('module-generator.suite_default_path', '../Suite');

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $input = trim((string) $this->ask(
                "Ruta de Suite (absoluta o relativa al proyecto). Escribe 'local' para generar la migración dentro del módulo",
                $default
            ));

            if (strtolower($input) === self::LOCAL_TARGET) {
                $this->line('Modo local: la migración se generará dentro del módulo.');
                return self::LOCAL_TARGET;
            }

            $path = $this->normalizeSuitePath($input);
            if ($path !== null && File::isDirectory("{$path}/database/migrations/tenant")) {
                $this->line("Suite localizado en: {$path}");
                return $path;
            }

            $this->error("No se encontró 'database/migrations/tenant' en: {$input}");
            $default = $input; // conservar lo escrito para corregirlo
        }

        $this->warn('No se pudo localizar Suite. Puedes definir MODULE_GENERATOR_SUITE_PATH en el .env.');

        return $this->confirm('¿Generar la migración localmente dentro del módulo?', true)
            ? self::LOCAL_TARGET
            : null;
    }

    /**
     * Normaliza la ruta de Suite. Rutas absolutas se usan tal cual; las
     * relativas se resuelven contra la raíz del proyecto (base_path()).
     */
    private function normalizeSuitePath(string $input): ?string
    {
        $input = trim($input);
        if ($input === '') {
            return null;
        }

        $path = str_starts_with($input, '/') ? $input : base_path($input);
        $real = realpath($path);

        return $real !== false ? $real : null;
    }

    /**
     * Convierte un namespace PSR-4 bajo App\ en su ruta de carpeta.
     * Ej: "App\Modules" => app/Modules (asume root App\ => app/).
     */
    private function nsToPath(string $namespace): string
    {
        $relative = ltrim(preg_replace('/^App\\\\/', '', $namespace) ?? $namespace, '\\');

        return $relative === ''
            ? app_path()
            : app_path(str_replace('\\', '/', $relative));
    }

    /**
     * Renderiza el middleware del grupo de rutas a partir de la config. Las
     * entradas con "(" o "::" se emiten como expresión PHP (sin comillas), el
     * resto como string. Ej: config('jetstream.auth_session') queda crudo.
     */
    private function renderRouteMiddleware(): string
    {
        $middleware = (array) config('module-generator.route_middleware', [
            'web', 'auth:sanctum', 'verified', 'tenant.selected', 'tenant',
        ]);

        return collect($middleware)
            ->map(static fn (string $m): string => str_contains($m, '(') || str_contains($m, '::')
                ? $m
                : "'{$m}'")
            ->implode(', ');
    }

    /**
     * Lista las carpetas de proyecto existentes bajo $tenantDir y deja elegir
     * una, o "otro" para crear un proyecto nuevo (cuyo nombre se solicita).
     */
    private function chooseProject(string $tenantDir, string $tipo): string
    {
        $projects = collect(File::directories($tenantDir))
            ->map(fn (string $dir): string => basename($dir))
            ->sort()
            ->values()
            ->all();

        $options = array_merge($projects, ['otro']);
        $default = in_array('iris', $projects, true) ? 'iris' : ($options[0] ?? 'otro');

        $choice = $this->choice("Seleccione el proyecto de {$tipo}", $options, $default);

        if ($choice === 'otro') {
            $choice = Str::lower(trim((string) $this->ask('Nombre del nuevo proyecto')));
        }

        return $choice;
    }

    /**
     * Crea la migración en el repositorio Suite, dentro de
     * database/migrations/tenant/{project}/{ModuleFolder}/.
     */
    private function createMigration(
        string $suitePath,
        string $project,
        string $moduleFolder,
        string $tableName
    ): void {
        $dir = "{$suitePath}/database/migrations/tenant/{$project}/{$moduleFolder}";
        $fileName = $this->writeMigrationFile($dir, $tableName);
        $this->line("Created: Suite/database/migrations/tenant/{$project}/{$moduleFolder}/{$fileName}");
    }

    /**
     * Escribe el archivo de migración en $dir y devuelve el nombre del archivo.
     * Reutilizado por el flujo Suite y por el respaldo local.
     */
    private function writeMigrationFile(string $dir, string $tableName): string
    {
        File::ensureDirectoryExists($dir);

        $timestamp = date('Y_m_d_His');
        $fileName = "{$timestamp}_create_table_{$tableName}.php";

        $content = <<<PHP
<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('{$tableName}', function (Blueprint \$table) {
            \$table->id();
            \$table->char('uuid', 26)->unique();
            \$table->string('description')->nullable();
            \$table->foreignId('created_by')->nullable();
            \$table->foreignId('updated_by')->nullable();
            \$table->timestamps();
            \$table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('{$tableName}');
    }
};
PHP;

        File::put("{$dir}/{$fileName}", $content);

        return $fileName;
    }

    /**
     * Crea un seeder en el repositorio Suite, plano dentro de
     * database/seeders/tenant/{project}/{Plural}Seeder.php.
     */
    private function createSeeder(
        string $suitePath,
        string $project,
        string $moduleNamePlural,
        string $tableName
    ): void {
        $dir = "{$suitePath}/database/seeders/tenant/{$project}";
        File::ensureDirectoryExists($dir);

        $seederClass = "{$moduleNamePlural}Seeder";
        $namespace = 'Database\\Seeders\\Tenant\\'.Str::studly($project);

        $content = <<<PHP
<?php

namespace {$namespace};

use Illuminate\\Database\\Seeder;
use Illuminate\\Support\\Facades\\DB;
use Illuminate\\Support\\Str;

class {$seederClass} extends Seeder
{
    public function run(): void
    {
        \$now = now();
        \$systemUser = '1';

        \$rows = [
            // ['description' => 'Ejemplo'],
        ];

        foreach (\$rows as \$row) {
            \$existing = DB::table('{$tableName}')
                ->where('description', \$row['description'])
                ->first();

            DB::table('{$tableName}')->updateOrInsert(
                ['description' => \$row['description']],
                [
                    'uuid' => \$existing->uuid ?? (string) Str::ulid(),
                    'description' => \$row['description'],
                    'created_by' => \$systemUser,
                    'updated_by' => \$systemUser,
                    'created_at' => \$existing->created_at ?? \$now,
                    'updated_at' => \$now,
                ]
            );
        }
    }
}
PHP;

        File::put("{$dir}/{$seederClass}.php", $content);
        $this->line("Created: Suite/database/seeders/tenant/{$project}/{$seederClass}.php");
    }

    /**
     * Create Eloquent Model from template
     */
    private function createModel(
        string $modelsPath,
        string $moduleNamePlural,
        string $moduleNameSingular,
        string $tableName
    ): void {
        $connectionLine = $this->connection !== null
            ? "\n    protected \$connection = '{$this->connection}';"
            : '';

        $content = <<<PHP
<?php

declare(strict_types=1);

namespace {$this->moduleNs}\\{$moduleNamePlural}\\Infrastructure\\Database\\Models;

use Illuminate\\Database\\Eloquent\\Model;
use Illuminate\\Database\\Eloquent\\SoftDeletes;

/**
 * @group {$moduleNamePlural}
 *
 * Modelo para la tabla de {$moduleNamePlural}
 */
class {$moduleNameSingular} extends Model
{
    use SoftDeletes;

    protected \$table = '{$tableName}';{$connectionLine}
    public \$incrementing = true;
    protected \$keyType = 'int';

    protected \$fillable = [
        'id',
        'uuid',
        'description',
        'created_by',
        'updated_by',
        'created_at',
        'updated_at',
        'deleted_at',
    ];
}
PHP;

        File::put("{$modelsPath}/{$moduleNameSingular}.php", $content);
        $this->line("Created: Infrastructure/Database/Models/{$moduleNameSingular}.php");
    }

    /**
     * Create Domain Entity from template
     */
    private function createDomainEntity(
        string $entitiesPath,
        string $moduleNamePlural,
        string $moduleNameSingular
    ): void {
        $content = <<<PHP
<?php

declare(strict_types=1);

namespace {$this->moduleNs}\\{$moduleNamePlural}\\Domain\\Entities;

use DateTimeImmutable;

final class {$moduleNameSingular}
{
    public function __construct(
        private ?int \$id,
        private string \$uuid,
        private ?string \$description,
        private string \$createdBy,
        private ?string \$updatedBy,
        private DateTimeImmutable \$createdAt,
        private ?DateTimeImmutable \$updatedAt,
        private ?DateTimeImmutable \$deletedAt = null,
    ) {}

    public static function create(
        ?int \$id,
        string \$uuid,
        ?string \$description,
        string \$createdBy,
        ?string \$updatedBy = null,
        ?DateTimeImmutable \$updatedAt = null,
    ): self {
        return new self(
            id: \$id,
            uuid: \$uuid,
            description: \$description,
            createdBy: \$createdBy,
            createdAt: new DateTimeImmutable(),
            updatedBy: \$updatedBy,
            updatedAt: \$updatedAt,
        );
    }

    public function update(
        ?string \$description,
        string \$updatedBy,
    ): void {
        \$this->description = \$description;
        \$this->updatedBy = \$updatedBy;
        \$this->updatedAt = new DateTimeImmutable();
    }

    public function id(): ?int { return \$this->id; }
    public function uuid(): string { return \$this->uuid; }
    public function description(): ?string { return \$this->description; }
    public function createdBy(): string { return \$this->createdBy; }
    public function updatedBy(): ?string { return \$this->updatedBy; }
    public function createdAt(): DateTimeImmutable { return \$this->createdAt; }
    public function updatedAt(): ?DateTimeImmutable { return \$this->updatedAt; }
    public function deletedAt(): ?DateTimeImmutable { return \$this->deletedAt; }
}
PHP;

        File::put("{$entitiesPath}/{$moduleNameSingular}.php", $content);
        $this->line("Created: Domain/Entities/{$moduleNameSingular}.php");
    }

    /**
     * Create DTO from template
     */
    private function createDto(
        string $dtosPath,
        string $moduleNamePlural,
        string $moduleNameSingular
    ): void {
        $content = <<<PHP
<?php

declare(strict_types=1);

namespace {$this->moduleNs}\\{$moduleNamePlural}\\Application\\DTOs;

use {$this->moduleNs}\\{$moduleNamePlural}\\Domain\\Entities\\{$moduleNameSingular};
use DateTimeImmutable;

final class {$moduleNameSingular}DTO
{
    public function __construct(
        public ?int \$id,
        public string \$uuid,
        public ?string \$description,
        public string \$createdBy,
        public ?string \$updatedBy,
        public DateTimeImmutable \$createdAt,
        public ?DateTimeImmutable \$updatedAt,
        public ?DateTimeImmutable \$deletedAt,
    ) {}

    public static function fromDomain({$moduleNameSingular} \$entity): self
    {
        return new self(
            id: \$entity->id(),
            uuid: \$entity->uuid(),
            description: \$entity->description(),
            createdBy: \$entity->createdBy(),
            updatedBy: \$entity->updatedBy(),
            createdAt: \$entity->createdAt(),
            updatedAt: \$entity->updatedAt(),
            deletedAt: \$entity->deletedAt(),
        );
    }

    /**
     * Representación lista para el frontend (snake_case + fechas formateadas).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => \$this->id,
            'uuid' => \$this->uuid,
            'description' => \$this->description,
            'created_by' => \$this->createdBy,
            'updated_by' => \$this->updatedBy,
            'created_at' => \$this->createdAt->format('Y-m-d H:i:s'),
            'updated_at' => \$this->updatedAt?->format('Y-m-d H:i:s'),
            'deleted_at' => \$this->deletedAt?->format('Y-m-d H:i:s'),
        ];
    }
}
PHP;

        File::put("{$dtosPath}/{$moduleNameSingular}DTO.php", $content);
        $this->line("Created: Application/DTOs/{$moduleNameSingular}DTO.php");
    }

    /**
     * Create Collection DTO from template
     */
    private function createCollectionDto(
        string $dtosPath,
        string $moduleNamePlural,
        string $moduleNameSingular
    ): void {
        $content = <<<PHP
<?php

declare(strict_types=1);

namespace {$this->moduleNs}\\{$moduleNamePlural}\\Application\\DTOs;

use {$this->moduleNs}\\{$moduleNamePlural}\\Domain\\Entities\\{$moduleNameSingular};

final class {$moduleNameSingular}CollectionDTO
{
    public function __construct(
        public array \$items,
        public int \$total,
    ) {}

    public static function fromDomain(array \$entities, int \$total): self
    {
        \$items = array_map(
            fn({$moduleNameSingular} \$entity) => {$moduleNameSingular}DTO::fromDomain(\$entity),
            \$entities
        );

        return new self(
            items: \$items,
            total: \$total,
        );
    }

    public function toArray(): array
    {
        return [
            'data' => array_map(
                fn({$moduleNameSingular}DTO \$dto) => \$dto->toArray(),
                \$this->items
            ),
            'meta' => [
                'total' => \$this->total,
            ],
        ];
    }
}
PHP;

        File::put("{$dtosPath}/{$moduleNameSingular}CollectionDTO.php", $content);
        $this->line("Created: Application/DTOs/{$moduleNameSingular}CollectionDTO.php");
    }

    /**
     * Create Commands (Create, Update, Delete) from templates
     */
    private function createCommands(
        string $commandsPath,
        string $moduleNamePlural,
        string $moduleNameSingular
    ): void {
        // Create
        $createContent = <<<PHP
<?php

declare(strict_types=1);

namespace {$this->moduleNs}\\{$moduleNamePlural}\\Application\\Commands;

final class Create{$moduleNameSingular}Command
{
    public function __construct(
        public ?string \$description,
        public string \$createdBy,
        public ?string \$updatedBy = null,
    ) {}
}
PHP;
        File::put("{$commandsPath}/Create{$moduleNameSingular}Command.php", $createContent);
        $this->line("Created: Application/Commands/Create{$moduleNameSingular}Command.php");

        // Update
        $updateContent = <<<PHP
<?php

declare(strict_types=1);

namespace {$this->moduleNs}\\{$moduleNamePlural}\\Application\\Commands;

final class Update{$moduleNameSingular}Command
{
    public function __construct(
        public string \$uuid,
        public ?string \$description,
        public string \$updatedBy,
    ) {}
}
PHP;
        File::put("{$commandsPath}/Update{$moduleNameSingular}Command.php", $updateContent);
        $this->line("Created: Application/Commands/Update{$moduleNameSingular}Command.php");

        // Delete
        $deleteContent = <<<PHP
<?php

declare(strict_types=1);

namespace {$this->moduleNs}\\{$moduleNamePlural}\\Application\\Commands;

final class Delete{$moduleNameSingular}Command
{
    public function __construct(
        public string \$uuid,
    ) {}
}
PHP;
        File::put("{$commandsPath}/Delete{$moduleNameSingular}Command.php", $deleteContent);
        $this->line("Created: Application/Commands/Delete{$moduleNameSingular}Command.php");
    }

    /**
     * Create Handlers (Create, List, Update) from templates
     */
    private function createHandlers(
        string $handlersPath,
        string $moduleNamePlural,
        string $moduleNameSingular
    ): void {
        // Create
        $createContent = <<<PHP
<?php

declare(strict_types=1);

namespace {$this->moduleNs}\\{$moduleNamePlural}\\Application\\Handlers;

use {$this->moduleNs}\\{$moduleNamePlural}\\Application\\Commands\\Create{$moduleNameSingular}Command;
use {$this->moduleNs}\\{$moduleNamePlural}\\Application\\DTOs\\{$moduleNameSingular}DTO;
use {$this->moduleNs}\\{$moduleNamePlural}\\Domain\\Repositories\\{$moduleNameSingular}RepositoryInterface;
use {$this->moduleNs}\\{$moduleNamePlural}\\Domain\\Entities\\{$moduleNameSingular};
use Illuminate\\Support\\Str;

final class Create{$moduleNameSingular}Handler
{
    public function __construct(
        private {$moduleNameSingular}RepositoryInterface \$repo,
    ) {}

    public function handle(Create{$moduleNameSingular}Command \$c): {$moduleNameSingular}DTO
    {
        \$entity = {$moduleNameSingular}::create(
            id: null,
            uuid: (string) Str::ulid(),
            description: \$c->description,
            createdBy: \$c->createdBy,
            updatedBy: \$c->updatedBy,
            updatedAt: null,
        );

        return {$moduleNameSingular}DTO::fromDomain(\$this->repo->save(\$entity));
    }
}
PHP;
        File::put("{$handlersPath}/Create{$moduleNameSingular}Handler.php", $createContent);
        $this->line("Created: Application/Handlers/Create{$moduleNameSingular}Handler.php");

        // List
        $listContent = <<<PHP
<?php

declare(strict_types=1);

namespace {$this->moduleNs}\\{$moduleNamePlural}\\Application\\Handlers;

use {$this->moduleNs}\\{$moduleNamePlural}\\Application\\DTOs\\{$moduleNameSingular}CollectionDTO;
use {$this->moduleNs}\\{$moduleNamePlural}\\Domain\\Repositories\\{$moduleNameSingular}RepositoryInterface;

/**
 * @group {$moduleNamePlural}
 *
 * Handler para la lista de {$moduleNamePlural}
 */
final class List{$moduleNamePlural}Handler
{
    public function __construct(
        private {$moduleNameSingular}RepositoryInterface \$repo,
    ) {}

    public function handle(array \$filters = []): {$moduleNameSingular}CollectionDTO
    {
        \$result = \$this->repo->list(\$filters);

        return {$moduleNameSingular}CollectionDTO::fromDomain(
            \$result['data'] ?? [],
            \$result['total'] ?? 0,
        );
    }
}
PHP;
        File::put("{$handlersPath}/List{$moduleNamePlural}Handler.php", $listContent);
        $this->line("Created: Application/Handlers/List{$moduleNamePlural}Handler.php");

        // Update
        $updateContent = <<<PHP
<?php

declare(strict_types=1);

namespace {$this->moduleNs}\\{$moduleNamePlural}\\Application\\Handlers;

use {$this->moduleNs}\\{$moduleNamePlural}\\Application\\Commands\\Update{$moduleNameSingular}Command;
use {$this->moduleNs}\\{$moduleNamePlural}\\Application\\DTOs\\{$moduleNameSingular}DTO;
use {$this->moduleNs}\\{$moduleNamePlural}\\Domain\\Repositories\\{$moduleNameSingular}RepositoryInterface;

final class Update{$moduleNameSingular}Handler
{
    public function __construct(
        private {$moduleNameSingular}RepositoryInterface \$repo,
    ) {}

    public function handle(Update{$moduleNameSingular}Command \$command): {$moduleNameSingular}DTO
    {
        \$entity = \$this->repo->findByUuid(\$command->uuid);

        \$entity->update(
            description: \$command->description,
            updatedBy: \$command->updatedBy,
        );

        \$this->repo->update(\$entity);

        \$fresh = \$this->repo->findByUuid(\$command->uuid);

        return {$moduleNameSingular}DTO::fromDomain(\$fresh);
    }
}
PHP;
        File::put("{$handlersPath}/Update{$moduleNameSingular}Handler.php", $updateContent);
        $this->line("Created: Application/Handlers/Update{$moduleNameSingular}Handler.php");

        // Get by UUID
        $getContent = <<<PHP
<?php

declare(strict_types=1);

namespace {$this->moduleNs}\\{$moduleNamePlural}\\Application\\Handlers;

use {$this->moduleNs}\\{$moduleNamePlural}\\Application\\DTOs\\{$moduleNameSingular}DTO;
use {$this->moduleNs}\\{$moduleNamePlural}\\Domain\\Repositories\\{$moduleNameSingular}RepositoryInterface;
use {$this->moduleNs}\\{$moduleNamePlural}\\Domain\\Exceptions\\{$moduleNameSingular}NotFoundException;

final class Get{$moduleNameSingular}ByUuidHandler
{
    public function __construct(
        private {$moduleNameSingular}RepositoryInterface \$repo,
    ) {}

    public function handle(string \$uuid): {$moduleNameSingular}DTO
    {
        \$entity = \$this->repo->findByUuid(\$uuid);

        if (\$entity === null) {
            throw {$moduleNameSingular}NotFoundException::withUuid(\$uuid);
        }

        return {$moduleNameSingular}DTO::fromDomain(\$entity);
    }
}
PHP;
        File::put("{$handlersPath}/Get{$moduleNameSingular}ByUuidHandler.php", $getContent);
        $this->line("Created: Application/Handlers/Get{$moduleNameSingular}ByUuidHandler.php");

        // Delete
        $deleteContent = <<<PHP
<?php

declare(strict_types=1);

namespace {$this->moduleNs}\\{$moduleNamePlural}\\Application\\Handlers;

use {$this->moduleNs}\\{$moduleNamePlural}\\Application\\Commands\\Delete{$moduleNameSingular}Command;
use {$this->moduleNs}\\{$moduleNamePlural}\\Domain\\Repositories\\{$moduleNameSingular}RepositoryInterface;
use {$this->moduleNs}\\{$moduleNamePlural}\\Domain\\Exceptions\\{$moduleNameSingular}NotFoundException;

final class Delete{$moduleNameSingular}Handler
{
    public function __construct(
        private {$moduleNameSingular}RepositoryInterface \$repo,
    ) {}

    public function handle(Delete{$moduleNameSingular}Command \$command): void
    {
        \$entity = \$this->repo->findByUuid(\$command->uuid);

        if (\$entity === null) {
            throw {$moduleNameSingular}NotFoundException::withUuid(\$command->uuid);
        }

        \$this->repo->delete((string) \$entity->id());
    }
}
PHP;
        File::put("{$handlersPath}/Delete{$moduleNameSingular}Handler.php", $deleteContent);
        $this->line("Created: Application/Handlers/Delete{$moduleNameSingular}Handler.php");
    }

    /**
     * Create Vue front pages (Index, Create, Edit, Show) from templates
     */
    private function createVueFront(
        string $moduleNamePlural,
        string $moduleNamePluralLower,
        string $moduleNameSingularLower
    ): void {
        $pagesPath = resource_path("js/{$this->pagesPath}/{$moduleNamePlural}");
        if (!File::exists($pagesPath)) {
            File::makeDirectory($pagesPath, 0755, true);
        }
        $this->line("Created: resources/js/Pages/{$moduleNamePlural}");

        $plural = $moduleNamePlural;
        $pluralLower = $moduleNamePluralLower;
        $singularLower = $moduleNameSingularLower;
        $editProp = "{$singularLower}Edit";
        $showProp = $singularLower;

        // ===== Index.vue =====
        $indexContent = <<<VUE
<script>
    import Layout from "@/Layouts/main.vue";
    import PageHeader from "@/Components/page-header.vue";
    import DataTable from "@/Components/DataTable.vue";
    import { router } from '@inertiajs/vue3';
    import { useAlert } from '@/Composables/useSweetAlert.js';
    import { useFetchPetition } from '@/Composables/useFetchPetition.js';

    const { showAlert, showLoading, showConfirm } = useAlert();
    const { fetchPetition } = useFetchPetition();

    export default {
        name: '{$plural}Index',
        components: {
            Layout,
            PageHeader,
            DataTable,
        },
        props: {
            {$pluralLower}: {
                type: Object,
                required: true,
                default: () => ({ data: [], meta: { total: 0 } }),
            },
        },
        data() {
            return {
                tableHeaders: [
                    { label: 'Descripción', key: 'description', width: '80%' },
                    { label: 'Acciones', key: 'actions', width: '20%' },
                ],
                searchQuery: '',
                currentPage: 1,
            };
        },
        computed: {
            filteredItems() {
                const rows = this.{$pluralLower}?.data ?? [];
                const raw = (this.searchQuery || '').trim();
                if (!raw) return rows;
                const q = raw.toLowerCase();
                return rows.filter((item) =>
                    String(item.description ?? '').toLowerCase().includes(q)
                );
            },
        },
        methods: {
            async deleteItem(uuid) {
                const confirmed = await showConfirm(
                    'warning',
                    '¡Alerta!',
                    '¿Está seguro que desea eliminar este registro?',
                    'Sí, eliminar'
                );
                if (!confirmed) return;
                const loading = showLoading("Eliminando registro", "Por favor espera...");
                const response = await fetchPetition(route('{$pluralLower}.destroy', uuid), { method: 'DELETE' });
                loading.close();
                if (response.ok) {
                    showAlert('success', '¡Éxito!', 'Registro eliminado correctamente', 1500);
                    router.visit(route('{$pluralLower}.index'));
                    return;
                } else {
                    const data = response.data;
                    const message = data?.message || 'Ocurrió un error al eliminar el registro';
                    showAlert('warning', 'Alerta', message, 3000);
                }
            },
        },
        watch: {
            searchQuery() {
                this.currentPage = 1;
            },
        },
    };
</script>

<template>
    <Layout>
        <PageHeader title="{$plural}" pageTitle="Gestión" />
        <div class="row">
            <div class="col-lg-12">
                <div class="card">
                    <div class="card-header border-bottom-dashed">
                        <div class="row g-4 align-items-center">
                            <div class="col-sm">
                                <div>
                                    <h5 class="card-title mb-0">Listado {$plural}</h5>
                                </div>
                            </div>
                            <div class="col-sm-auto">
                                <div class="d-flex flex-wrap gap-2">
                                    <a :href="route('{$pluralLower}.create')" class="btn btn-primary"><i class="ri-add-line align-bottom me-1"></i>Nuevo</a>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="card-body border-bottom-dashed border-bottom">
                        <div class="row g-3">
                            <div class="col-xl-12">
                                <input type="text" class="form-control" placeholder="Buscar..." v-model="searchQuery">
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <DataTable
                            id="tabla_{$pluralLower}"
                            :headers="tableHeaders"
                            :items="filteredItems"
                            :page-length="9"
                            order-by="description"
                        >
                            <template #cell-description="{ item }">
                                <a class="fw-medium text-primary cell-description">
                                    {{ item.description || '' }}
                                </a>
                            </template>
                            <template #cell-actions="{ item }">
                                <ul class="list-inline hstack gap-2 mb-0">
                                    <li class="list-inline-item">
                                        <a :href="route('{$pluralLower}.show', item.uuid)" class="text-primary" title="Ver">
                                            <i class="ri-eye-fill fs-16"></i>
                                        </a>
                                    </li>
                                    <li class="list-inline-item edit">
                                        <a :href="route('{$pluralLower}.edit', item.uuid)" class="text-primary" title="Editar">
                                            <i class="ri-pencil-fill fs-16"></i>
                                        </a>
                                    </li>
                                    <li class="list-inline-item">
                                        <a class="text-danger" style="cursor: pointer;" title="Eliminar" @click="deleteItem(item.uuid)">
                                            <i class="ri-delete-bin-5-fill fs-16"></i>
                                        </a>
                                    </li>
                                </ul>
                            </template>
                        </DataTable>
                    </div>
                </div>
            </div>
        </div>
    </Layout>
</template>

<style scoped>
.cell-description {
    display: inline-block;
    max-width: 700px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
</style>
VUE;
        File::put("{$pagesPath}/Index.vue", $indexContent);
        $this->line("Created: resources/js/Pages/{$moduleNamePlural}/Index.vue");

        // ===== Create.vue =====
        $createContent = <<<VUE
<script>
    import Layout from "@/Layouts/main.vue";
    import PageHeader from "@/Components/page-header.vue";
    import { router } from '@inertiajs/vue3';
    import { useFetchPetition } from "@/Composables/useFetchPetition.js";
    import { useAlert } from "@/Composables/useSweetAlert.js";

    const { showAlert, showLoading, showConfirm } = useAlert();
    const { fetchPetition } = useFetchPetition();

    export default {
        name: '{$plural}Create',
        components: {
            Layout,
            PageHeader,
        },
        data() {
            return {
                form: {
                    description: '',
                },
                loading: false,
                formErrors: {},
            };
        },
        methods: {
            collectRequiredFieldErrors() {
                const e = {};
                const f = this.form;
                if (!f.description || String(f.description).trim() === '') e.description = true;
                return e;
            },
            clearFormError(field) {
                if (this.formErrors[field]) {
                    const next = { ...this.formErrors };
                    delete next[field];
                    this.formErrors = next;
                }
            },
            async submitForm() {
                this.formErrors = {};
                try {
                    const requiredErrors = this.collectRequiredFieldErrors();
                    if (Object.keys(requiredErrors).length > 0) {
                        this.formErrors = requiredErrors;
                        await this.\$nextTick();
                        await showAlert('warning', '¡Alerta!', 'Campos sin diligenciar. Revise los campos resaltados.', 2500);
                        return;
                    }
                    const confirmed = await showConfirm(
                        'warning',
                        '¡Alerta!',
                        '¿Está seguro que desea crear este registro?',
                        'Sí, crear'
                    );
                    if (!confirmed) return;
                    const loading = showLoading("Creando registro", "Por favor espera...");
                    this.loading = true;
                    const response = await fetchPetition(route('{$pluralLower}.store'), {
                        method: 'POST',
                        body: this.form,
                    });
                    loading.close();
                    this.loading = false;
                    if (response.ok) {
                        showAlert('success', '¡Éxito!', 'Registro creado correctamente', 1500);
                        router.visit(route('{$pluralLower}.index'));
                    } else {
                        const data = response.data;
                        const message = data?.message || 'Ocurrió un error al crear el registro';
                        showAlert('warning', 'Alerta', message, 3000);
                    }
                } catch (error) {
                    showAlert('error', 'Error inesperado', error?.message || 'Ocurrió un error al crear el registro', 3000);
                } finally {
                    this.loading = false;
                }
            },
        },
    };
</script>

<template>
    <Head title="Crear {$plural}" />
    <Layout>
        <PageHeader title="Crear {$plural}" pageTitle="{$plural}" />
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">{$plural}</h5>
                    </div>
                    <div class="card-body">
                        <form @submit.prevent="submitForm">
                            <div class="row g-3">
                                <div class="col-12">
                                    <label for="description" class="form-label">Descripción<span class="text-danger ms-1">*</span></label>
                                    <input
                                        v-model="form.description"
                                        autocomplete="off"
                                        type="text"
                                        class="form-control"
                                        placeholder="Ingrese descripción"
                                        maxlength="191"
                                        :class="{ 'field-error': formErrors.description }"
                                        @input="clearFormError('description')"
                                    >
                                </div>
                            </div>
                            <div class="mt-4 text-end">
                                <button type="button" @click="\$inertia.visit(route('{$pluralLower}.index'))" style="margin-right: 10px;" class="btn btn-light"><i class="me-1 ri-logout-circle-line align-bottom"></i>Cancelar</button>
                                <button type="submit" class="btn btn-primary" :disabled="loading"><i class="ri-save-line align-bottom me-1"></i>Guardar</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </Layout>
</template>

<style scoped>
    .form-control.field-error {
        border-color: #dc3545 !important;
        background-image: none !important;
        box-shadow: none !important;
    }
</style>
VUE;
        File::put("{$pagesPath}/Create.vue", $createContent);
        $this->line("Created: resources/js/Pages/{$moduleNamePlural}/Create.vue");

        // ===== Edit.vue =====
        $editContent = <<<VUE
<script>
    import Layout from "@/Layouts/main.vue";
    import PageHeader from "@/Components/page-header.vue";
    import { router } from '@inertiajs/vue3';
    import { useAlert } from '@/Composables/useSweetAlert.js';
    import { useFetchPetition } from '@/Composables/useFetchPetition.js';

    const { showAlert, showLoading, showConfirm } = useAlert();
    const { fetchPetition } = useFetchPetition();

    export default {
        name: '{$plural}Edit',
        components: {
            Layout,
            PageHeader,
        },
        props: {
            uuid: {
                type: String,
                required: true,
            },
            {$editProp}: {
                type: Object,
                required: true,
            },
        },
        data() {
            return {
                form: {
                    description: '',
                },
                loading: false,
                formErrors: {},
            };
        },
        methods: {
            collectRequiredFieldErrors() {
                const e = {};
                const f = this.form;
                if (!f.description || String(f.description).trim() === '') e.description = true;
                return e;
            },
            clearFormError(field) {
                if (this.formErrors[field]) {
                    const next = { ...this.formErrors };
                    delete next[field];
                    this.formErrors = next;
                }
            },
            async submitForm() {
                this.formErrors = {};
                try {
                    const requiredErrors = this.collectRequiredFieldErrors();
                    if (Object.keys(requiredErrors).length > 0) {
                        this.formErrors = requiredErrors;
                        await this.\$nextTick();
                        await showAlert('warning', '¡Alerta!', 'Campos sin diligenciar. Revise los campos resaltados.', 2500);
                        return;
                    }
                    const confirmed = await showConfirm(
                        'warning',
                        '¡Alerta!',
                        '¿Está seguro que desea editar este registro?',
                        'Sí, editar'
                    );
                    if (!confirmed) return;
                    const loading = showLoading("Editando registro", "Por favor espera...");
                    this.loading = true;
                    const response = await fetchPetition(route('{$pluralLower}.update', this.uuid), {
                        method: 'PUT',
                        body: this.form,
                    });
                    loading.close();
                    this.loading = false;
                    if (response.ok) {
                        showAlert('success', '¡Éxito!', 'Registro editado correctamente', 1500);
                        router.visit(route('{$pluralLower}.index'));
                    } else {
                        const data = response.data;
                        const message = data?.message || 'Ocurrió un error al editar el registro';
                        showAlert('error', 'Error', message, 3000);
                    }
                } catch (error) {
                    showAlert('error', 'Error inesperado', error?.message || 'Ocurrió un error al editar el registro', 3000);
                } finally {
                    this.loading = false;
                }
            },
        },
        watch: {
            {$editProp}: {
                immediate: true,
                handler(val) {
                    this.form = {
                        description: val?.description ?? '',
                    };
                },
            },
        },
    };
</script>

<template>
    <Head title="Editar {$plural}" />
    <Layout>
        <PageHeader title="Editar {$plural}" pageTitle="{$plural}" />
        <div class="row">
            <div class="col-lg-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">{$plural}</h5>
                    </div>
                    <div class="card-body">
                        <form @submit.prevent="submitForm">
                            <div class="row g-3">
                                <div class="col-12">
                                    <label for="description" class="form-label">Descripción<span class="text-danger ms-1">*</span></label>
                                    <input
                                        v-model="form.description"
                                        autocomplete="off"
                                        type="text"
                                        class="form-control"
                                        placeholder="Ingrese descripción"
                                        maxlength="191"
                                        :class="{ 'field-error': formErrors.description }"
                                        @input="clearFormError('description')"
                                    >
                                </div>
                            </div>
                            <div class="mt-4 text-end">
                                <button type="button" @click="\$inertia.visit(route('{$pluralLower}.index'))" style="margin-right: 10px;" class="btn btn-light"><i class="me-1 ri-logout-circle-line align-bottom"></i>Cancelar</button>
                                <button type="submit" class="btn btn-primary" :disabled="loading"><span v-if="loading" class="spinner-border spinner-border-sm me-2" role="status"></span><i class="ri-save-line align-bottom me-1"></i>Actualizar</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </Layout>
</template>

<style scoped>
    .form-control.field-error {
        border-color: #dc3545 !important;
        background-image: none !important;
        box-shadow: none !important;
    }
</style>
VUE;
        File::put("{$pagesPath}/Edit.vue", $editContent);
        $this->line("Created: resources/js/Pages/{$moduleNamePlural}/Edit.vue");

        // ===== Show.vue =====
        $showContent = <<<VUE
<script>
    import Layout from "@/Layouts/main.vue";
    import PageHeader from "@/Components/page-header.vue";

    export default {
        name: '{$plural}Show',
        components: {
            Layout,
            PageHeader,
        },
        props: {
            uuid: {
                type: String,
                required: true,
            },
            {$showProp}: {
                type: Object,
                required: true,
            },
        },
    };
</script>

<template>
    <Head title="Detalle {$plural}" />
    <Layout>
        <PageHeader title="Detalle {$plural}" pageTitle="{$plural}" />
        <div class="row">
            <div class="col-lg-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">{$plural}</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Descripción</label>
                                <p class="text-muted">{{ {$showProp}.description }}</p>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-12 text-end">
                                <button type="button" class="btn btn-light me-2" @click="\$inertia.visit(route('{$pluralLower}.index'))">
                                    Volver
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </Layout>
</template>
VUE;
        File::put("{$pagesPath}/Show.vue", $showContent);
        $this->line("Created: resources/js/Pages/{$moduleNamePlural}/Show.vue");
    }

    /**
     * Create ServiceProvider
     */
    /**
     * Crea la estructura "DataBridge": el listado del módulo expuesto a otros
     * módulos mediante contratos Shared (List + Match), con su DTO, services y
     * repositorio de listado autocontenido. Réplica del patrón de Concepts.
     */
    private function createDataBridge(
        string $basePath,
        string $moduleNamePlural,
        string $moduleNameSingular,
        string $tableName
    ): void {
        $modelName = $moduleNameSingular;
        $listDto = "{$moduleNamePlural}ListDTO";
        $listContract = "List{$moduleNamePlural}Contract";
        $matchContract = "Match{$moduleNamePlural}RowContract";
        $listService = "Fetch{$moduleNamePlural}ListService";
        $matchService = "Match{$moduleNamePlural}RowService";
        $listRepoInterface = "{$moduleNamePlural}ListRepositoryInterface";
        $listRepoImpl = "Eloquent{$moduleNamePlural}ListRepository";

        $sharedDir = $this->nsToPath($this->sharedNs)."/{$moduleNamePlural}";
        $servicesPath = "{$basePath}/Application/Services";
        File::ensureDirectoryExists($sharedDir);
        File::ensureDirectoryExists($servicesPath);

        // ===== Shared: List contract =====
        $listContractContent = <<<PHP
<?php

declare(strict_types=1);

namespace {$this->sharedNs}\\{$moduleNamePlural};

use {$this->moduleNs}\\{$moduleNamePlural}\\Application\\DTOs\\{$listDto};

/**
 * Caso de uso: listar {$tableName} del tenant, filtrable y con paginación
 * OPCIONAL. Sin paginar -> se devuelven todos.
 *
 * A diferencia de DataBridge NO recibe \$listing: el módulo dueño ES el listado.
 *
 * @see README.md en este directorio
 */
interface {$listContract}
{
    /**
     * @param  array<string, mixed>|null  \$filters  Columnas, 'search' y opcionalmente page/per_page
     * @param  bool  \$matchAny  false (AND, por defecto) exige todas las columnas; true (OR) basta con una
     */
    public function execute(?array \$filters = null, bool \$matchAny = false): {$listDto};
}
PHP;
        File::put("{$sharedDir}/{$listContract}.php", $listContractContent);
        $this->line("Created: Shared/Contracts/{$moduleNamePlural}/{$listContract}.php");

        // ===== Shared: Match contract =====
        $matchContractContent = <<<PHP
<?php

declare(strict_types=1);

namespace {$this->sharedNs}\\{$moduleNamePlural};

/**
 * Post-filtra las filas devueltas por {$listContract}::execute()->getData()
 * localizando la primera cuyo campo coincide EXACTAMENTE con el valor esperado.
 *
 * Los filtros de texto del listado usan coincidencia parcial (ILIKE), por lo
 * que execute() puede devolver varias filas; este contrato resuelve la fila
 * exacta por clave de negocio, o null si ninguna coincide.
 */
interface {$matchContract}
{
    /**
     * @param  list<array<string, mixed>>  \$rows
     * @return array<string, mixed>|null
     */
    public function matchByField(array \$rows, string \$field, mixed \$expectedValue): ?array;
}
PHP;
        File::put("{$sharedDir}/{$matchContract}.php", $matchContractContent);
        $this->line("Created: Shared/Contracts/{$moduleNamePlural}/{$matchContract}.php");

        // ===== Shared: README =====
        $readmeContent = <<<MD
# Contrato {$moduleNamePlural} (`{$this->sharedNs}\\{$moduleNamePlural}`)

Lectura de `{$tableName}` para otros módulos, sin acoplarse al repositorio del
módulo dueño. Inspirado en el contrato de DataBridge, pero **sin `listing`** (el
módulo ES el listado) y resolviendo contra el Eloquent propio.

Implementación: `{$this->moduleNs}\\{$moduleNamePlural}\\Application\\Services\\{$listService}`.

## Firma

```php
public function execute(?array \$filters = null, bool \$matchAny = false): {$listDto};
```

## Filtros

| Clave | Efecto |
|-------|--------|
| `['columna' => 'valor']` | Filtra por columna (texto = ILIKE; id/uuid/status = exacto) |
| `['columna' => [v1, v2]]` | WHERE IN |
| `search` | Búsqueda global en columnas no exactas |
| `page` + `per_page` | Paginan. **Si faltan, devuelve TODOS sin límite** |

`matchAny`: `false` (AND, por defecto) exige todas las columnas; `true` (OR) basta con una.

## Respuesta

`success / data / meta`. Cada fila en `data` con columnas en `camelCase` más el
campo calculado `name`. Con paginación, `meta` incluye `current_page`,
`per_page`, `last_page`.

## Post-filtro (`{$matchContract}`)

Para obtener UNA fila exacta de la lista (los filtros de texto son parciales):

```php
matchByField(array \$rows, string \$field, mixed \$expectedValue): ?array;
```

## Ejemplos

```php
// Todos los registros (sin paginar)
\$todos = \$this->list->execute()->getData();

// Filtrado + paginado
\$dto = \$this->list->execute(['description' => 'algo', 'page' => 1, 'per_page' => 15]);

// Una sola fila: listar + post-filtrar exacto
\$rows = \$this->list->execute(['uuid' => '...'])->getData();
\$row = \$this->matchRow->matchByField(\$rows, 'uuid', '...');
```
MD;
        File::put("{$sharedDir}/README.md", $readmeContent);
        $this->line("Created: Shared/Contracts/{$moduleNamePlural}/README.md");

        // ===== Module: List DTO =====
        $listDtoContent = <<<PHP
<?php

declare(strict_types=1);

namespace {$this->moduleNs}\\{$moduleNamePlural}\\Application\\DTOs;

use JsonSerializable;

/**
 * Misma forma que DataBridge: success, data, meta.
 */
final class {$listDto} implements JsonSerializable
{
    /**
     * @param  list<array<string, mixed>>  \$data
     * @param  array<string, mixed>  \$meta
     */
    public function __construct(
        private readonly bool \$success,
        private readonly array \$data,
        private readonly array \$meta,
    ) {}

    /**
     * @param  array{success?: mixed, data?: mixed, meta?: mixed}  \$result
     */
    public static function fromRepositoryResult(array \$result): self
    {
        \$data = is_array(\$result['data'] ?? null) ? \$result['data'] : [];
        \$meta = is_array(\$result['meta'] ?? null) ? \$result['meta'] : [];

        return new self((bool) (\$result['success'] ?? true), \$data, \$meta);
    }

    public function isSuccess(): bool
    {
        return \$this->success;
    }

    public function isEmpty(): bool
    {
        return \$this->data === [];
    }

    /** @return list<array<string, mixed>> */
    public function getData(): array
    {
        return \$this->data;
    }

    /** @return array<string, mixed> */
    public function getMeta(): array
    {
        return \$this->meta;
    }

    /** @return array{success: bool, data: array, meta: array<string, mixed>} */
    public function toArray(): array
    {
        return [
            'success' => \$this->success,
            'data' => \$this->data,
            'meta' => \$this->meta,
        ];
    }

    public function jsonSerialize(): array
    {
        return \$this->toArray();
    }
}
PHP;
        File::put("{$basePath}/Application/DTOs/{$listDto}.php", $listDtoContent);
        $this->line("Created: Application/DTOs/{$listDto}.php");

        // ===== Module: Fetch list service =====
        $listServiceContent = <<<PHP
<?php

declare(strict_types=1);

namespace {$this->moduleNs}\\{$moduleNamePlural}\\Application\\Services;

use {$this->moduleNs}\\{$moduleNamePlural}\\Application\\DTOs\\{$listDto};
use {$this->moduleNs}\\{$moduleNamePlural}\\Domain\\Repositories\\{$listRepoInterface};
use {$this->sharedNs}\\{$moduleNamePlural}\\{$listContract};

/**
 * Service de listado del módulo {$moduleNamePlural}. Sin match ni \$listing:
 * un módulo = un listado. Arma el query y delega en el repositorio.
 */
final class {$listService} implements {$listContract}
{
    public function __construct(
        private readonly {$listRepoInterface} \$repository,
    ) {}

    public function execute(?array \$filters = null, bool \$matchAny = false): {$listDto}
    {
        \$query = \$filters ?? [];
        \$query['match_any'] = \$matchAny;

        return {$listDto}::fromRepositoryResult(\$this->repository->list(\$query));
    }
}
PHP;
        File::put("{$servicesPath}/{$listService}.php", $listServiceContent);
        $this->line("Created: Application/Services/{$listService}.php");

        // ===== Module: Match row service =====
        $matchServiceContent = <<<PHP
<?php

declare(strict_types=1);

namespace {$this->moduleNs}\\{$moduleNamePlural}\\Application\\Services;

use {$this->sharedNs}\\{$moduleNamePlural}\\{$matchContract};

final class {$matchService} implements {$matchContract}
{
    public function matchByField(array \$rows, string \$field, mixed \$expectedValue): ?array
    {
        foreach (\$rows as \$row) {
            if (! is_array(\$row)) {
                continue;
            }

            if ((\$row[\$field] ?? null) === \$expectedValue) {
                return \$row;
            }
        }

        return null;
    }
}
PHP;
        File::put("{$servicesPath}/{$matchService}.php", $matchServiceContent);
        $this->line("Created: Application/Services/{$matchService}.php");

        // ===== Module: List repository interface =====
        $listRepoInterfaceContent = <<<PHP
<?php

declare(strict_types=1);

namespace {$this->moduleNs}\\{$moduleNamePlural}\\Domain\\Repositories;

/**
 * Contrato de listado de `{$tableName}` al estilo DataBridge: un módulo = un
 * listado, con filtros por columna, búsqueda global y paginación OPCIONAL.
 */
interface {$listRepoInterface}
{
    /**
     * @param  array<string, mixed>  \$query  filtros + (opcional) page, per_page, match_any
     * @return array{success: bool, data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function list(array \$query): array;
}
PHP;
        File::put("{$basePath}/Domain/Repositories/{$listRepoInterface}.php", $listRepoInterfaceContent);
        $this->line("Created: Domain/Repositories/{$listRepoInterface}.php");

        // ===== Module: Eloquent list repository =====
        $listRepoImplContent = <<<PHP
<?php

declare(strict_types=1);

namespace {$this->moduleNs}\\{$moduleNamePlural}\\Infrastructure\\Database\\Repositories;

use {$this->moduleNs}\\{$moduleNamePlural}\\Domain\\Repositories\\{$listRepoInterface};
use {$this->moduleNs}\\{$moduleNamePlural}\\Infrastructure\\Database\\Models\\{$modelName};
use App\\Shared\\Infrastructure\\Database\\Repositories\\BaseSearchRepository;
use Illuminate\\Database\\Eloquent\\Collection;
use Illuminate\\Database\\Eloquent\\Model;
use Illuminate\\Support\\Carbon;
use Illuminate\\Support\\Str;

/**
 * Lógica de listado AUTOCONTENIDA: filtros + paginación opcional + mapeo
 * dinámico snake_case -> camelCase. Replica el lookup de DataBridge contra el
 * modelo propio del módulo ({$modelName}, tabla {$tableName}).
 */
final class {$listRepoImpl} implements {$listRepoInterface}
{
    public function list(array \$query): array
    {
        \$page = isset(\$query['page']) ? (int) \$query['page'] : null;
        \$perPage = isset(\$query['per_page']) ? (int) \$query['per_page'] : null;
        \$matchAny = (bool) (\$query['match_any'] ?? false);
        \$filters = array_diff_key(\$query, array_flip(['page', 'per_page', 'match_any']));

        \$builder = {$modelName}::query();
        BaseSearchRepository::applyPaginateColumnFilters(
            \$builder, \$filters, \$this->allowedColumns(), '', \$matchAny
        );
        \$builder->orderBy('created_at', 'desc');

        // Sin page/per_page -> TODOS los registros, sin límite.
        if (\$page === null || \$perPage === null) {
            \$rows = \$builder->get();

            return [
                'success' => true,
                'data' => \$this->mapRows(\$rows),
                'meta' => ['total' => \$rows->count()],
            ];
        }

        \$total = (clone \$builder)->count();
        \$perPage = max(1, \$perPage);
        \$lastPage = (int) max(1, (int) ceil(\$total / \$perPage));
        \$rows = \$builder->forPage(\$page, \$perPage)->get();

        return [
            'success' => true,
            'data' => \$this->mapRows(\$rows),
            'meta' => [
                'total' => \$total,
                'current_page' => \$page,
                'per_page' => \$perPage,
                'last_page' => \$lastPage,
            ],
        ];
    }

    /** @return list<string> */
    private function allowedColumns(): array
    {
        \$model = new {$modelName}();

        return \$model->getFillable() !== []
            ? \$model->getFillable()
            : \$model->getConnection()->getSchemaBuilder()->getColumnListing(\$model->getTable());
    }

    /**
     * @param  Collection<int, Model>  \$rows
     * @return list<array<string, mixed>>
     */
    private function mapRows(Collection \$rows): array
    {
        return \$rows->map(fn (Model \$row): array => array_merge(
            \$this->baseFields(\$row),
            \$this->extraFields(\$row),
        ))->all();
    }

    /** @return array<string, mixed> */
    private function baseFields(Model \$row): array
    {
        \$output = [];
        foreach (\$row->getAttributes() as \$column => \$value) {
            \$output[Str::camel(\$column)] = \$this->normalizeValue(\$column, \$value);
        }

        return \$output;
    }

    /**
     * Campos calculados propios del módulo. `name` es la etiqueta lista para
     * selects; ajústelo según las columnas reales del módulo.
     *
     * @return array<string, mixed>
     */
    private function extraFields(Model \$row): array
    {
        \$description = (string) (\$row->getAttribute('description') ?? '');
        \$name = \$description !== '' ? \$description : '{$modelName} #'.(int) \$row->getAttribute('id');

        return [
            'name' => \$name,
        ];
    }

    private function normalizeValue(string \$column, mixed \$value): mixed
    {
        if (\$value === null) {
            return null;
        }
        if (str_ends_with(\$column, '_at')) {
            return Carbon::parse((string) \$value)->toAtomString();
        }
        if (str_ends_with(\$column, '_date')) {
            return Carbon::parse((string) \$value)->format('Y-m-d');
        }

        return \$value;
    }
}
PHP;
        File::put("{$basePath}/Infrastructure/Database/Repositories/{$listRepoImpl}.php", $listRepoImplContent);
        $this->line("Created: Infrastructure/Database/Repositories/{$listRepoImpl}.php");
    }

    private function createServiceProvider(
        string $basePath,
        string $moduleNamePlural,
        string $moduleNameLower,
        string $moduleNameSingular
    ): void {
        $serviceProviderName = "{$moduleNamePlural}ServiceProvider";
        $repositoryInterface = "{$moduleNameSingular}RepositoryInterface";
        $repositoryImplementation = "Eloquent{$moduleNameSingular}Repository";
        $listRepositoryInterface = "{$moduleNamePlural}ListRepositoryInterface";
        $listRepositoryImplementation = "Eloquent{$moduleNamePlural}ListRepository";
        $listContract = "List{$moduleNamePlural}Contract";
        $listService = "Fetch{$moduleNamePlural}ListService";
        $matchContract = "Match{$moduleNamePlural}RowContract";
        $matchService = "Match{$moduleNamePlural}RowService";

        $content = <<<PHP
<?php
declare(strict_types=1);

namespace {$this->moduleNs}\\{$moduleNamePlural};

use Illuminate\\Support\\ServiceProvider;
use {$this->moduleNs}\\{$moduleNamePlural}\\Domain\\Repositories\\{$repositoryInterface};
use {$this->moduleNs}\\{$moduleNamePlural}\\Infrastructure\\Database\\Repositories\\{$repositoryImplementation};
use {$this->moduleNs}\\{$moduleNamePlural}\\Domain\\Repositories\\{$listRepositoryInterface};
use {$this->moduleNs}\\{$moduleNamePlural}\\Infrastructure\\Database\\Repositories\\{$listRepositoryImplementation};
use {$this->moduleNs}\\{$moduleNamePlural}\\Application\\Services\\{$listService};
use {$this->moduleNs}\\{$moduleNamePlural}\\Application\\Services\\{$matchService};
use {$this->sharedNs}\\{$moduleNamePlural}\\{$listContract};
use {$this->sharedNs}\\{$moduleNamePlural}\\{$matchContract};

final class {$serviceProviderName} extends ServiceProvider
{
    public function register(): void
    {
        \$this->app->bind({$repositoryInterface}::class, {$repositoryImplementation}::class);

        // DataBridge: listado del módulo expuesto a otros módulos vía contrato Shared.
        \$this->app->singleton({$listRepositoryInterface}::class, {$listRepositoryImplementation}::class);
        \$this->app->singleton({$listContract}::class, {$listService}::class);
        \$this->app->singleton({$matchContract}::class, {$matchService}::class);
    }

    public function boot(): void
    {
        \$this->loadRoutesFrom(__DIR__.'/Infrastructure/Http/Routes/web.php');
        \$this->loadMigrationsFrom(__DIR__.'/Infrastructure/Database/Migrations');
    }
}
PHP;
        
        File::put("{$basePath}/{$serviceProviderName}.php", $content);
        $this->line("Created: {$serviceProviderName}.php");
    }
    
    /**
     * Create .gitkeep files in empty directories
     */
    /**
     * Create Repository Interface
     */
    private function createRepositoryInterface(
        string $basePath,
        string $moduleNamePlural,
        string $moduleNameSingular
    ): void {
        $interfaceName = "{$moduleNameSingular}RepositoryInterface";
        $content = <<<PHP
<?php

declare(strict_types=1);

namespace {$this->moduleNs}\\{$moduleNamePlural}\\Domain\\Repositories;

use {$this->moduleNs}\\{$moduleNamePlural}\\Domain\\Entities\\{$moduleNameSingular};

interface {$interfaceName}
{
    public function findByUuid(string \$uuid): ?{$moduleNameSingular};

    public function list(array \$filters = []): array;

    public function save({$moduleNameSingular} \$entity): {$moduleNameSingular};

    public function update({$moduleNameSingular} \$entity): void;

    public function delete(string \$id): void;
}
PHP;

        File::put("{$basePath}/Domain/Repositories/{$interfaceName}.php", $content);
        $this->line("Created: Domain/Repositories/{$interfaceName}.php");
    }
    
    /**
     * Create Repository Implementation
     */
    private function createRepositoryImplementation(
        string $basePath,
        string $moduleNamePlural,
        string $moduleNameSingular,
        string $moduleNameLower,
        string $moduleNamePluralLower
    ): void {
        $repositoryName = "Eloquent{$moduleNameSingular}Repository";
        $interfaceName = "{$moduleNameSingular}RepositoryInterface";
        $modelName = $moduleNameSingular;
        $entityAlias = "{$moduleNameSingular}Entity";

        $content = <<<PHP
<?php

declare(strict_types=1);

namespace {$this->moduleNs}\\{$moduleNamePlural}\\Infrastructure\\Database\\Repositories;

use {$this->moduleNs}\\{$moduleNamePlural}\\Domain\\Repositories\\{$interfaceName};
use {$this->moduleNs}\\{$moduleNamePlural}\\Domain\\Entities\\{$moduleNameSingular} as {$entityAlias};
use {$this->moduleNs}\\{$moduleNamePlural}\\Infrastructure\\Database\\Models\\{$modelName};
use Illuminate\\Support\\Facades\\DB;

final class {$repositoryName} implements {$interfaceName}
{
    public function findByUuid(string \$uuid): ?{$entityAlias}
    {
        \$m = {$modelName}::where('uuid', \$uuid)->first();

        return \$m ? \$this->toDomain(\$m) : null;
    }

    public function save({$entityAlias} \$p): {$entityAlias}
    {
        return DB::transaction(function () use (\$p) {
            \$m = new {$modelName}();
            \$m->uuid = \$p->uuid();
            \$m->description = \$p->description();
            \$m->created_by = \$p->createdBy();
            \$m->updated_by = \$p->updatedBy();
            \$m->created_at = now();
            \$m->updated_at = now();
            \$m->save();
            \$m->refresh();

            return \$this->toDomain(\$m);
        });
    }

    public function update({$entityAlias} \$u): void
    {
        \$m = {$modelName}::where('uuid', \$u->uuid())->first();

        if (\$m) {
            \$m->description = \$u->description();
            \$m->updated_by = \$u->updatedBy();
            \$m->updated_at = now();
            \$m->save();
        }
    }

    public function delete(string \$id): void
    {
        {$modelName}::where('id', \$id)->delete();
    }

    public function list(array \$filters = []): array
    {
        \$q = {$modelName}::query();

        if (!empty(\$filters['search'])) {
            \$s = \$filters['search'];
            \$q->where('description', 'like', "%{\$s}%");
        }

        \$total = (clone \$q)->count();

        \$page = (int) (\$filters['page'] ?? 1);
        \$perPage = (int) (\$filters['per_page'] ?? 15);

        \$rows = \$q->orderByDesc('created_at')
            ->forPage(\$page, \$perPage)
            ->get();

        return [
            'data' => array_map(fn(\$m) => \$this->toDomain(\$m), \$rows->all()),
            'total' => \$total,
        ];
    }

    private function toDomain({$modelName} \$m): {$entityAlias}
    {
        return new {$entityAlias}(
            id: (int) \$m->id,
            uuid: \$m->uuid,
            description: \$m->description,
            createdBy: (string) \$m->created_by,
            updatedBy: \$m->updated_by !== null ? (string) \$m->updated_by : null,
            createdAt: new \\DateTimeImmutable(\$m->created_at?->toAtomString() ?? 'now'),
            updatedAt: \$m->updated_at ? new \\DateTimeImmutable(\$m->updated_at->toAtomString()) : null,
            deletedAt: \$m->deleted_at ? new \\DateTimeImmutable(\$m->deleted_at->toAtomString()) : null,
        );
    }
}
PHP;

        File::put("{$basePath}/Infrastructure/Database/Repositories/{$repositoryName}.php", $content);
        $this->line("Created: Infrastructure/Database/Repositories/{$repositoryName}.php");
    }
    
    /**
     * Create NotFoundException
     */
    private function createNotFoundException(
        string $basePath,
        string $moduleNamePlural,
        string $moduleNameSingular
    ): void {
        $exceptionName = "{$moduleNameSingular}NotFoundException";
        $content = <<<PHP
<?php

declare(strict_types=1);

namespace {$this->moduleNs}\\{$moduleNamePlural}\\Domain\\Exceptions;

use Exception;

final class {$exceptionName} extends Exception
{
    public static function withUuid(string \$uuid): self
    {
        return new self("{$moduleNameSingular} con UUID {\$uuid} no encontrado");
    }
}
PHP;
        
        File::put("{$basePath}/Domain/Exceptions/{$exceptionName}.php", $content);
        $this->line("Created: Domain/Exceptions/{$exceptionName}.php");
    }
    
    /**
     * Create Controller with empty methods
     */
    private function createController(
        string $basePath,
        string $moduleNamePlural,
        string $moduleNameSingular,
        string $moduleNamePluralLower,
        string $moduleNameSingularLower
    ): void {
        $controllerName = "{$moduleNameSingular}Controller";
        $inertiaFolder = $moduleNamePlural;
        $listHandler = "List{$moduleNamePlural}Handler";
        $getHandler = "Get{$moduleNameSingular}ByUuidHandler";
        $createHandler = "Create{$moduleNameSingular}Handler";
        $updateHandler = "Update{$moduleNameSingular}Handler";
        $deleteHandler = "Delete{$moduleNameSingular}Handler";
        $createCommand = "Create{$moduleNameSingular}Command";
        $updateCommand = "Update{$moduleNameSingular}Command";
        $deleteCommand = "Delete{$moduleNameSingular}Command";
        $createRequest = "Create{$moduleNameSingular}Request";
        $updateRequest = "Update{$moduleNameSingular}Request";
        $filterRequest = "Filter{$moduleNamePlural}Request";
        $indexProp = $moduleNamePluralLower;
        $editProp = "{$moduleNameSingularLower}Edit";
        $showProp = $moduleNameSingularLower;

        $content = <<<PHP
<?php

declare(strict_types=1);

namespace {$this->moduleNs}\\{$moduleNamePlural}\\Infrastructure\\Http\\Controllers;

use App\\Http\\Controllers\\Controller;
use Inertia\\Inertia;
use Illuminate\\Support\\Facades\\Auth;

use {$this->moduleNs}\\{$moduleNamePlural}\\Infrastructure\\Http\\Requests\\{$createRequest};
use {$this->moduleNs}\\{$moduleNamePlural}\\Infrastructure\\Http\\Requests\\{$updateRequest};
use {$this->moduleNs}\\{$moduleNamePlural}\\Infrastructure\\Http\\Requests\\{$filterRequest};

use {$this->moduleNs}\\{$moduleNamePlural}\\Application\\Handlers\\{$listHandler};
use {$this->moduleNs}\\{$moduleNamePlural}\\Application\\Handlers\\{$getHandler};
use {$this->moduleNs}\\{$moduleNamePlural}\\Application\\Handlers\\{$createHandler};
use {$this->moduleNs}\\{$moduleNamePlural}\\Application\\Handlers\\{$updateHandler};
use {$this->moduleNs}\\{$moduleNamePlural}\\Application\\Handlers\\{$deleteHandler};

use {$this->moduleNs}\\{$moduleNamePlural}\\Application\\Commands\\{$createCommand};
use {$this->moduleNs}\\{$moduleNamePlural}\\Application\\Commands\\{$updateCommand};
use {$this->moduleNs}\\{$moduleNamePlural}\\Application\\Commands\\{$deleteCommand};

final class {$controllerName} extends Controller
{
    public function __construct(
        private {$listHandler} \$listHandler,
    ) {}

    public function index({$filterRequest} \$request)
    {
        \$result = \$this->listHandler->handle(\$request->validated());

        return Inertia::render('{$inertiaFolder}/Index', [
            '{$indexProp}' => \$result->toArray(),
        ]);
    }

    public function viewCreate()
    {
        return Inertia::render('{$inertiaFolder}/Create', []);
    }

    public function viewShow(string \$uuid, {$getHandler} \$handler)
    {
        \$dto = \$handler->handle(\$uuid);

        return Inertia::render('{$inertiaFolder}/Show', [
            'uuid' => \$uuid,
            '{$showProp}' => \$dto->toArray(),
        ]);
    }

    public function viewEdit(string \$uuid, {$getHandler} \$handler)
    {
        \$dto = \$handler->handle(\$uuid);

        return Inertia::render('{$inertiaFolder}/Edit', [
            'uuid' => \$uuid,
            '{$editProp}' => \$dto->toArray(),
        ]);
    }

    public function store({$createRequest} \$request, {$createHandler} \$handler)
    {
        \$actorId = (string) (Auth::id() ?? 1);

        \$command = new {$createCommand}(
            description: \$request->input('description'),
            createdBy: \$actorId,
            updatedBy: \$actorId,
        );

        \$dto = \$handler->handle(\$command);

        return response()->json(['data' => \$dto->toArray()], 201);
    }

    public function update(string \$uuid, {$updateRequest} \$request, {$updateHandler} \$handler)
    {
        \$actorId = (string) (Auth::id() ?? 1);

        \$command = new {$updateCommand}(
            uuid: \$uuid,
            description: \$request->input('description'),
            updatedBy: \$actorId,
        );

        \$dto = \$handler->handle(\$command);

        return response()->json(['data' => \$dto->toArray()]);
    }

    public function destroy(string \$uuid, {$deleteHandler} \$handler)
    {
        \$handler->handle(new {$deleteCommand}(\$uuid));

        return response()->json(['data' => ['message' => 'Registro eliminado correctamente']]);
    }
}
PHP;

        File::put("{$basePath}/Infrastructure/Http/Controllers/{$controllerName}.php", $content);
        $this->line("Created: Infrastructure/Http/Controllers/{$controllerName}.php");
    }

    /**
     * Create Form Requests (Create, Update) from templates
     */
    private function createRequests(
        string $requestsPath,
        string $moduleNamePlural,
        string $moduleNameSingular
    ): void {
        // Create
        $createContent = <<<PHP
<?php

declare(strict_types=1);

namespace {$this->moduleNs}\\{$moduleNamePlural}\\Infrastructure\\Http\\Requests;

use Illuminate\\Foundation\\Http\\FormRequest;

final class Create{$moduleNameSingular}Request extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'description' => ['required', 'string', 'max:191'],
        ];
    }
}
PHP;
        File::put("{$requestsPath}/Create{$moduleNameSingular}Request.php", $createContent);
        $this->line("Created: Infrastructure/Http/Requests/Create{$moduleNameSingular}Request.php");

        // Update
        $updateContent = <<<PHP
<?php

declare(strict_types=1);

namespace {$this->moduleNs}\\{$moduleNamePlural}\\Infrastructure\\Http\\Requests;

use Illuminate\\Foundation\\Http\\FormRequest;

final class Update{$moduleNameSingular}Request extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'description' => ['required', 'string', 'max:191'],
        ];
    }
}
PHP;
        File::put("{$requestsPath}/Update{$moduleNameSingular}Request.php", $updateContent);
        $this->line("Created: Infrastructure/Http/Requests/Update{$moduleNameSingular}Request.php");

        // Filter (listado: búsqueda + paginación)
        $filterContent = <<<PHP
<?php

declare(strict_types=1);

namespace {$this->moduleNs}\\{$moduleNamePlural}\\Infrastructure\\Http\\Requests;

use Illuminate\\Foundation\\Http\\FormRequest;

/**
 * @group {$moduleNamePlural}
 *
 * Request para la filtración de {$moduleNamePlural}
 */
final class Filter{$moduleNamePlural}Request extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'string', 'max:191'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
PHP;
        File::put("{$requestsPath}/Filter{$moduleNamePlural}Request.php", $filterContent);
        $this->line("Created: Infrastructure/Http/Requests/Filter{$moduleNamePlural}Request.php");
    }

    /**
     * Create Routes file
     */
    private function createRoutesFile(
        string $basePath,
        string $moduleNamePlural,
        string $moduleNameLower,
        string $moduleNamePluralLower,
        string $moduleNameSingular
    ): void {
        $controllerName = "{$moduleNameSingular}Controller";
        $routePrefix = $moduleNamePluralLower;
        $routeNamePrefix = $moduleNamePluralLower;
        $singularPrefix = $moduleNameLower;
        $middleware = $this->renderRouteMiddleware();

        $content = <<<PHP
<?php

declare(strict_types=1);

use Illuminate\\Support\\Facades\\Route;
use {$this->moduleNs}\\{$moduleNamePlural}\\Infrastructure\\Http\\Controllers\\{$controllerName};

// Los nombres de ruta ('{$routeNamePrefix}.*') deben conservarse: el frontend los usa con route().
// Los segmentos de URL ('/{$routePrefix}', '/{$singularPrefix}/...') pueden traducirse a español si se requiere.
Route::middleware([{$middleware}])->group(function () {
    Route::get('/{$routePrefix}', [{$controllerName}::class, 'index'])->name('{$routeNamePrefix}.index');

    Route::get('/{$singularPrefix}/create', [{$controllerName}::class, 'viewCreate'])->name('{$routeNamePrefix}.create');
    Route::post('/{$singularPrefix}', [{$controllerName}::class, 'store'])->name('{$routeNamePrefix}.store');

    Route::get('/{$singularPrefix}/{uuid}/show', [{$controllerName}::class, 'viewShow'])->name('{$routeNamePrefix}.show');

    Route::get('/{$singularPrefix}/{uuid}/edit', [{$controllerName}::class, 'viewEdit'])->name('{$routeNamePrefix}.edit');
    Route::put('/{$singularPrefix}/{uuid}', [{$controllerName}::class, 'update'])->name('{$routeNamePrefix}.update');

    Route::delete('/{$singularPrefix}/{uuid}', [{$controllerName}::class, 'destroy'])->name('{$routeNamePrefix}.destroy');
});
PHP;

        File::put("{$basePath}/Infrastructure/Http/Routes/web.php", $content);
        $this->line("Created: Infrastructure/Http/Routes/web.php");
    }

    /**
     * Registrar el ServiceProvider del módulo en config/app.php
     */
    private function registerProviderInConfig(string $moduleNamePlural): void
    {
        $configPath = config_path('app.php');
        $content = File::get($configPath);

        $providerClass = "{$this->moduleNs}\\{$moduleNamePlural}\\{$moduleNamePlural}ServiceProvider::class,";
        $lineToAdd = "        {$providerClass}";

        $marker = "        // Módulos (SAT)";
        if (str_contains($content, $marker)) {
            $content = str_replace(
                $marker,
                $lineToAdd . "\n        " . trim($marker),
                $content
            );
        } else {
            // Solo el primer `])->toArray(),` tras `providers`; no usar str_replace global
            // porque `aliases` cierra con la misma cadena y duplicaría el provider.
            $providersKey = "'providers' => ServiceProvider::defaultProviders()->merge([";
            $providersPos = strpos($content, $providersKey);
            if ($providersPos === false) {
                $this->warn('No se encontró providers en config/app.php. Regístralo manualmente.');
                return;
            }

            $closing = "    ])->toArray(),";
            $insertPos = strpos($content, $closing, $providersPos);
            if ($insertPos === false) {
                $this->warn('No se encontró el cierre del array providers en config/app.php. Regístralo manualmente.');
                return;
            }

            $replacement = "        {$providerClass}\n" . $closing;
            $content = substr($content, 0, $insertPos) . $replacement . substr($content, $insertPos + strlen($closing));
        }

        File::put($configPath, $content);
        $this->line("Registered provider in config/app.php: {$moduleNamePlural}ServiceProvider");
    }
}

