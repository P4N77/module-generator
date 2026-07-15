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

    /** IDs canónicos de apps del ecosistema (AppsSeeder de Suite). */
    private const APP_IDS = [
        'suite' => 1,
        'econnect' => 2,
        'sat' => 3,
        'iris' => 4,
        'tut' => 5,
        'f16' => 6,
        'fintegra' => 7,
    ];

    /** Archivos generados, para el resumen final. @var list<string> */
    private array $created = [];

    /**
     * Contexto de generación compartido por todos los builders (naming derivado
     * y feature flags). Se llena en handle() y cada create* lo lee con extract().
     *
     * @var array<string, mixed>
     */
    private array $ctx = [];

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

        $this->renderBanner();

        $rawName = $this->argument('name');

        // Detectar prefijo opcional con el formato "prefijo/nombreModulo" (ej. shd/email_types).
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
        $moduleNamePluralLower = Str::snake($moduleNamePlural);

        // Naming derivado estándar de las maestras de Suite.
        $moduleCode = Str::camel($moduleNamePlural);      // emailTypes  (route names + Casbin)
        $singularCamel = Str::camel($moduleNameSingular); // emailType   (prop de detalle/edición)
        $kebabPlural = Str::kebab($moduleNamePlural);     // email-types (segmentos de URL)

        // Nombre de la tabla: con prefijo "{prefix}_{module}" o solo "{module}"
        $tableName = $prefix !== null
            ? "{$prefix}_{$moduleNamePluralLower}"
            : $moduleNamePluralLower;

        $basePath = $this->nsToPath($this->moduleNs)."/{$moduleNamePlural}";

        // El módulo no debe existir: módulo, contratos compartidos ni vistas Vue.
        $conflicts = array_values(array_filter([
            $basePath,
            $this->nsToPath($this->sharedNs)."/{$moduleNamePlural}",
            resource_path("js/{$this->pagesPath}/{$moduleNamePlural}"),
        ], static fn (string $p): bool => File::exists($p)));

        if ($conflicts !== []) {
            $this->newLine();
            $this->components->error("El módulo '{$moduleNamePlural}' ya existe. No se creó nada.");
            foreach ($conflicts as $conflict) {
                $this->components->twoColumnDetail('<fg=gray>Ya existe</>', $conflict);
            }
            $this->newLine();

            return Command::FAILURE;
        }

        // ===== Preguntas interactivas (definen la forma del módulo) =====
        $hasCode = $this->confirm('¿El módulo lleva código (columna "code" con unicidad)?', true);

        $type = $this->choice(
            '¿Qué tipo de módulo es?',
            ['usuario (Casbin + menú)', 'interno (solo Sodeker, sin menú)'],
            'usuario (Casbin + menú)'
        );
        $isInternal = str_starts_with($type, 'interno');

        $appSlug = 'suite';
        $visibleName = $moduleNamePlural;
        $indexUrl = '/'.$kebabPlural;

        if (! $isInternal) {
            $appSlug = Str::lower(trim((string) $this->choice(
                'App destino (para seeders/permisos)',
                ['suite', 'sat', 'iris', 'tut', 'econnect', 'f16', 'fintegra'],
                'suite'
            )));
            $visibleName = trim((string) $this->ask('Nombre visible en español (ej. Tipos Email)', $moduleNamePlural));
        }

        $indexUrl = trim((string) $this->ask('URL del índice en español (ej. /tipos-email)', '/'.$kebabPlural));
        $indexUrl = '/'.ltrim($indexUrl, '/');

        // Contexto compartido por todos los builders.
        $this->ctx = [
            'ns' => $this->moduleNs,
            'shared' => $this->sharedNs,
            'basePath' => $basePath,
            'plural' => $moduleNamePlural,        // EmailTypes
            'singular' => $moduleNameSingular,    // EmailType
            'moduleCode' => $moduleCode,          // emailTypes
            'singularCamel' => $singularCamel,    // emailType
            'kebab' => $kebabPlural,              // email-types
            'table' => $tableName,                // shd_email_types
            'connection' => $this->connection,    // tenant
            'hasCode' => $hasCode,
            'isInternal' => $isInternal,
            'appSlug' => $appSlug,
            'visibleName' => $visibleName,        // Tipos Email
            'indexUrl' => $indexUrl,              // /tipos-email
        ];

        // ===== Cabecera =====
        $this->newLine();
        $this->drawBox("Generando módulo · {$moduleNamePlural}", [
            '<fg=gray>Tabla</>       '."<fg=green;options=bold>{$tableName}</>",
            '<fg=gray>Namespace</>   '."{$this->moduleNs}\\{$moduleNamePlural}",
            '<fg=gray>Código</>      '.($hasCode ? '<fg=green>sí (columna code única)</>' : '<fg=gray>no</>'),
            '<fg=gray>Tipo</>        '.($isInternal ? '<fg=yellow>interno (guard Sodeker)</>' : '<fg=cyan>usuario (Casbin + menú)</>'),
        ], 'green');
        $this->newLine();

        // ===== Decisiones de migración/seeder (Suite o respaldo local) =====
        $suitePath = $this->resolveSuiteBasePath();
        if ($suitePath === null) {
            return Command::FAILURE;
        }

        $migrationProject = null;
        $wantSeeder = false;
        if ($suitePath !== self::LOCAL_TARGET) {
            $migrationProject = $this->chooseProject("{$suitePath}/database/migrations/tenant", 'migraciones');
            $wantSeeder = $this->confirm('¿Desea agregar un seeder para este módulo?', false);
        }

        // ===== Fases de generación (se ejecutan bajo barra de progreso) =====
        $phases = [];

        if ($suitePath === self::LOCAL_TARGET) {
            $phases['Migración (local)'] = function () use ($basePath, $tableName): void {
                $dir = "{$basePath}/Infrastructure/Database/Migrations";
                File::ensureDirectoryExists($dir);
                $this->recordCreated($this->writeMigrationFile($dir, $tableName));
            };
        } else {
            $phases['Migración (Suite)'] = function () use ($suitePath, $migrationProject, $moduleNamePlural, $tableName): void {
                $this->createMigration($suitePath, $migrationProject, $moduleNamePlural, $tableName);
            };
        }

        $phases['Value Object (Status)'] = fn () => $this->createStatusValueObject();
        $phases['Modelo Eloquent'] = fn () => $this->createModel();
        $phases['Entidad de dominio'] = fn () => $this->createDomainEntity();
        $phases['DTOs'] = fn () => $this->createDtos();
        $phases['Repositorio (interfaz)'] = fn () => $this->createRepositoryInterface();
        $phases['Commands'] = fn () => $this->createCommands();
        $phases['Handlers'] = fn () => $this->createHandlers();
        $phases['Repositorio (Eloquent)'] = fn () => $this->createRepositoryImplementation();
        $phases['Excepciones'] = fn () => $this->createNotFoundException();
        $phases['Form Requests'] = fn () => $this->createRequests();
        $phases['Controlador'] = fn () => $this->createController();
        $phases['Vistas Vue'] = fn () => $this->createVueFront();
        $phases['Rutas'] = fn () => $this->createRoutesFile();
        $phases['DataBridge (contratos)'] = fn () => $this->createDataBridge();
        $phases['ServiceProvider'] = fn () => $this->createServiceProvider();
        $phases['Registro en config/app.php'] = fn () => $this->registerProviderInConfig($moduleNamePlural);

        if ($suitePath !== self::LOCAL_TARGET) {
            $phases['Test (List Service)'] = function () use ($suitePath): void {
                $this->createListServiceTest($suitePath);
            };
        }

        if ($wantSeeder) {
            $phases['Seeder (Suite)'] = function () use ($suitePath, $migrationProject, $moduleNamePlural, $tableName): void {
                $this->createSeeder($suitePath, $migrationProject, $moduleNamePlural, $tableName);
            };
        }

        $this->runPhases($phases);

        $this->components->task('Limpiando cachés', function (): bool {
            $this->callSilently('optimize:clear');

            return true;
        });

        // ===== Resumen =====
        $migrationTarget = $suitePath === self::LOCAL_TARGET
            ? 'local (dentro del módulo)'
            : "Suite · {$migrationProject}";

        $this->newLine();
        $this->components->info("Módulo <options=bold>{$moduleNamePlural}</> creado correctamente");
        $this->components->twoColumnDetail('<fg=gray>Archivos generados</>', '<fg=green;options=bold>'.count($this->created).'</>');
        $this->components->twoColumnDetail('<fg=gray>Migración</>', $migrationTarget);
        $this->components->twoColumnDetail('<fg=gray>Backend</>', "app/Modules/{$moduleNamePlural}/");
        $this->components->twoColumnDetail('<fg=gray>Frontend</>', "resources/js/{$this->pagesPath}/{$moduleNamePlural}/");
        $this->newLine();
        $this->components->bulletList([
            "Ejecuta <fg=cyan>php artisan migrate</> para crear la tabla <fg=green>{$tableName}</>",
            "Carga la vista en <fg=cyan>{$indexUrl}</>",
        ]);
        $this->newLine();

        // ===== Pasos manuales (permisos/menú) =====
        $this->printManualSteps();

        return Command::SUCCESS;
    }

    /**
     * Registra un archivo generado (para el conteo del resumen). Sustituye a la
     * impresión inmediata de cada "Created:" para no romper la barra de progreso.
     */
    private function recordCreated(string $file): void
    {
        $this->created[] = $file;
    }

    /**
     * Ejecuta las fases de generación mostrando una barra de progreso con el
     * nombre de la fase actual.
     *
     * @param  array<string, callable>  $phases
     */
    private function runPhases(array $phases): void
    {
        $bar = $this->output->createProgressBar(count($phases));
        $bar->setFormat('  %bar%  %percent:3s%%  <fg=gray>%message%</>');
        $bar->setBarCharacter('<fg=green>█</>');
        $bar->setProgressCharacter('<fg=green>█</>');
        $bar->setEmptyBarCharacter('░');
        $bar->setMessage('Iniciando…');
        $bar->start();

        foreach ($phases as $label => $task) {
            $bar->setMessage((string) $label);
            $bar->display();
            $task();
            $bar->advance();
            usleep(30000);
        }

        $bar->setMessage('Completado');
        $bar->finish();
        $this->newLine(2);
    }

    /**
     * Imprime los pasos manuales para dejar el módulo operativo en la capa de
     * permisos/menú de Suite. Solo aplica a módulos de usuario (los internos no
     * usan Casbin ni menú lateral).
     */
    private function printManualSteps(): void
    {
        ['plural' => $plural, 'moduleCode' => $moduleCode, 'isInternal' => $isInternal,
         'appSlug' => $appSlug, 'visibleName' => $visibleName, 'indexUrl' => $indexUrl] = $this->ctx;

        if ($isInternal) {
            $this->components->warn('Módulo interno: sin menú lateral ni permisos Casbin.');
            $this->line('  El acceso queda restringido en el controller a la cuenta Sodeker con rol Developer.');
            $this->line('  Se ingresa únicamente por URL: <fg=cyan>'.$indexUrl.'</>');
            $this->newLine();

            return;
        }

        $appId = self::APP_IDS[$appSlug] ?? 'N /* app_id de '.$appSlug.', ajústalo */';
        $isSuiteApp = $appSlug === 'suite';

        $this->drawBox('PASOS MANUALES · permisos + menú', [
            '<fg=gray>Edita estos 3 archivos en Suite para dejar</>',
            '<fg=gray>el módulo</> <options=bold>'.$moduleCode.'</> <fg=gray>operativo.</>',
        ], 'yellow');
        $this->newLine();

        // 1) Registro del módulo (ModulesTenantAppsSeeder, dentro de CasbinSeeders).
        $this->line("  <fg=yellow;options=bold>1)</> <options=underscore>database/seeders/Landlord/CasbinSeeders/ModulesTenantAppsSeeder.php</>");
        $this->line("     <fg=gray>Agrega la entrada del módulo en el arreglo \$modules:</>");
        $this->line("     <fg=green>['uuid' => Str::ulid(), 'app_id' => {$appId}, 'code' => '{$moduleCode}', 'name' => '{$visibleName}', 'status' => 1, 'apps_required' => null],</>");
        $this->newLine();

        // 2) Permisos Casbin.
        $this->line("  <fg=yellow;options=bold>2)</> <options=underscore>database/seeders/Landlord/CasbinSeeders/PermissionsTableSeeder.php</>");
        $this->line("     <fg=gray>Dentro del arreglo, bajo el slug de la app '{$appSlug}':</>");
        $this->line("     <fg=green>'{$moduleCode}' => ['view', 'edit', 'create', 'delete'],</>");
        $this->newLine();

        // 3) Ruta navegable: SuiteConfigModeResolver para app suite; CHILD_APP_MODULE_ROUTES para apps hijas.
        if ($isSuiteApp) {
            $this->line("  <fg=yellow;options=bold>3)</> <options=underscore>app/Http/Support/SuiteConfigModeResolver.php</>  <fg=gray>(const ROUTES)</>");
            $this->line("     <fg=green>'{$moduleCode}' => '{$indexUrl}',</>");
        } else {
            $this->line("  <fg=yellow;options=bold>3)</> <options=underscore>app/Http/Middleware/HandleInertiaRequests.php</>  <fg=gray>(const CHILD_APP_MODULE_ROUTES)</>");
            $this->line("     <fg=gray>Bajo la clave '{$appSlug}':</>");
            $this->line("     <fg=green>'{$moduleCode}' => '{$indexUrl}',</>");
        }
        $this->newLine();
    }

    /**
     * Banner decorativo de apertura: logo Sódeker (varita + portal ø con
     * destellos) en púrpura de marca, con el título "MODULE GENERATOR". El
     * púrpura se emite como hex; Symfony lo degrada al color ANSI más cercano
     * en terminales sin soporte truecolor.
     */
    private function renderBanner(): void
    {
        $art = [
            '                             ░█░',
            '                            ▒███░',
            '                    ░░   ░██  ▒',
            '                    ▒▒ ░▒█▒░',
            '                      ░██▒ ░██░',
            '                     ▒██░   ░░',
            '                    ▒██░',
            '                  ░███░',
            '                 ░██▒',
            '      ░▒▒▒▒▒▒▒▒▒▒███░',
            '    ░█████████████████',
            '    ████▒░░░░▒███▒████░',
            '    ████    ▒███░ ░███▒',
            '    ███▒   ▒██▒░   ███▒',
            '    ████░░███▒    ▒███▒',
            '    ▒████████▒▒▒▒█████░',
            '     ▒███████████████░',
            '      ░▒▒█▒▒▒▒▒▒▒░░░',
            '     ▒███▒',
            '   ░████░',
            '   ████░',
            ' ▒████░',
            '▒████░',
        ];

        $purple = '#7C3AED';

        $this->newLine();
        foreach ($art as $line) {
            $this->line("  <fg={$purple}>{$line}</>");
        }

        $this->newLine();
        $this->line('     <options=bold>M O D U L E   G E N E R A T O R</>');
        $this->line('     <fg=gray>Sódeker · Arquitectura DDD · maestras de Suite</>');
        $this->newLine();
    }

    /**
     * Dibuja una caja decorativa con título y líneas de contenido. Las líneas
     * pueden contener tags de color (<fg=...>, <options=...>); su ancho visible
     * se calcula ignorando dichos tags para alinear el borde derecho.
     *
     * @param  list<string>  $lines
     */
    private function drawBox(string $title, array $lines, string $border = 'cyan'): void
    {
        $inner = 60;
        $b = fn (string $s): string => "<fg={$border}>{$s}</>";
        $row = function (string $text) use ($inner, $b): void {
            $cell = ' '.$text;
            $cell .= str_repeat(' ', max(0, $inner - $this->visibleLen($cell)));
            $this->line('  '.$b('│').$cell.$b('│'));
        };

        $this->line('  '.$b('╭'.str_repeat('─', $inner).'╮'));
        if ($title !== '') {
            $row('<options=bold>'.$title.'</>');
            $this->line('  '.$b('├'.str_repeat('─', $inner).'┤'));
        }
        foreach ($lines as $line) {
            $row($line);
        }
        $this->line('  '.$b('╰'.str_repeat('─', $inner).'╯'));
    }

    /**
     * Longitud visible de un texto con tags de consola (ignora <...>).
     */
    private function visibleLen(string $text): int
    {
        return mb_strlen(preg_replace('/<[^>]+>/', '', $text) ?? $text);
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
        $default = in_array('shared', $projects, true) ? 'shared' : ($options[0] ?? 'otro');

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
        $this->recordCreated("Suite/database/migrations/tenant/{$project}/{$moduleFolder}/{$fileName}");
    }

    /**
     * Escribe el archivo de migración en $dir y devuelve el nombre del archivo.
     * Reutilizado por el flujo Suite y por el respaldo local.
     */
    private function writeMigrationFile(string $dir, string $tableName): string
    {
        File::ensureDirectoryExists($dir);

        $hasCode = (bool) ($this->ctx['hasCode'] ?? true);
        $timestamp = date('Y_m_d_His');
        $fileName = "{$timestamp}_create_table_{$tableName}.php";

        $codeLine = $hasCode
            ? "            \$table->char('code', 10)->unique();\n"
            : '';

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
{$codeLine}            \$table->string('description')->nullable();
            \$table->char('status', 1)->default('1')->comment('1: Activo, 0: Inactivo');
            \$table->char('created_by', 26)->nullable();
            \$table->char('updated_by', 26)->nullable();
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

        $hasCode = (bool) ($this->ctx['hasCode'] ?? true);
        $seederClass = "{$moduleNamePlural}Seeder";
        $namespace = 'Database\\Seeders\\Tenant\\'.Str::studly($project);

        $sampleRow = $hasCode
            ? "            // ['code' => 'EJ', 'description' => 'Ejemplo'],"
            : "            // ['description' => 'Ejemplo'],";
        $matchKey = $hasCode ? 'code' : 'description';
        $codeInsert = $hasCode ? "                    'code' => \$row['code'],\n" : '';

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
{$sampleRow}
        ];

        foreach (\$rows as \$row) {
            \$existing = DB::table('{$tableName}')
                ->where('{$matchKey}', \$row['{$matchKey}'])
                ->first();

            DB::table('{$tableName}')->updateOrInsert(
                ['{$matchKey}' => \$row['{$matchKey}']],
                [
                    'uuid' => \$existing->uuid ?? (string) Str::ulid(),
{$codeInsert}                    'description' => \$row['description'] ?? null,
                    'status' => '1',
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
        $this->recordCreated("Suite/database/seeders/tenant/{$project}/{$seederClass}.php");
    }

    /**
     * Value Object del estado (enum backed string 1/0).
     */
    private function createStatusValueObject(): void
    {
        extract($this->ctx);
        /** @var string $ns @var string $plural @var string $singular @var string $basePath */

        $path = "{$basePath}/Domain/ValueObjects";
        File::ensureDirectoryExists($path);
        $class = "{$singular}Status";

        $content = <<<PHP
<?php

declare(strict_types=1);

namespace {$ns}\\{$plural}\\Domain\\ValueObjects;

enum {$class}: string
{
    case Active = '1';
    case Inactive = '0';

    public static function fromMixed(mixed \$value): self
    {
        if (\$value instanceof self) {
            return \$value;
        }

        if (is_string(\$value)) {
            \$normalized = strtolower(trim(\$value));
            return in_array(\$normalized, ['1'], true)
                ? self::Active
                : self::Inactive;
        }

        if (is_bool(\$value)) {
            return \$value ? self::Active : self::Inactive;
        }

        if (is_int(\$value) || is_float(\$value)) {
            return ((int) \$value) === 1 ? self::Active : self::Inactive;
        }

        return filter_var(\$value, FILTER_VALIDATE_BOOLEAN) ? self::Active : self::Inactive;
    }

    public function description(): string
    {
        return match (\$this) {
            self::Active => 'ACTIVO',
            self::Inactive => 'INACTIVO',
        };
    }

    public function toBool(): bool
    {
        return \$this === self::Active;
    }
}
PHP;

        File::put("{$path}/{$class}.php", $content);
        $this->recordCreated("Domain/ValueObjects/{$class}.php");
    }

    /**
     * Create Eloquent Model from template
     */
    private function createModel(): void
    {
        extract($this->ctx);
        /** @var string $ns @var string $plural @var string $singular @var string $table @var string $basePath @var bool $hasCode */

        $path = "{$basePath}/Infrastructure/Database/Models";
        File::ensureDirectoryExists($path);

        $connectionLine = $connection !== null
            ? "\n    protected \$connection = '{$connection}';"
            : '';
        $codeFillable = $hasCode ? "        'code',\n" : '';

        $content = <<<PHP
<?php

declare(strict_types=1);

namespace {$ns}\\{$plural}\\Infrastructure\\Database\\Models;

use Illuminate\\Database\\Eloquent\\Model;
use Illuminate\\Database\\Eloquent\\SoftDeletes;

class {$singular} extends Model
{
    use SoftDeletes;

    protected \$table = '{$table}';{$connectionLine}
    public \$incrementing = true;
    protected \$keyType = 'int';

    protected \$fillable = [
        'id',
        'uuid',
{$codeFillable}        'description',
        'status',
        'created_by',
        'updated_by',
    ];
}
PHP;

        File::put("{$path}/{$singular}.php", $content);
        $this->recordCreated("Infrastructure/Database/Models/{$singular}.php");
    }

    /**
     * Create Domain Entity from template
     */
    private function createDomainEntity(): void
    {
        extract($this->ctx);
        /** @var string $ns @var string $plural @var string $singular @var string $basePath @var bool $hasCode */

        $path = "{$basePath}/Domain/Entities";
        File::ensureDirectoryExists($path);
        $statusVo = "{$singular}Status";

        // Fragmentos condicionales para "code".
        $codeCtorProp = $hasCode ? "        private string \$code,\n" : '';
        $codeCreateParam = $hasCode ? "        string \$code,\n" : '';
        $codeCreateAssign = $hasCode ? "            code: \$code,\n" : '';
        $codeUpdateParam = $hasCode ? 'string $code, ' : '';
        $codeUpdateAssign = $hasCode ? "        \$this->code = \$code;\n" : '';
        $codeGetter = $hasCode ? "    public function code(): string { return \$this->code; }\n" : '';

        $content = <<<PHP
<?php

declare(strict_types=1);

namespace {$ns}\\{$plural}\\Domain\\Entities;

use {$ns}\\{$plural}\\Domain\\ValueObjects\\{$statusVo};
use DateTimeImmutable;
use Illuminate\\Support\\Str;

final class {$singular}
{
    public function __construct(
        private ?int \$id,
        private string \$uuid,
{$codeCtorProp}        private ?string \$description,
        private {$statusVo} \$status,
        private string \$createdBy,
        private string \$updatedBy,
        private DateTimeImmutable \$createdAt,
        private ?DateTimeImmutable \$updatedAt,
        private ?DateTimeImmutable \$deletedAt = null,
    ) {}

    public static function create(
        ?int \$id,
{$codeCreateParam}        ?string \$description,
        {$statusVo} \$status,
        string \$createdBy,
        string \$updatedBy,
    ): self {
        return new self(
            id: \$id,
            uuid: (string) Str::ulid(),
{$codeCreateAssign}            description: \$description,
            status: \$status,
            createdBy: \$createdBy,
            updatedBy: \$updatedBy,
            createdAt: new DateTimeImmutable(),
            updatedAt: new DateTimeImmutable(),
        );
    }

    public function update({$codeUpdateParam}?string \$description, {$statusVo} \$status, string \$updatedBy): void
    {
{$codeUpdateAssign}        \$this->description = \$description;
        \$this->status = \$status;
        \$this->updatedBy = \$updatedBy;
        \$this->updatedAt = new DateTimeImmutable();
    }

    public function delete(): void
    {
        \$this->deletedAt = new DateTimeImmutable();
    }

    public function id(): ?int { return \$this->id; }
    public function uuid(): string { return \$this->uuid; }
{$codeGetter}    public function description(): ?string { return \$this->description; }
    public function status(): {$statusVo} { return \$this->status; }
    public function createdBy(): string { return \$this->createdBy; }
    public function updatedBy(): string { return \$this->updatedBy; }
    public function createdAt(): DateTimeImmutable { return \$this->createdAt; }
    public function updatedAt(): ?DateTimeImmutable { return \$this->updatedAt; }
    public function deletedAt(): ?DateTimeImmutable { return \$this->deletedAt; }
}
PHP;

        File::put("{$path}/{$singular}.php", $content);
        $this->recordCreated("Domain/Entities/{$singular}.php");
    }

    /**
     * Create DTOs (DTO, SaveDTO, CollectionDTO)
     */
    private function createDtos(): void
    {
        extract($this->ctx);
        /** @var string $ns @var string $plural @var string $singular @var string $basePath @var bool $hasCode */

        $path = "{$basePath}/Application/DTOs";
        File::ensureDirectoryExists($path);
        $statusVo = "{$singular}Status";

        $codeProp = $hasCode ? "        public string \$code,\n" : '';
        $codeAssign = $hasCode ? "            code: \${$singularCamel}->code(),\n" : '';
        $codeArray = $hasCode ? "            'code' => \$this->code,\n" : '';
        $codeCollArray = $hasCode ? "                    'code' => \$dto->code,\n" : '';

        // ===== DTO =====
        $dto = <<<PHP
<?php

declare(strict_types=1);

namespace {$ns}\\{$plural}\\Application\\DTOs;

use {$ns}\\{$plural}\\Domain\\Entities\\{$singular};
use {$ns}\\{$plural}\\Domain\\ValueObjects\\{$statusVo};

final class {$singular}DTO
{
    public function __construct(
        public ?int \$id,
        public string \$uuid,
{$codeProp}        public ?string \$description,
        public {$statusVo} \$status,
    ) {}

    public static function fromDomain({$singular} \${$singularCamel}): self
    {
        return new self(
            id: \${$singularCamel}->id(),
            uuid: \${$singularCamel}->uuid(),
{$codeAssign}            description: \${$singularCamel}->description(),
            status: \${$singularCamel}->status(),
        );
    }

    public function toArray(): array
    {
        return [
            'id' => \$this->id,
            'uuid' => \$this->uuid,
{$codeArray}            'description' => \$this->description,
            'status' => \$this->status->value,
        ];
    }
}
PHP;
        File::put("{$path}/{$singular}DTO.php", $dto);
        $this->recordCreated("Application/DTOs/{$singular}DTO.php");

        // ===== SaveDTO =====
        $save = <<<PHP
<?php

declare(strict_types=1);

namespace {$ns}\\{$plural}\\Application\\DTOs;

use {$ns}\\{$plural}\\Domain\\Entities\\{$singular};
use {$ns}\\{$plural}\\Domain\\ValueObjects\\{$statusVo};

final class Save{$singular}DTO
{
    public function __construct(
        public ?int \$id,
        public string \$uuid,
{$codeProp}        public ?string \$description,
        public {$statusVo} \$status,
        public string \$createdBy,
        public string \$updatedBy,
    ) {}

    public static function fromDomain({$singular} \${$singularCamel}): self
    {
        return new self(
            id: \${$singularCamel}->id(),
            uuid: \${$singularCamel}->uuid(),
{$codeAssign}            description: \${$singularCamel}->description(),
            status: \${$singularCamel}->status(),
            createdBy: \${$singularCamel}->createdBy(),
            updatedBy: \${$singularCamel}->updatedBy(),
        );
    }

    public function toArray(): array
    {
        return [
            'id' => \$this->id,
            'uuid' => \$this->uuid,
{$codeArray}            'description' => \$this->description,
            'status' => \$this->status->value,
            'createdBy' => \$this->createdBy,
            'updatedBy' => \$this->updatedBy,
        ];
    }
}
PHP;
        File::put("{$path}/Save{$singular}DTO.php", $save);
        $this->recordCreated("Application/DTOs/Save{$singular}DTO.php");

        // ===== CollectionDTO =====
        $collection = <<<PHP
<?php

declare(strict_types=1);

namespace {$ns}\\{$plural}\\Application\\DTOs;

use {$ns}\\{$plural}\\Domain\\Entities\\{$singular};

final class {$singular}CollectionDTO
{
    public function __construct(
        public array \$items,
        public int \$total,
        public int \$page,
        public int \$perPage,
    ) {}

    public static function fromDomain(array \$entities, int \$total, int \$page = 1, int \$perPage = 15): self
    {
        \$items = array_map(
            fn ({$singular} \${$singularCamel}) => {$singular}DTO::fromDomain(\${$singularCamel}),
            \$entities
        );

        return new self(
            items: \$items,
            total: \$total,
            page: \$page,
            perPage: \$perPage,
        );
    }

    public function toArray(): array
    {
        return [
            'data' => array_map(
                fn ({$singular}DTO \$dto) => [
                    'id' => \$dto->id,
                    'uuid' => \$dto->uuid,
{$codeCollArray}                    'description' => \$dto->description,
                    'status' => \$dto->status->value,
                ],
                \$this->items
            ),
            'meta' => [
                'total' => \$this->total,
                'page' => \$this->page,
                'per_page' => \$this->perPage,
                'last_page' => (int) ceil(\$this->total / max(1, \$this->perPage)),
            ],
        ];
    }
}
PHP;
        File::put("{$path}/{$singular}CollectionDTO.php", $collection);
        $this->recordCreated("Application/DTOs/{$singular}CollectionDTO.php");
    }

    /**
     * Create Repository Interface
     */
    private function createRepositoryInterface(): void
    {
        extract($this->ctx);
        /** @var string $ns @var string $plural @var string $singular @var string $basePath @var bool $hasCode */

        $path = "{$basePath}/Domain/Repositories";
        File::ensureDirectoryExists($path);
        $interfaceName = "{$singular}RepositoryInterface";
        $entityAlias = "{$singular}Entity";

        $codeExists = $hasCode
            ? "\n    public function codeExists(string \$code, ?string \$excludeUuid = null): bool;\n"
            : '';

        $content = <<<PHP
<?php

declare(strict_types=1);

namespace {$ns}\\{$plural}\\Domain\\Repositories;

use {$ns}\\{$plural}\\Domain\\Entities\\{$singular} as {$entityAlias};

interface {$interfaceName}
{
    public function findByUuid(string \$uuid): ?{$entityAlias};

    public function findAll(): array;

    public function list(): array;

    public function save({$entityAlias} \${$singularCamel}): {$entityAlias};
{$codeExists}
    public function update({$entityAlias} \${$singularCamel}): void;

    public function delete(string \$uuid): void;
}
PHP;

        File::put("{$path}/{$interfaceName}.php", $content);
        $this->recordCreated("Domain/Repositories/{$interfaceName}.php");
    }

    /**
     * Create Commands (Create, Update, Delete) from templates
     */
    private function createCommands(): void
    {
        extract($this->ctx);
        /** @var string $ns @var string $plural @var string $singular @var string $basePath @var bool $hasCode */

        $path = "{$basePath}/Application/Commands";
        File::ensureDirectoryExists($path);

        $codeCreate = $hasCode ? "        public string \$code,\n" : '';
        $codeUpdate = $hasCode ? "        public string \$code,\n" : '';

        // Create
        $create = <<<PHP
<?php

declare(strict_types=1);

namespace {$ns}\\{$plural}\\Application\\Commands;

final class Create{$singular}Command
{
    public function __construct(
{$codeCreate}        public ?string \$description,
        public bool \$status,
        public string \$createdBy,
        public string \$updatedBy,
    ) {}
}
PHP;
        File::put("{$path}/Create{$singular}Command.php", $create);
        $this->recordCreated("Application/Commands/Create{$singular}Command.php");

        // Update
        $update = <<<PHP
<?php

declare(strict_types=1);

namespace {$ns}\\{$plural}\\Application\\Commands;

final class Update{$singular}Command
{
    public function __construct(
        public string \$uuid,
{$codeUpdate}        public ?string \$description,
        public ?string \$status,
        public ?string \$actorId,
    ) {}
}
PHP;
        File::put("{$path}/Update{$singular}Command.php", $update);
        $this->recordCreated("Application/Commands/Update{$singular}Command.php");

        // Delete
        $delete = <<<PHP
<?php

declare(strict_types=1);

namespace {$ns}\\{$plural}\\Application\\Commands;

final class Delete{$singular}Command
{
    public function __construct(
        public string \$uuid,
    ) {}
}
PHP;
        File::put("{$path}/Delete{$singular}Command.php", $delete);
        $this->recordCreated("Application/Commands/Delete{$singular}Command.php");
    }

    /**
     * Create Handlers (Create, List, Update, Get, Delete, [ValidateCode])
     */
    private function createHandlers(): void
    {
        extract($this->ctx);
        /** @var string $ns @var string $plural @var string $singular @var string $basePath @var bool $hasCode */

        $path = "{$basePath}/Application/Handlers";
        File::ensureDirectoryExists($path);
        $statusVo = "{$singular}Status";
        $repoInterface = "{$singular}RepositoryInterface";
        $codeCreateAssign = $hasCode ? "            code: \$c->code,\n" : '';
        $codeUpdateAssign = $hasCode ? "            code: \$c->code,\n" : '';

        // Create
        $create = <<<PHP
<?php

declare(strict_types=1);

namespace {$ns}\\{$plural}\\Application\\Handlers;

use {$ns}\\{$plural}\\Application\\Commands\\Create{$singular}Command;
use {$ns}\\{$plural}\\Application\\DTOs\\Save{$singular}DTO;
use {$ns}\\{$plural}\\Domain\\Repositories\\{$repoInterface};
use {$ns}\\{$plural}\\Domain\\ValueObjects\\{$statusVo};
use {$ns}\\{$plural}\\Domain\\Entities\\{$singular};

final class Create{$singular}Handler
{
    public function __construct(
        private {$repoInterface} \$repo,
    ) {}

    public function handle(Create{$singular}Command \$c): Save{$singular}DTO
    {
        \${$singularCamel} = {$singular}::create(
            id: null,
{$codeCreateAssign}            description: \$c->description,
            status: {$statusVo}::fromMixed(\$c->status),
            createdBy: \$c->createdBy,
            updatedBy: \$c->updatedBy,
        );
        \$saved = \$this->repo->save(\${$singularCamel});

        return Save{$singular}DTO::fromDomain(\$saved);
    }
}
PHP;
        File::put("{$path}/Create{$singular}Handler.php", $create);
        $this->recordCreated("Application/Handlers/Create{$singular}Handler.php");

        // List
        $list = <<<PHP
<?php

declare(strict_types=1);

namespace {$ns}\\{$plural}\\Application\\Handlers;

use {$ns}\\{$plural}\\Application\\DTOs\\{$singular}CollectionDTO;
use {$ns}\\{$plural}\\Domain\\Repositories\\{$repoInterface};

final class List{$plural}Handler
{
    public function __construct(private {$repoInterface} \$repo) {}

    public function handle(array \$filters, int \$page, int \$perPage): {$singular}CollectionDTO
    {
        \$result = \$this->repo->list();

        return {$singular}CollectionDTO::fromDomain(
            entities: \$result['data'],
            total: \$result['total'],
            page: \$page,
            perPage: \$perPage,
        );
    }
}
PHP;
        File::put("{$path}/List{$plural}Handler.php", $list);
        $this->recordCreated("Application/Handlers/List{$plural}Handler.php");

        // Update
        $update = <<<PHP
<?php

declare(strict_types=1);

namespace {$ns}\\{$plural}\\Application\\Handlers;

use {$ns}\\{$plural}\\Application\\Commands\\Update{$singular}Command;
use {$ns}\\{$plural}\\Application\\DTOs\\{$singular}DTO;
use {$ns}\\{$plural}\\Domain\\Repositories\\{$repoInterface};
use {$ns}\\{$plural}\\Domain\\Exceptions\\{$singular}NotFoundException;
use {$ns}\\{$plural}\\Domain\\ValueObjects\\{$statusVo};

final class Update{$singular}Handler
{
    public function __construct(private {$repoInterface} \$repo) {}

    public function handle(Update{$singular}Command \$c): {$singular}DTO
    {
        \${$singularCamel} = \$this->repo->findByUuid(\$c->uuid);
        if (! \${$singularCamel}) {
            throw {$singular}NotFoundException::withUuid(\$c->uuid);
        }

        \${$singularCamel}->update(
{$codeUpdateAssign}            description: \$c->description,
            status: {$statusVo}::from(\$c->status),
            updatedBy: \$c->actorId,
        );

        \$this->repo->update(\${$singularCamel});

        return {$singular}DTO::fromDomain(\${$singularCamel});
    }
}
PHP;
        File::put("{$path}/Update{$singular}Handler.php", $update);
        $this->recordCreated("Application/Handlers/Update{$singular}Handler.php");

        // Get by UUID
        $get = <<<PHP
<?php

declare(strict_types=1);

namespace {$ns}\\{$plural}\\Application\\Handlers;

use {$ns}\\{$plural}\\Application\\DTOs\\{$singular}DTO;
use {$ns}\\{$plural}\\Domain\\Repositories\\{$repoInterface};
use {$ns}\\{$plural}\\Domain\\Exceptions\\{$singular}NotFoundException;

final class Get{$singular}ByUuidHandler
{
    public function __construct(
        private {$repoInterface} \$repo,
    ) {}

    public function handle(string \$uuid): {$singular}DTO
    {
        \$entity = \$this->repo->findByUuid(\$uuid);

        if (\$entity === null) {
            throw {$singular}NotFoundException::withUuid(\$uuid);
        }

        return {$singular}DTO::fromDomain(\$entity);
    }
}
PHP;
        File::put("{$path}/Get{$singular}ByUuidHandler.php", $get);
        $this->recordCreated("Application/Handlers/Get{$singular}ByUuidHandler.php");

        // Delete
        $delete = <<<PHP
<?php

declare(strict_types=1);

namespace {$ns}\\{$plural}\\Application\\Handlers;

use {$ns}\\{$plural}\\Application\\Commands\\Delete{$singular}Command;
use {$ns}\\{$plural}\\Domain\\Repositories\\{$repoInterface};

final class Delete{$singular}Handler
{
    public function __construct(private {$repoInterface} \$repo) {}

    public function handle(Delete{$singular}Command \$c): void
    {
        \$this->repo->delete(\$c->uuid);
    }
}
PHP;
        File::put("{$path}/Delete{$singular}Handler.php", $delete);
        $this->recordCreated("Application/Handlers/Delete{$singular}Handler.php");

        // ValidateCode (solo si hay code)
        if ($hasCode) {
            $validate = <<<PHP
<?php

declare(strict_types=1);

namespace {$ns}\\{$plural}\\Application\\Handlers;

use {$ns}\\{$plural}\\Domain\\Repositories\\{$repoInterface};

final class Validate{$singular}CodeHandler
{
    public function __construct(private {$repoInterface} \$repo) {}

    public function handle(string \$code, ?string \$excludeUuid = null): bool
    {
        return \$this->repo->codeExists(\$code, \$excludeUuid);
    }
}
PHP;
            File::put("{$path}/Validate{$singular}CodeHandler.php", $validate);
            $this->recordCreated("Application/Handlers/Validate{$singular}CodeHandler.php");
        }
    }

    /**
     * Create Repository Implementation
     */
    private function createRepositoryImplementation(): void
    {
        extract($this->ctx);
        /** @var string $ns @var string $plural @var string $singular @var string $basePath @var bool $hasCode */

        $path = "{$basePath}/Infrastructure/Database/Repositories";
        File::ensureDirectoryExists($path);
        $repositoryName = "Eloquent{$singular}Repository";
        $interfaceName = "{$singular}RepositoryInterface";
        $entityAlias = "{$singular}Entity";
        $statusVo = "{$singular}Status";

        $codeSave = $hasCode ? "            \$model->code = \${$singularCamel}->code();\n" : '';
        $codeUpdate = $hasCode ? "            \$m->code = \${$singularCamel}->code();\n" : '';
        $codeToDomain = $hasCode ? "            code: \$m->code,\n" : '';
        $searchColumn = $hasCode ? 'code' : 'description';

        $codeExistsMethod = $hasCode ? <<<PHP

    public function codeExists(string \$code, ?string \$excludeUuid = null): bool
    {
        \$normalized = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', trim(\$code)));
        \$query = {$singular}::query()
            ->whereRaw('TRIM(code) = ?', [\$normalized])
            ->whereNull('deleted_at');
        if (! empty(\$excludeUuid)) {
            \$query->where('uuid', '!=', \$excludeUuid);
        }

        return \$query->exists();
    }

PHP : "\n";

        $content = <<<PHP
<?php

declare(strict_types=1);

namespace {$ns}\\{$plural}\\Infrastructure\\Database\\Repositories;

use Illuminate\\Support\\Facades\\DB;

use {$ns}\\{$plural}\\Domain\\Repositories\\{$interfaceName};
use {$ns}\\{$plural}\\Domain\\Entities\\{$singular} as {$entityAlias};
use {$ns}\\{$plural}\\Domain\\ValueObjects\\{$statusVo};
use {$ns}\\{$plural}\\Infrastructure\\Database\\Models\\{$singular};

class {$repositoryName} implements {$interfaceName}
{
    public function findByUuid(string \$uuid): ?{$entityAlias}
    {
        \$model = {$singular}::where('uuid', \$uuid)->first();

        if (! \$model) {
            return null;
        }

        return \$this->toDomain(\$model);
    }

    public function findAll(): array
    {
        return {$singular}::all()
            ->map(fn (\$model) => \$this->toDomain(\$model))
            ->toArray();
    }

    public function list(): array
    {
        \$items = {$singular}::all()->map(fn (\$m) => \$this->toDomain(\$m))->all();
        \$total = {$singular}::count();

        return [
            'data' => \$items,
            'total' => \$total,
        ];
    }

    public function save({$entityAlias} \${$singularCamel}): {$entityAlias}
    {
        return DB::transaction(function () use (\${$singularCamel}) {
            \$model = new {$singular}();
            \$model->uuid = \${$singularCamel}->uuid();
{$codeSave}            \$model->description = \${$singularCamel}->description();
            \$model->status = \${$singularCamel}->status()->value;
            \$model->created_by = \${$singularCamel}->createdBy();
            \$model->updated_by = \${$singularCamel}->updatedBy();
            \$model->created_at = now();
            \$model->updated_at = now();
            \$model->save();
            \$model->refresh();

            return \$this->toDomain(\$model);
        });
    }
{$codeExistsMethod}
    public function update({$entityAlias} \${$singularCamel}): void
    {
        \$m = {$singular}::where('uuid', \${$singularCamel}->uuid())->first();
        if (\$m) {
{$codeUpdate}            \$m->description = \${$singularCamel}->description();
            \$m->status = \${$singularCamel}->status()->value;
            \$m->updated_by = \${$singularCamel}->updatedBy();
            \$m->updated_at = now();
            \$m->save();
        }
    }

    public function delete(string \$uuid): void
    {
        {$singular}::where('uuid', \$uuid)->delete();
    }

    private function toDomain({$singular} \$m): {$entityAlias}
    {
        return new {$entityAlias}(
            id: (int) \$m->id,
            uuid: \$m->uuid,
{$codeToDomain}            description: \$m->description,
            status: {$statusVo}::fromMixed(\$m->status),
            createdBy: (string) (\$m->created_by ?? ''),
            updatedBy: (string) (\$m->updated_by ?? ''),
            createdAt: new \\DateTimeImmutable(\$m->created_at?->toAtomString() ?? 'now'),
            updatedAt: new \\DateTimeImmutable(\$m->updated_at?->toAtomString() ?? 'now'),
            deletedAt: \$m->deleted_at ? new \\DateTimeImmutable(\$m->deleted_at->toAtomString()) : null,
        );
    }
}
PHP;

        File::put("{$path}/{$repositoryName}.php", $content);
        $this->recordCreated("Infrastructure/Database/Repositories/{$repositoryName}.php");
    }

    /**
     * Create NotFoundException
     */
    private function createNotFoundException(): void
    {
        extract($this->ctx);
        /** @var string $ns @var string $plural @var string $singular @var string $basePath */

        $path = "{$basePath}/Domain/Exceptions";
        File::ensureDirectoryExists($path);
        $exceptionName = "{$singular}NotFoundException";

        $content = <<<PHP
<?php

declare(strict_types=1);

namespace {$ns}\\{$plural}\\Domain\\Exceptions;

use App\\Shared\\Domain\\Exceptions\\ResourceNotFoundException;

final class {$exceptionName} extends ResourceNotFoundException
{
    public static function withUuid(string \$uuid): self
    {
        return new self("{$singular} con UUID {\$uuid} no encontrado");
    }
}
PHP;

        File::put("{$path}/{$exceptionName}.php", $content);
        $this->recordCreated("Domain/Exceptions/{$exceptionName}.php");
    }

    /**
     * Create Form Requests (Create, Update, Filter)
     */
    private function createRequests(): void
    {
        extract($this->ctx);
        /** @var string $ns @var string $plural @var string $singular @var string $table @var string $basePath @var bool $hasCode */

        $path = "{$basePath}/Infrastructure/Http/Requests";
        File::ensureDirectoryExists($path);
        $conn = $connection !== null && $connection !== '' ? $connection.'.' : '';

        // ---- Fragmentos condicionales de code ----
        $useRule = $hasCode ? "use Illuminate\\Validation\\Rule;\n" : '';
        $prepareCode = $hasCode
            ? "        \$code = (string) \$this->input('code', '');\n        \$code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', \$code));\n        \$this->merge(['code' => \$code, 'description' => \$description]);"
            : "        \$this->merge(['description' => \$description]);";

        // Create rules
        $createCodeRule = $hasCode
            ? "            'code' => [\n                'required',\n                'string',\n                'max:10',\n                'regex:/^[A-Z0-9]+\$/',\n                Rule::unique('{$conn}{$table}', 'code')->whereNull('deleted_at'),\n            ],\n"
            : '';
        $updateCodeRule = $hasCode
            ? "            'code' => [\n                'required',\n                'string',\n                'max:10',\n                'regex:/^[A-Z0-9]+\$/',\n                Rule::unique('{$conn}{$table}', 'code')\n                    ->ignore(\$this->route('uuid'), 'uuid')\n                    ->whereNull('deleted_at'),\n            ],\n"
            : '';
        $createDescRule = "            'description' => [\n                'required',\n                'string',\n                'max:191',\n                Rule::unique('{$conn}{$table}', 'description')->whereNull('deleted_at'),\n            ],";
        $updateDescRule = "            'description' => [\n                'required',\n                'string',\n                'max:191',\n                Rule::unique('{$conn}{$table}', 'description')\n                    ->ignore(\$this->route('uuid'), 'uuid')\n                    ->whereNull('deleted_at'),\n            ],";
        // Sin code no importamos Rule salvo por description unique -> siempre lo necesitamos.
        $useRule = "use Illuminate\\Validation\\Rule;\n";

        $codeMessages = $hasCode
            ? "            'code.required' => 'El campo código es requerido',\n            'code.max' => 'El campo código debe tener máximo 10 caracteres',\n            'code.regex' => 'El código solo puede contener letras y números, sin espacios ni símbolos',\n            'code.unique' => 'El código que intenta guardar ya existe',\n"
            : '';

        // ===== Create =====
        $create = <<<PHP
<?php

declare(strict_types=1);

namespace {$ns}\\{$plural}\\Infrastructure\\Http\\Requests;

use Illuminate\\Foundation\\Http\\FormRequest;
{$useRule}
class Create{$singular}Request extends FormRequest
{
    public function authorize(): bool { return true; }

    protected function prepareForValidation(): void
    {
        \$description = trim((string) \$this->input('description', ''));
{$prepareCode}
    }

    public function rules(): array
    {
        return [
{$createCodeRule}{$createDescRule}
            'status' => 'required|boolean',
        ];
    }

    public function messages(): array
    {
        return [
{$codeMessages}            'description.required' => 'El campo descripción es requerido',
            'description.string' => 'El campo descripción debe ser un texto',
            'description.max' => 'El campo descripción debe tener máximo 191 caracteres',
            'description.unique' => 'Ya existe un registro con esta descripción',
            'status.required' => 'El campo estado es requerido',
            'status.boolean' => 'El campo estado no tiene un valor válido',
        ];
    }
}
PHP;
        File::put("{$path}/Create{$singular}Request.php", $create);
        $this->recordCreated("Infrastructure/Http/Requests/Create{$singular}Request.php");

        // ===== Update =====
        $update = <<<PHP
<?php

declare(strict_types=1);

namespace {$ns}\\{$plural}\\Infrastructure\\Http\\Requests;

use Illuminate\\Foundation\\Http\\FormRequest;
{$useRule}
class Update{$singular}Request extends FormRequest
{
    public function authorize(): bool { return true; }

    protected function prepareForValidation(): void
    {
        \$description = trim((string) \$this->input('description', ''));
{$prepareCode}
    }

    public function rules(): array
    {
        return [
{$updateCodeRule}{$updateDescRule}
            'status' => ['required', 'in:0,1'],
        ];
    }

    public function messages(): array
    {
        return [
{$codeMessages}            'description.required' => 'El campo descripción es requerido',
            'description.string' => 'El campo descripción debe ser un texto',
            'description.max' => 'El campo descripción debe tener máximo 191 caracteres',
            'description.unique' => 'Ya existe un registro con esta descripción',
            'status.required' => 'El campo estado es requerido',
            'status.in' => 'El campo estado no tiene un valor válido',
        ];
    }
}
PHP;
        File::put("{$path}/Update{$singular}Request.php", $update);
        $this->recordCreated("Infrastructure/Http/Requests/Update{$singular}Request.php");

        // ===== Filter =====
        $filter = <<<PHP
<?php

declare(strict_types=1);

namespace {$ns}\\{$plural}\\Infrastructure\\Http\\Requests;

use Illuminate\\Foundation\\Http\\FormRequest;

class Filter{$plural}Request extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
PHP;
        File::put("{$path}/Filter{$plural}Request.php", $filter);
        $this->recordCreated("Infrastructure/Http/Requests/Filter{$plural}Request.php");
    }

    /**
     * Create Controller (variante usuario/Casbin o interno/guard).
     */
    private function createController(): void
    {
        extract($this->ctx);
        /** @var string $ns @var string $plural @var string $singular @var string $moduleCode @var string $basePath @var bool $hasCode @var bool $isInternal @var string $visibleName */

        $path = "{$basePath}/Infrastructure/Http/Controllers";
        File::ensureDirectoryExists($path);

        $content = $isInternal
            ? $this->buildInternalController()
            : $this->buildUserController();

        File::put("{$path}/{$singular}Controller.php", $content);
        $this->recordCreated("Infrastructure/Http/Controllers/{$singular}Controller.php");
    }

    /**
     * Controller de módulo de usuario: permisos Casbin por acción.
     */
    private function buildUserController(): string
    {
        extract($this->ctx);
        /** @var string $ns @var string $plural @var string $singular @var string $moduleCode @var string $singularCamel @var bool $hasCode @var string $visibleName */

        $visibleLower = mb_strtolower($visibleName);

        $validateHandlerUse = $hasCode ? "\n    Validate{$singular}CodeHandler," : '';
        $validateHandlerCtor = $hasCode ? "        private Validate{$singular}CodeHandler \$validate{$singular}CodeHandler,\n" : '';

        $codeStore = $hasCode ? "            code: \$r->input('code'),\n" : '';
        $codeUpdate = $hasCode ? "            code: \$r->input('code'),\n" : '';

        $validateMethod = $hasCode ? <<<PHP

    public function validateCode()
    {
        \$rawCode = (string) request()->input('code', '');
        \$code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', \$rawCode));

        \$validated = validator(
            ['uuid' => request()->input('uuid'), 'code' => \$code],
            [
                'uuid' => ['nullable', 'string'],
                'code' => ['required', 'string', 'max:10', 'regex:/^[A-Z0-9]+\$/'],
            ],
            [
                'code.regex' => 'El código solo puede contener letras y números, sin espacios ni símbolos.',
            ]
        )->validate();

        \$exists = \$this->validate{$singular}CodeHandler->handle(
            \$validated['code'],
            \$validated['uuid'] ?? null
        );

        return response()->json(['exists' => \$exists]);
    }

PHP : "\n";

        return <<<PHP
<?php

declare(strict_types=1);

namespace {$ns}\\{$plural}\\Infrastructure\\Http\\Controllers;

use App\\Http\\Controllers\\Controller;
use Inertia\\Inertia;
use Illuminate\\Support\\Facades\\Auth;
use Sodeker\\LaravelCasbin\\Domain\\Contracts\\PermissionServiceInterface;

use {$ns}\\{$plural}\\Infrastructure\\Http\\Requests\\{
    Create{$singular}Request,
    Update{$singular}Request,
    Filter{$plural}Request,
};

use {$ns}\\{$plural}\\Application\\Handlers\\{
    List{$plural}Handler,
    Get{$singular}ByUuidHandler,
    Create{$singular}Handler,{$validateHandlerUse}
    Update{$singular}Handler,
    Delete{$singular}Handler,
};

use {$ns}\\{$plural}\\Application\\Commands\\{
    Update{$singular}Command,
    Create{$singular}Command,
    Delete{$singular}Command,
};

class {$singular}Controller extends Controller
{
    public function __construct(
        private PermissionServiceInterface \$permissionService,
        private Get{$singular}ByUuidHandler \$get{$singular}ByUuidHandler,
        private Create{$singular}Handler \$create{$singular}Handler,
{$validateHandlerCtor}        private Update{$singular}Handler \$update{$singular}Handler,
        private Delete{$singular}Handler \$delete{$singular}Handler,
    ) {}

    public function index(Filter{$plural}Request \$r, List{$plural}Handler \$handler)
    {
        \$userId = (int) Auth::id();
        \$tenantId = (int) session('tenant_id');
        \$module = '{$moduleCode}';
        \$permissions = [
            'view' => \$this->permissionService->can(\$userId, \$tenantId, \$module, 'view'),
            'create' => \$this->permissionService->can(\$userId, \$tenantId, \$module, 'create'),
            'edit' => \$this->permissionService->can(\$userId, \$tenantId, \$module, 'edit'),
            'delete' => \$this->permissionService->can(\$userId, \$tenantId, \$module, 'delete'),
        ];

        if (! \$permissions['view']) {
            return Inertia::render('{$plural}/Index', [
                'canViewModule' => false,
                'message' => 'No tienes permisos para acceder al módulo de {$visibleLower}.',
                'permissions' => \$permissions,
                '{$moduleCode}' => ['data' => [], 'meta' => ['total' => 0]],
                'title' => '{$visibleName}',
            ]);
        }

        \$filters = \$r->validated();
        \$page = (int) (\$filters['page'] ?? 1);
        \$perPage = (int) (\$filters['per_page'] ?? 15);

        \$collectionDTO = \$handler->handle(\$filters, \$page, \$perPage);

        return Inertia::render('{$plural}/Index', [
            'canViewModule' => true,
            'permissions' => \$permissions,
            '{$moduleCode}' => \$collectionDTO->toArray(),
            'title' => '{$visibleName}',
        ]);
    }

    public function viewCreate()
    {
        return Inertia::render('{$plural}/Create', []);
    }

    public function viewEdit(string \$uuid)
    {
        \$dto = \$this->get{$singular}ByUuidHandler->handle(\$uuid);

        return Inertia::render('{$plural}/Edit', [
            'uuid' => \$uuid,
            '{$singularCamel}' => \$dto->toArray(),
        ]);
    }

    public function viewShow(string \$uuid)
    {
        \$dto = \$this->get{$singular}ByUuidHandler->handle(\$uuid);

        return Inertia::render('{$plural}/Show', [
            'uuid' => \$uuid,
            '{$singularCamel}' => \$dto->toArray(),
        ]);
    }

    public function store(Create{$singular}Request \$r)
    {
        \$userId = (string) (Auth::id() ?? 1);
        \$cmd = new Create{$singular}Command(
{$codeStore}            description: \$r->input('description'),
            status: \$r->input('status'),
            createdBy: \$userId,
            updatedBy: \$userId,
        );
        \$dto = \$this->create{$singular}Handler->handle(\$cmd);

        return response()->json(['data' => \$dto->toArray()]);
    }
{$validateMethod}
    public function update(string \$uuid, Update{$singular}Request \$r)
    {
        \$userId = (string) (Auth::id() ?? 1);
        \$command = new Update{$singular}Command(
            uuid: \$uuid,
{$codeUpdate}            description: \$r->input('description'),
            status: \$r->input('status'),
            actorId: \$userId,
        );

        \$dto = \$this->update{$singular}Handler->handle(\$command);

        return response()->json(['data' => \$dto->toArray()]);
    }

    public function destroy(string \$uuid)
    {
        \$this->delete{$singular}Handler->handle(new Delete{$singular}Command(\$uuid));

        return response()->noContent();
    }
}
PHP;
    }

    /**
     * Controller de módulo interno: guard de la cuenta Sodeker (Developer),
     * sin Casbin ni menú.
     */
    private function buildInternalController(): string
    {
        extract($this->ctx);
        /** @var string $ns @var string $plural @var string $singular @var string $moduleCode @var string $singularCamel @var bool $hasCode @var string $visibleName */

        $visibleLower = mb_strtolower($visibleName);

        $validateHandlerUse = $hasCode ? "\n    Validate{$singular}CodeHandler," : '';
        $validateHandlerCtor = $hasCode ? "        private Validate{$singular}CodeHandler \$validate{$singular}CodeHandler,\n" : '';

        $codeStore = $hasCode ? "            code: \$r->input('code'),\n" : '';
        $codeUpdate = $hasCode ? "            code: \$r->input('code'),\n" : '';

        $validateMethod = $hasCode ? <<<PHP

    public function validateCode()
    {
        if (! \$this->userCanManage()) {
            return response()->json(['message' => 'No tienes permisos para validar códigos de {$visibleLower}.'], 403);
        }

        \$rawCode = (string) request()->input('code', '');
        \$code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', \$rawCode));

        \$validated = validator(
            ['uuid' => request()->input('uuid'), 'code' => \$code],
            [
                'uuid' => ['nullable', 'string'],
                'code' => ['required', 'string', 'max:10', 'regex:/^[A-Z0-9]+\$/'],
            ],
            [
                'code.regex' => 'El código solo puede contener letras y números, sin espacios ni símbolos.',
            ]
        )->validate();

        \$exists = \$this->validate{$singular}CodeHandler->handle(
            \$validated['code'],
            \$validated['uuid'] ?? null
        );

        return response()->json(['exists' => \$exists]);
    }

PHP : "\n";

        return <<<PHP
<?php

declare(strict_types=1);

namespace {$ns}\\{$plural}\\Infrastructure\\Http\\Controllers;

use App\\Http\\Controllers\\Controller;
use App\\Modules\\Tenants\\Domain\\DevelopTenantMembershipRule;
use Inertia\\Inertia;
use Illuminate\\Support\\Facades\\Auth;

use {$ns}\\{$plural}\\Infrastructure\\Http\\Requests\\{
    Create{$singular}Request,
    Update{$singular}Request,
    Filter{$plural}Request,
};

use {$ns}\\{$plural}\\Application\\Handlers\\{
    List{$plural}Handler,
    Get{$singular}ByUuidHandler,
    Create{$singular}Handler,{$validateHandlerUse}
    Update{$singular}Handler,
    Delete{$singular}Handler,
};

use {$ns}\\{$plural}\\Application\\Commands\\{
    Update{$singular}Command,
    Create{$singular}Command,
    Delete{$singular}Command,
};

class {$singular}Controller extends Controller
{
    public function __construct(
        private Get{$singular}ByUuidHandler \$get{$singular}ByUuidHandler,
        private Create{$singular}Handler \$create{$singular}Handler,
{$validateHandlerCtor}        private Update{$singular}Handler \$update{$singular}Handler,
        private Delete{$singular}Handler \$delete{$singular}Handler,
    ) {}

    /**
     * Módulo interno sin menú: solo la cuenta sembrada Sodeker con rol
     * Developer puede administrar este recurso (mismo guard que Authentications).
     */
    private function userCanManage(): bool
    {
        \$user = Auth::user();
        if (\$user === null) {
            return false;
        }

        \$email = (string) (\$user->email ?? '');
        \$roleCode = (string) (\$user->role?->code ?? '');

        return strcasecmp(\$email, DevelopTenantMembershipRule::ALLOWED_EMAIL) === 0
            && strcasecmp(\$roleCode, DevelopTenantMembershipRule::ALLOWED_ROLE_CODE) === 0;
    }

    public function index(Filter{$plural}Request \$r, List{$plural}Handler \$handler)
    {
        if (! \$this->userCanManage()) {
            return Inertia::render('{$plural}/Index', [
                'canViewModule' => false,
                'message' => 'Esta sección está disponible únicamente para la cuenta Sodeker con rol Developer.',
                'permissions' => ['view' => false, 'create' => false, 'edit' => false, 'delete' => false],
                '{$moduleCode}' => ['data' => [], 'meta' => ['total' => 0]],
                'title' => '{$visibleName}',
            ]);
        }

        \$filters = \$r->validated();
        \$page = (int) (\$filters['page'] ?? 1);
        \$perPage = (int) (\$filters['per_page'] ?? 15);

        \$collectionDTO = \$handler->handle(\$filters, \$page, \$perPage);

        return Inertia::render('{$plural}/Index', [
            'canViewModule' => true,
            'permissions' => ['view' => true, 'create' => true, 'edit' => true, 'delete' => true],
            '{$moduleCode}' => \$collectionDTO->toArray(),
            'title' => '{$visibleName}',
        ]);
    }

    public function viewCreate()
    {
        if (! \$this->userCanManage()) {
            return redirect()->route('{$moduleCode}.index');
        }

        return Inertia::render('{$plural}/Create', []);
    }

    public function viewEdit(string \$uuid)
    {
        if (! \$this->userCanManage()) {
            return redirect()->route('{$moduleCode}.index');
        }

        \$dto = \$this->get{$singular}ByUuidHandler->handle(\$uuid);

        return Inertia::render('{$plural}/Edit', [
            'uuid' => \$uuid,
            '{$singularCamel}' => \$dto->toArray(),
        ]);
    }

    public function viewShow(string \$uuid)
    {
        if (! \$this->userCanManage()) {
            return redirect()->route('{$moduleCode}.index');
        }

        \$dto = \$this->get{$singular}ByUuidHandler->handle(\$uuid);

        return Inertia::render('{$plural}/Show', [
            'uuid' => \$uuid,
            '{$singularCamel}' => \$dto->toArray(),
        ]);
    }

    public function store(Create{$singular}Request \$r)
    {
        if (! \$this->userCanManage()) {
            return response()->json(['message' => 'No tienes permisos para crear {$visibleLower}.'], 403);
        }

        \$userId = (string) (Auth::id() ?? 1);
        \$cmd = new Create{$singular}Command(
{$codeStore}            description: \$r->input('description'),
            status: \$r->input('status'),
            createdBy: \$userId,
            updatedBy: \$userId,
        );
        \$dto = \$this->create{$singular}Handler->handle(\$cmd);

        return response()->json(['data' => \$dto->toArray()]);
    }
{$validateMethod}
    public function update(string \$uuid, Update{$singular}Request \$r)
    {
        if (! \$this->userCanManage()) {
            return response()->json(['message' => 'No tienes permisos para editar {$visibleLower}.'], 403);
        }

        \$userId = (string) (Auth::id() ?? 1);
        \$command = new Update{$singular}Command(
            uuid: \$uuid,
{$codeUpdate}            description: \$r->input('description'),
            status: \$r->input('status'),
            actorId: \$userId,
        );

        \$dto = \$this->update{$singular}Handler->handle(\$command);

        return response()->json(['data' => \$dto->toArray()]);
    }

    public function destroy(string \$uuid)
    {
        if (! \$this->userCanManage()) {
            return response()->json(['message' => 'No tienes permisos para eliminar {$visibleLower}.'], 403);
        }

        \$this->delete{$singular}Handler->handle(new Delete{$singular}Command(\$uuid));

        return response()->noContent();
    }
}
PHP;
    }

    /**
     * Create Routes file (variante usuario/Casbin o interno/guard).
     */
    private function createRoutesFile(): void
    {
        extract($this->ctx);
        /** @var string $plural @var string $singular @var string $moduleCode @var string $kebab @var string $basePath @var bool $hasCode @var bool $isInternal @var string $indexUrl */

        $path = "{$basePath}/Infrastructure/Http/Routes";
        File::ensureDirectoryExists($path);
        $controller = "{$singular}Controller";

        $validateRoute = $hasCode
            ? "\n    Route::post('{$kebab}/validate-code', [{$controller}::class, 'validateCode'])->name('{$moduleCode}.validateCode');\n"
            : '';

        if ($isInternal) {
            $content = <<<PHP
<?php

declare(strict_types=1);

use Illuminate\\Support\\Facades\\Route;
use {$ns}\\{$plural}\\Infrastructure\\Http\\Controllers\\{$controller};

// Módulo interno (sin menú ni permisos Casbin): el acceso se restringe en el
// controller a la cuenta Sodeker con rol Developer, igual que Authentications.
Route::middleware(['web', 'auth:sanctum', config('jetstream.auth_session'), 'verified', 'tenant.selected', 'tenant'])->group(function () {
    Route::get('{$indexUrl}', [{$controller}::class, 'index'])->name('{$moduleCode}.index');
    Route::get('/{$kebab}/{uuid}/show', [{$controller}::class, 'viewShow'])->name('{$moduleCode}.show');

    Route::get('/{$kebab}/create', [{$controller}::class, 'viewCreate'])->name('{$moduleCode}.create');
    Route::post('/{$kebab}-create', [{$controller}::class, 'store'])->name('{$moduleCode}.store');

    Route::get('/{$kebab}/{uuid}/edit', [{$controller}::class, 'viewEdit'])->name('{$moduleCode}.edit');
    Route::put('/{$kebab}/{uuid}', [{$controller}::class, 'update'])->name('{$moduleCode}.update');

    Route::delete('/{$kebab}/{uuid}', [{$controller}::class, 'destroy'])->name('{$moduleCode}.destroy');
{$validateRoute}});
PHP;
        } else {
            $content = <<<PHP
<?php

declare(strict_types=1);

use Illuminate\\Support\\Facades\\Route;
use {$ns}\\{$plural}\\Infrastructure\\Http\\Controllers\\{$controller};

Route::middleware(['web', 'tenant.selected', 'tenant'])->group(function () {
    \$sanctumVerified = ['auth:sanctum', config('jetstream.auth_session'), 'verified'];

    Route::middleware(array_merge(\$sanctumVerified, ['casbin:{$moduleCode},view']))->group(function () {
        Route::get('{$indexUrl}', [{$controller}::class, 'index'])
            ->name('{$moduleCode}.index');
        Route::get('/{$kebab}/{uuid}/show', [{$controller}::class, 'viewShow'])
            ->name('{$moduleCode}.show');
    });

    Route::middleware(['auth', 'casbin:{$moduleCode},create'])->group(function () {
        Route::get('/{$kebab}/create', [{$controller}::class, 'viewCreate'])
            ->name('{$moduleCode}.create');
        Route::post('/{$kebab}-create', [{$controller}::class, 'store'])
            ->name('{$moduleCode}.store');
    });

    Route::middleware(['auth', 'casbin:{$moduleCode},edit'])->group(function () {
        Route::get('/{$kebab}/{uuid}/edit', [{$controller}::class, 'viewEdit'])
            ->name('{$moduleCode}.edit');
        Route::put('/{$kebab}/{uuid}', [{$controller}::class, 'update'])
            ->name('{$moduleCode}.update');
    });

    Route::middleware(['auth', 'casbin:{$moduleCode},delete'])->group(function () {
        Route::delete('/{$kebab}/{uuid}', [{$controller}::class, 'destroy'])
            ->name('{$moduleCode}.destroy');
    });
{$validateRoute}});
PHP;
        }

        File::put("{$path}/web.php", $content);
        $this->recordCreated("Infrastructure/Http/Routes/web.php");
    }

    /**
     * Create Vue front pages (Index, Create, Edit, Show).
     */
    private function createVueFront(): void
    {
        extract($this->ctx);
        /** @var string $plural */

        $pagesPath = resource_path("js/{$this->pagesPath}/{$plural}");
        File::ensureDirectoryExists($pagesPath);
        $this->recordCreated("resources/js/Pages/{$plural}");

        File::put("{$pagesPath}/Index.vue", $this->buildVueIndex());
        $this->recordCreated("resources/js/Pages/{$plural}/Index.vue");

        File::put("{$pagesPath}/Create.vue", $this->buildVueForm(false));
        $this->recordCreated("resources/js/Pages/{$plural}/Create.vue");

        File::put("{$pagesPath}/Edit.vue", $this->buildVueForm(true));
        $this->recordCreated("resources/js/Pages/{$plural}/Edit.vue");

        File::put("{$pagesPath}/Show.vue", $this->buildVueShow());
        $this->recordCreated("resources/js/Pages/{$plural}/Show.vue");
    }

    private function buildVueIndex(): string
    {
        extract($this->ctx);
        /** @var string $plural @var string $moduleCode @var bool $hasCode @var string $visibleName */

        $visibleLower = mb_strtolower($visibleName);

        // Encabezados y búsqueda según haya code.
        if ($hasCode) {
            $headers = "                    { label: 'Código', key: 'code', width: '20%' },\n                    { label: 'Descripción', key: 'description', width: '50%' },\n                    { label: 'Estado', key: 'status', width: '15%' },\n                    { label: 'Acciones', key: 'actions', width: '15%' },";
            $searchFilter = "                item.code?.toLowerCase().includes(query) ||\n                item.description?.toLowerCase().includes(query)";
            $orderBy = 'code';
            $searchPlaceholder = 'Buscar por código o descripción...';
        } else {
            $headers = "                    { label: 'Descripción', key: 'description', width: '70%' },\n                    { label: 'Estado', key: 'status', width: '15%' },\n                    { label: 'Acciones', key: 'actions', width: '15%' },";
            $searchFilter = "                item.description?.toLowerCase().includes(query)";
            $orderBy = 'description';
            $searchPlaceholder = 'Buscar por descripción...';
        }

        $codeColumn = $hasCode
            ? "                                <template #cell-code=\"{ item }\">\n                                    <span class=\"fw-medium\">{{ item.code }}</span>\n                                </template>\n"
            : '';

        return <<<VUE
<script>
    import Layout from "@/Layouts/main.vue";
    import { router, Head } from "@inertiajs/vue3";

    import PageHeader from "@/Components/page-header.vue";
    import DataTable from "@/Components/DataTable.vue";
    import AccessDeniedCard from '@/Components/AccessDeniedCard.vue';

    import { useAlert } from '@/Composables/useSweetAlert.js';
    import { useFetchPetition } from '@/Composables/useFetchPetition.js';

    const { showAlert, showConfirm } = useAlert();
    const { fetchPetition } = useFetchPetition();

    export default {
        name: '{$plural}Index',
        components: {
            Layout,
            PageHeader,
            DataTable,
            AccessDeniedCard,
            Head,
        },
        props: {
            canViewModule: {
                type: Boolean,
                default: true,
            },
            message: {
                type: String,
                default: '',
            },
            permissions: {
                type: Object,
                default: () => ({ view: false, create: false, edit: false, delete: false }),
            },
            {$moduleCode}: {
                type: Object,
                required: true,
                default: () => ({ data: [], meta: { total: 0 } }),
            },
            title: {
                type: String,
                default: '{$visibleName}',
            },
        },
        data() {
            return {
                searchQuery: '',
                isDeleting: false,
                tableHeaders: [
{$headers}
                ],
            };
        },
        computed: {
            filteredItems() {
                const list = this.{$moduleCode}.data || [];
                const query = this.searchQuery.trim().toLowerCase();
                if (!query) {
                    return list;
                }
                return list.filter(item =>
{$searchFilter}
                );
            },
        },
        methods: {
            async deleteItem(item) {
                if (!item?.uuid || this.isDeleting) return;
                const confirmed = await showConfirm(
                    "warning",
                    "¡Alerta!",
                    "¿Está seguro que desea eliminar este registro?",
                    "Sí, eliminar"
                );
                if (!confirmed) return;
                this.isDeleting = true;
                try {
                    const response = await fetchPetition(route("{$moduleCode}.destroy", item.uuid), {
                        method: "DELETE",
                    });
                    if (response.ok) {
                        showAlert("success", "Éxito", "Registro eliminado correctamente", 1500);
                        router.visit(route('{$moduleCode}.index'));
                    } else {
                        const data = response.data || {};
                        const message = data.message ||
                            (data.errors ? Object.values(data.errors).flat().join(" ") : "Error al eliminar el registro");
                        showAlert("error", "Error", message, 3000);
                    }
                } catch (error) {
                    showAlert("error", "Error inesperado", "Ocurrió un error al eliminar el registro", 2000);
                } finally {
                    this.isDeleting = false;
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
    <Head :title="title" />
    <Layout>
        <PageHeader title="{$visibleName}" pageTitle="Gestión" />
        <div v-if="!canViewModule" class="row justify-content-center">
            <div class="col-lg-6">
                <AccessDeniedCard :message="message" />
            </div>
        </div>
        <div v-else class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <div class="row g-4 align-items-center">
                            <div class="col-sm">
                                <h5 class="card-title mb-0">Listado</h5>
                            </div>
                            <div class="col-sm-auto">
                                <div class="d-flex flex-wrap align-items-start gap-2" v-if="permissions.create">
                                    <a :href="route('{$moduleCode}.create')" class="btn btn-success add-btn">
                                        <i class="ri-add-line align-bottom me-1"></i> Nuevo
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-xl-12 mb-3">
                                <div class="search-box">
                                    <input
                                        v-model.trim="searchQuery"
                                        type="text"
                                        class="form-control"
                                        placeholder="{$searchPlaceholder}"
                                    />
                                    <i class="ri-search-line search-icon"></i>
                                </div>
                            </div>
                            <DataTable
                                id="table_{$moduleCode}"
                                :headers="tableHeaders"
                                :items="filteredItems"
                                :page-length="10"
                                order-by="{$orderBy}">
{$codeColumn}                                <template #cell-description="{ item }">
                                    <span>{{ item.description || '' }}</span>
                                </template>
                                <template #cell-status="{ item }">
                                    <span class="badge bg-success-subtle text-success" v-if="String(item.status) === '1'">
                                        Activo
                                    </span>
                                    <span class="badge bg-danger-subtle text-danger" v-else>
                                        Inactivo
                                    </span>
                                </template>
                                <template #cell-actions="{ item }">
                                    <ul class="list-inline hstack gap-2 mb-0">
                                        <li class="list-inline-item" v-if="permissions.view">
                                            <a :href="route('{$moduleCode}.show', item.uuid)" class="text-primary" title="Ver">
                                                <i class="ri-eye-fill fs-16"></i>
                                            </a>
                                        </li>
                                        <li class="list-inline-item" v-if="permissions.edit">
                                            <a :href="route('{$moduleCode}.edit', item.uuid)" class="text-primary" title="Editar">
                                                <i class="ri-pencil-fill fs-16"></i>
                                            </a>
                                        </li>
                                        <li class="list-inline-item" v-if="permissions.delete">
                                            <a @click="deleteItem(item)" class="text-danger" style="cursor: pointer" title="Eliminar">
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
        </div>
    </Layout>
</template>
VUE;
    }

    private function buildVueForm(bool $isEdit): string
    {
        extract($this->ctx);
        /** @var string $plural @var string $moduleCode @var string $singularCamel @var bool $hasCode @var string $visibleName */

        $formVar = 'form'.$plural;
        $visibleSingular = Str::singular($visibleName);

        $action = $isEdit ? 'Editar' : 'Crear';
        $actionVerb = $isEdit ? 'actualizar' : 'crear';
        $actionPast = $isEdit ? 'actualizado' : 'creado';
        $submitIdle = $isEdit ? 'Actualizar' : 'Guardar';
        $submitBusy = $isEdit ? 'Actualizando...' : 'Guardando...';

        // Estado inicial del form.
        $initStatus = $isEdit ? "'1'" : 'true';
        $codeInit = $hasCode ? "                    code: '',\n" : '';
        $formData = "{$codeInit}                    description: '',\n                    status: {$initStatus},";

        // Props (Edit lleva uuid + singular).
        $props = $isEdit
            ? "        props: {\n            uuid: {\n                type: String,\n                required: true,\n            },\n            {$singularCamel}: {\n                type: Object,\n                required: true,\n            },\n        },\n"
            : '';

        // Watch (solo Edit) para hidratar el form.
        $codeWatch = $hasCode ? "                        code: val?.code ?? '',\n" : '';
        $watch = $isEdit
            ? "        watch: {\n            {$singularCamel}: {\n                immediate: true,\n                handler(val) {\n                    this.{$formVar} = {\n{$codeWatch}                        description: val?.description ?? '',\n                        status: val?.status === '1' || val?.status === 1 || val?.status === true ? '1' : '0',\n                    };\n                },\n            },\n        },\n"
            : '';

        // Lógica de code (normalización + validación de unicidad).
        $codeMethods = $hasCode ? <<<JS
            normalizeCode(raw) {
                return String(raw ?? '').replace(/[^A-Za-z0-9]/g, '');
            },
            onCodeInput() {
                const normalized = this.normalizeCode(this.{$formVar}.code);
                if (normalized !== this.{$formVar}.code) {
                    this.{$formVar}.code = normalized;
                }
            },
            async validateCodeUniqueness() {
                const code = this.normalizeCode(this.{$formVar}.code);
                if (code === '') return false;
                const response = await fetchPetition(route('{$moduleCode}.validateCode'), {
                    method: 'POST',
                    body: {
                        code,
                        uuid: ##UUIDARG##,
                    },
                });
                if (!response.ok) return false;
                const exists = Boolean(response.data?.exists);
                this.codeExistsError = exists ? 'El código ya existe' : '';
                return exists;
            },
JS : '';
        $uuidArg = $isEdit ? 'this.uuid' : 'null';
        $codeMethods = str_replace('##UUIDARG##', $uuidArg, $codeMethods);
        if ($codeMethods !== '') {
            $codeMethods .= "\n";
        }

        $codeRequired = $hasCode ? "                if (!this.normalizeCode(f.code)) e.code = true;\n" : '';
        $codeClearError = $hasCode ? "                if (field === 'code') {\n                    this.codeExistsError = '';\n                }\n" : '';
        $codeExistsCheck = $hasCode ? "                    const codeExists = await this.validateCodeUniqueness();\n                    if (codeExists) {\n                        await this.\$nextTick();\n                        return;\n                    }\n\n" : '';
        $codeExistsData = $hasCode ? "                codeExistsError: '',\n" : '';
        $codeBodyNormalize = $hasCode ? "                            code: this.normalizeCode(this.{$formVar}.code),\n" : '';

        // Campo code (markup).
        $codeField = $hasCode ? <<<HTML
                                <div class="col-4">
                                    <label for="code" class="form-label">Código<span class="text-danger ms-1">*</span></label>
                                    <input
                                        v-model="{$formVar}.code"
                                        type="text"
                                        class="form-control"
                                        id="code"
                                        maxlength="10"
                                        placeholder="Ej: COD001"
                                        :class="{ 'field-error': formErrors.code || codeExistsError }"
                                        @input="onCodeInput(); clearFormError('code'); codeExistsError = '';"
                                    >
                                    <div v-if="codeExistsError" class="invalid-feedback d-block">
                                        {{ codeExistsError }}
                                    </div>
                                </div>
                                <div class="col-8">
                                    <label for="description" class="form-label">Descripción<span class="text-danger ms-1">*</span></label>
                                    <input
                                        v-model="{$formVar}.description"
                                        type="text"
                                        class="form-control"
                                        id="description"
                                        maxlength="191"
                                        placeholder="Ingrese descripción"
                                        :class="{ 'field-error': formErrors.description }"
                                        @input="clearFormError('description');"
                                    >
                                </div>
HTML : <<<HTML
                                <div class="col-12">
                                    <label for="description" class="form-label">Descripción<span class="text-danger ms-1">*</span></label>
                                    <input
                                        v-model="{$formVar}.description"
                                        type="text"
                                        class="form-control"
                                        id="description"
                                        maxlength="191"
                                        placeholder="Ingrese descripción"
                                        :class="{ 'field-error': formErrors.description }"
                                        @input="clearFormError('description');"
                                    >
                                </div>
HTML;

        // Switch de estado: Create booleano; Edit con true/false-value string.
        $statusInput = $isEdit
            ? "                                        <input\n                                            id=\"{$moduleCode}Status\"\n                                            :true-value=\"'1'\"\n                                            :false-value=\"'0'\"\n                                            v-model=\"{$formVar}.status\"\n                                            type=\"checkbox\"\n                                            class=\"form-check-input\"\n                                        />"
            : "                                        <input\n                                            v-model=\"{$formVar}.status\"\n                                            type=\"checkbox\"\n                                            class=\"form-check-input\"\n                                            id=\"{$moduleCode}Status\"\n                                        >";

        $nameSuffix = $isEdit ? 'Edit' : 'Create';

        // Endpoint y verbo HTTP del submit.
        $request = $isEdit
            ? "route('{$moduleCode}.update', this.uuid)"
            : "route('{$moduleCode}.store')";
        $method = $isEdit ? 'PUT' : 'POST';

        return <<<VUE
<script>
    import Layout from "@/Layouts/main.vue";
    import { router } from '@inertiajs/vue3';

    import PageHeader from "@/Components/page-header.vue";

    import { useFetchPetition } from "@/Composables/useFetchPetition.js";
    import { useAlert } from "@/Composables/useSweetAlert.js";

    const { showAlert, showConfirm } = useAlert();
    const { fetchPetition } = useFetchPetition();

    export default {
        name: '{$plural}{$nameSuffix}',
        components: {
            Layout,
            PageHeader,
        },
{$props}        data() {
            return {
                {$formVar}: {
{$formData}
                },
                loading: false,
{$codeExistsData}                formErrors: {},
            };
        },
        methods: {
            async confirmCancel() {
                const confirmed = await showConfirm(
                    'warning',
                    '¿Cancelar?',
                    '¿Está seguro que desea cancelar? Se perderán los datos ingresados.',
                    'Sí, cancelar'
                );
                if (confirmed) {
                    router.visit(route('{$moduleCode}.index'));
                }
            },
{$codeMethods}            collectRequiredFieldErrors() {
                const e = {};
                const f = this.{$formVar};
{$codeRequired}                if (!f.description || String(f.description).trim() === '') e.description = true;
                return e;
            },
            clearFormError(field) {
{$codeClearError}                if (this.formErrors[field]) {
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

{$codeExistsCheck}                    const confirmed = await showConfirm(
                        'warning',
                        '¡Alerta!',
                        '¿Está seguro que desea {$actionVerb} este {$visibleSingular}?',
                        'Sí, {$actionVerb}'
                    );
                    if (!confirmed) return;

                    this.loading = true;
                    const response = await fetchPetition({$request}, {
                        method: '{$method}',
                        body: {
                            ...this.{$formVar},
{$codeBodyNormalize}                        },
                    });

                    if (response.ok) {
                        showAlert('success', '¡Éxito!', '{$visibleSingular} {$actionPast} correctamente', 1500);
                        router.visit(route('{$moduleCode}.index'));
                    } else {
                        const data = response.data;
                        const message = data?.message || (data?.errors ? Object.values(data.errors || {}).flat().join(' ') : 'Ocurrió un error al {$actionVerb} el {$visibleSingular}');
                        showAlert('error', 'Error', message, 3000);
                    }
                } catch (error) {
                    showAlert('error', 'Error inesperado', error?.message || 'Ocurrió un error al {$actionVerb} el {$visibleSingular}', 3000);
                } finally {
                    this.loading = false;
                }
            },
        },
{$watch}    };
</script>

<template>
    <Head title="{$action} {$visibleSingular}" />
    <Layout>
        <PageHeader title="{$action} {$visibleSingular}" pageTitle="{$visibleName}" />
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">{$visibleSingular}</h5>
                    </div>
                    <div class="card-body">
                        <form @submit.prevent="submitForm">
                            <div class="row g-3">
{$codeField}
                                <div class="col-12 d-flex align-items-center">
                                    <label class="form-check-label me-3" for="{$moduleCode}Status">Estado</label>
                                    <div class="form-check form-switch form-switch-md" dir="ltr">
{$statusInput}
                                    </div>
                                </div>
                            </div>
                            <div class="mt-4 text-end">
                                <button type="button" class="btn btn-light me-2" @click="confirmCancel">Cancelar</button>
                                <button type="submit" class="btn btn-primary" :disabled="loading">
                                    <span v-if="loading" class="spinner-border spinner-border-sm me-2" role="status"></span>
                                    {{ loading ? "{$submitBusy}" : "{$submitIdle}" }}
                                </button>
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
    }

    private function buildVueShow(): string
    {
        extract($this->ctx);
        /** @var string $plural @var string $moduleCode @var string $singularCamel @var bool $hasCode @var string $visibleName */

        $visibleSingular = Str::singular($visibleName);

        $codeBlock = $hasCode ? <<<HTML
                            <div class="col-md-4 mb-1">
                                <label class="form-label">Código</label>
                                <p class="text-muted">
                                    {{ {$singularCamel}.code || '' }}
                                </p>
                            </div>
                            <div class="col-md-8 mb-1">
                                <label class="form-label">Descripción</label>
                                <p class="text-muted">
                                    {{ {$singularCamel}.description || 'Sin descripción' }}
                                </p>
                            </div>
HTML : <<<HTML
                            <div class="col-md-12 mb-1">
                                <label class="form-label">Descripción</label>
                                <p class="text-muted">
                                    {{ {$singularCamel}.description || 'Sin descripción' }}
                                </p>
                            </div>
HTML;

        return <<<VUE
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
            {$singularCamel}: {
                type: Object,
                required: true,
            },
        },
        methods: {
            getStatusText(status) {
                const texts = { 1: 'Activo', 0: 'Inactivo' };
                return texts[status] || 'Desconocido';
            },
            getStatusClass(status) {
                const classes = {
                    1: 'badge bg-success-subtle text-success',
                    0: 'badge bg-danger-subtle text-danger',
                };
                return classes[status] || 'bg-secondary-subtle';
            },
        },
    };
</script>

<template>
    <Head title="Detalle {$visibleSingular}" />
    <Layout>
        <PageHeader title="Detalle {$visibleSingular}" pageTitle="{$visibleName}" />
        <div class="row">
            <div class="col-lg-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">{$visibleSingular}</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
{$codeBlock}
                            <div class="col-md-6 mb-1">
                                <label class="form-label">Estado</label>
                                <div>
                                    <span class="badge" :class="getStatusClass({$singularCamel}.status)">
                                        {{ getStatusText({$singularCamel}.status) }}
                                    </span>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-12 text-end">
                                <button type="button" class="btn btn-light me-2" @click="\$inertia.visit(route('{$moduleCode}.index'))">
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
    }

    /**
     * Crea la estructura "DataBridge": el listado del módulo expuesto a otros
     * módulos mediante contratos Shared (List + Match), con su DTO, services y
     * repositorio de listado autocontenido.
     */
    private function createDataBridge(): void
    {
        extract($this->ctx);
        /** @var string $ns @var string $shared @var string $plural @var string $singular @var string $table @var string $basePath @var bool $hasCode */

        $modelName = $singular;
        $listDto = "{$plural}ListDTO";
        $listContract = "List{$plural}Contract";
        $matchContract = "Match{$plural}RowContract";
        $listService = "List{$plural}Service";
        $matchService = "Match{$plural}RowService";
        $listRepoInterface = "{$plural}ListRepositoryInterface";
        $listRepoImpl = "Eloquent{$plural}ListRepository";

        $sharedDir = $this->nsToPath($this->sharedNs)."/{$plural}";
        $servicesPath = "{$basePath}/Application/Services";
        File::ensureDirectoryExists($sharedDir);
        File::ensureDirectoryExists($servicesPath);

        // ===== Shared: List contract =====
        $listContractContent = <<<PHP
<?php

declare(strict_types=1);

namespace {$shared}\\{$plural};

use {$ns}\\{$plural}\\Application\\DTOs\\{$listDto};

/**
 * Caso de uso: listar {$table} del tenant, filtrable y con paginación
 * OPCIONAL. Sin paginar -> se devuelven todos.
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
        $this->recordCreated("Shared/Contracts/{$plural}/{$listContract}.php");

        // ===== Shared: Match contract =====
        $matchContractContent = <<<PHP
<?php

declare(strict_types=1);

namespace {$shared}\\{$plural};

/**
 * Post-filtra las filas devueltas por {$listContract}::execute()->getData()
 * localizando la primera cuyo campo coincide EXACTAMENTE con el valor esperado.
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
        $this->recordCreated("Shared/Contracts/{$plural}/{$matchContract}.php");

        // ===== Shared: README =====
        $readmeContent = <<<MD
# Contrato {$plural} (`{$this->sharedNs}\\{$plural}`)

Lectura de `{$table}` para otros módulos, sin acoplarse al repositorio del
módulo dueño. Inspirado en el contrato de DataBridge, pero **sin `listing`** (el
módulo ES el listado) y resolviendo contra el Eloquent propio.

Implementación: `{$this->moduleNs}\\{$plural}\\Application\\Services\\{$listService}`.

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

## Post-filtro (`{$matchContract}`)

```php
matchByField(array \$rows, string \$field, mixed \$expectedValue): ?array;
```
MD;
        File::put("{$sharedDir}/README.md", $readmeContent);
        $this->recordCreated("Shared/Contracts/{$plural}/README.md");

        // ===== Module: List DTO =====
        $listDtoContent = <<<PHP
<?php

declare(strict_types=1);

namespace {$ns}\\{$plural}\\Application\\DTOs;

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
        $this->recordCreated("Application/DTOs/{$listDto}.php");

        // ===== Module: List service =====
        $listServiceContent = <<<PHP
<?php

declare(strict_types=1);

namespace {$ns}\\{$plural}\\Application\\Services;

use {$ns}\\{$plural}\\Application\\DTOs\\{$listDto};
use {$ns}\\{$plural}\\Domain\\Repositories\\{$listRepoInterface};
use {$shared}\\{$plural}\\{$listContract};

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
        $this->recordCreated("Application/Services/{$listService}.php");

        // ===== Module: Match row service =====
        $matchServiceContent = <<<PHP
<?php

declare(strict_types=1);

namespace {$ns}\\{$plural}\\Application\\Services;

use {$shared}\\{$plural}\\{$matchContract};

final class {$matchService} implements {$matchContract}
{
    public function matchByField(array \$rows, string \$field, mixed \$expectedValue): ?array
    {
        foreach (\$rows as \$row) {
            if (! is_array(\$row)) {
                continue;
            }

            \$actual = \$row[\$field] ?? null;
            if (\$this->valuesMatch(\$actual, \$expectedValue)) {
                return \$row;
            }
        }

        return null;
    }

    private function valuesMatch(mixed \$actual, mixed \$expected): bool
    {
        if (is_string(\$actual) && is_string(\$expected)) {
            // char(N) en PostgreSQL rellena con espacios; comparar el valor de negocio.
            return rtrim(\$actual) === rtrim(\$expected);
        }

        return \$actual === \$expected;
    }
}
PHP;
        File::put("{$servicesPath}/{$matchService}.php", $matchServiceContent);
        $this->recordCreated("Application/Services/{$matchService}.php");

        // ===== Module: List repository interface =====
        $listRepoInterfaceContent = <<<PHP
<?php

declare(strict_types=1);

namespace {$ns}\\{$plural}\\Domain\\Repositories;

/**
 * Contrato de listado de `{$table}` al estilo DataBridge: un módulo = un
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
        $this->recordCreated("Domain/Repositories/{$listRepoInterface}.php");

        // ===== Module: Eloquent list repository =====
        // Campo calculado según haya code.
        $extraFields = $hasCode
            ? "        return [\n            'codeAndDescription' => \$row->getAttribute('code').' - '.\$row->getAttribute('description'),\n        ];"
            : "        return [\n            'name' => (string) \$row->getAttribute('description'),\n        ];";
        $codeNormalize = $hasCode
            ? "        // char(N) llega con padding; el code de negocio no debe llevar espacios.\n        if (\$column === 'code' && is_string(\$value)) {\n            return rtrim(\$value);\n        }\n"
            : '';

        $listRepoImplContent = <<<PHP
<?php

declare(strict_types=1);

namespace {$ns}\\{$plural}\\Infrastructure\\Database\\Repositories;

use {$ns}\\{$plural}\\Domain\\Repositories\\{$listRepoInterface};
use {$ns}\\{$plural}\\Infrastructure\\Database\\Models\\{$modelName};
use App\\Shared\\Infrastructure\\Database\\Repositories\\BaseSearchRepository;
use Illuminate\\Database\\Eloquent\\Collection;
use Illuminate\\Database\\Eloquent\\Model;
use Illuminate\\Support\\Carbon;
use Illuminate\\Support\\Str;

/**
 * Lógica de listado AUTOCONTENIDA: filtros + paginación opcional + mapeo
 * dinámico snake_case -> camelCase. Replica el lookup de DataBridge contra el
 * modelo propio del módulo ({$modelName}, tabla {$table}).
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
            \$builder,
            \$filters,
            \$this->allowedColumns(),
        );
        // TODO: propagar \$matchAny cuando BaseSearchRepository lo soporte (hoy sólo aplica AND).
        unset(\$matchAny);

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
     * Campos calculados propios del módulo (etiqueta lista para selects).
     *
     * @return array<string, mixed>
     */
    private function extraFields(Model \$row): array
    {
{$extraFields}
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
{$codeNormalize}
        return \$value;
    }
}
PHP;
        File::put("{$basePath}/Infrastructure/Database/Repositories/{$listRepoImpl}.php", $listRepoImplContent);
        $this->recordCreated("Infrastructure/Database/Repositories/{$listRepoImpl}.php");
    }

    private function createServiceProvider(): void
    {
        extract($this->ctx);
        /** @var string $ns @var string $shared @var string $plural @var string $singular @var string $basePath */

        $serviceProviderName = "{$plural}ServiceProvider";
        $repositoryInterface = "{$singular}RepositoryInterface";
        $repositoryImplementation = "Eloquent{$singular}Repository";
        $listRepositoryInterface = "{$plural}ListRepositoryInterface";
        $listRepositoryImplementation = "Eloquent{$plural}ListRepository";
        $listContract = "List{$plural}Contract";
        $listService = "List{$plural}Service";
        $matchContract = "Match{$plural}RowContract";
        $matchService = "Match{$plural}RowService";

        $content = <<<PHP
<?php
declare(strict_types=1);

namespace {$ns}\\{$plural};

use Illuminate\\Support\\ServiceProvider;
use {$ns}\\{$plural}\\Domain\\Repositories\\{
    {$repositoryInterface},
    {$listRepositoryInterface},
};
use {$ns}\\{$plural}\\Infrastructure\\Database\\Repositories\\{
    {$repositoryImplementation},
    {$listRepositoryImplementation},
};
use {$ns}\\{$plural}\\Application\\Services\\{
    {$listService},
    {$matchService},
};

use {$shared}\\{$plural}\\{
    {$listContract},
    {$matchContract},
};

final class {$serviceProviderName} extends ServiceProvider
{
    public function register(): void
    {
        \$this->app->bind({$repositoryInterface}::class, {$repositoryImplementation}::class);

        \$this->app->singleton(
            {$listRepositoryInterface}::class,
            {$listRepositoryImplementation}::class,
        );
        \$this->app->singleton(
            {$listContract}::class,
            {$listService}::class,
        );
        \$this->app->singleton(
            {$matchContract}::class,
            {$matchService}::class,
        );
    }

    public function boot(): void
    {
        \$this->loadRoutesFrom(__DIR__.'/Infrastructure/Http/Routes/web.php');
        \$this->loadMigrationsFrom(__DIR__.'/Infrastructure/Database/Migrations');
    }
}
PHP;

        File::put("{$basePath}/{$serviceProviderName}.php", $content);
        $this->recordCreated("{$serviceProviderName}.php");
    }

    /**
     * Genera el test unitario del List Service en Suite (Pest + Mockery).
     */
    private function createListServiceTest(string $suitePath): void
    {
        extract($this->ctx);
        /** @var string $ns @var string $plural @var bool $hasCode */

        $dir = "{$suitePath}/tests/Unit/{$plural}";
        File::ensureDirectoryExists($dir);

        $listService = "List{$plural}Service";
        $listRepoInterface = "{$plural}ListRepositoryInterface";

        if ($hasCode) {
            $filterKey = 'code';
            $secondFilter = "['code' => '001', 'description' => 'Ejemplo', 'match_any' => true]";
            $secondExecute = "['code' => '001', 'description' => 'Ejemplo']";
            $row = "['id' => 1, 'code' => '001', 'description' => 'Ejemplo', 'codeAndDescription' => '001 - Ejemplo']";
            $extraKey = 'codeAndDescription';
            $extraValue = '001 - Ejemplo';
        } else {
            $filterKey = 'description';
            $secondFilter = "['description' => 'Ejemplo', 'status' => '1', 'match_any' => true]";
            $secondExecute = "['description' => 'Ejemplo', 'status' => '1']";
            $row = "['id' => 1, 'description' => 'Ejemplo', 'name' => 'Ejemplo']";
            $extraKey = 'name';
            $extraValue = 'Ejemplo';
        }

        $content = <<<PHP
<?php

declare(strict_types=1);

use {$ns}\\{$plural}\\Application\\Services\\{$listService};
use {$ns}\\{$plural}\\Domain\\Repositories\\{$listRepoInterface};

it('devuelve todos los registros cuando no se pagina', function () {
    \$repo = Mockery::mock({$listRepoInterface}::class);
    \$repo->shouldReceive('list')
        ->once()
        ->with(['{$filterKey}' => '001', 'match_any' => false])
        ->andReturn([
            'success' => true,
            'data' => [{$row}],
            'meta' => ['total' => 1],
        ]);

    \$dto = (new {$listService}(\$repo))->execute(['{$filterKey}' => '001']);

    expect(\$dto->isSuccess())->toBeTrue();
    expect(\$dto->getData()[0]['{$extraKey}'])->toBe('{$extraValue}');
    expect(\$dto->getMeta())->not->toHaveKey('current_page');
});

it('propaga matchAny al query del repositorio', function () {
    \$repo = Mockery::mock({$listRepoInterface}::class);
    \$repo->shouldReceive('list')
        ->once()
        ->with({$secondFilter})
        ->andReturn(['success' => true, 'data' => [], 'meta' => ['total' => 0]]);

    (new {$listService}(\$repo))->execute({$secondExecute}, true);
});
PHP;

        File::put("{$dir}/{$listService}Test.php", $content);
        $this->recordCreated("Suite/tests/Unit/{$plural}/{$listService}Test.php");
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
        $this->recordCreated("config/app.php (provider {$moduleNamePlural}ServiceProvider)");
    }
}
