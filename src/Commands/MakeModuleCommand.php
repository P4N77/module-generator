<?php

declare(strict_types=1);

namespace Sodeker\ModuleGenerator\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Sodeker\ModuleGenerator\Console\ModuleConsole;
use Sodeker\ModuleGenerator\Generators\ApplicationGenerator;
use Sodeker\ModuleGenerator\Generators\DataBridgeGenerator;
use Sodeker\ModuleGenerator\Generators\DomainGenerator;
use Sodeker\ModuleGenerator\Generators\FrontendGenerator;
use Sodeker\ModuleGenerator\Generators\HttpGenerator;
use Sodeker\ModuleGenerator\Generators\InfrastructureGenerator;
use Sodeker\ModuleGenerator\Generators\MigrationGenerator;
use Sodeker\ModuleGenerator\Generators\ProviderGenerator;
use Sodeker\ModuleGenerator\Generators\SeederGenerator;
use Sodeker\ModuleGenerator\Generators\TestGenerator;
use Sodeker\ModuleGenerator\Support\FieldMapper;
use Sodeker\ModuleGenerator\Support\GeneratorConfig;
use Sodeker\ModuleGenerator\Support\ModuleContext;
use Sodeker\ModuleGenerator\Support\ModuleNaming;
use Sodeker\ModuleGenerator\Support\ModuleWriter;
use Sodeker\ModuleGenerator\Support\SqlSource;
use Sodeker\ModuleGenerator\Support\SqlTableParser;
use Sodeker\ModuleGenerator\Support\StubRenderer;
use Sodeker\ModuleGenerator\Support\SuiteLocator;
use Sodeker\ModuleGenerator\Support\TableDefinition;

/**
 * Genera un módulo DDD completo del ecosistema Sódeker. Este comando solo
 * pregunta y orquesta: cada capa la escribe su generator, a partir de los
 * stubs de stubs/.
 */
class MakeModuleCommand extends Command
{
    protected $signature = 'make:module
        {name : The name of the module}
        {--sql= : Archivo .sql/.txt con el CREATE TABLE, o el SQL en línea. Si se omite, se puede pegar en consola}';

    protected $description = 'Create a new DDD module with complete folder structure';

    /** Opción de escape al listar las tablas detectadas en el SQL. */
    private const CANCEL = 'cancelar';

    public function handle(): int
    {
        $config = GeneratorConfig::fromConfig();
        $console = new ModuleConsole($this);

        $console->banner();

        $naming = ModuleNaming::fromArgument((string) $this->argument('name'), $config->tablePrefix);

        $conflicts = $this->existingPaths($naming->plural, $config);
        if ($conflicts !== []) {
            $console->moduleExists($naming->plural, $conflicts);

            return Command::FAILURE;
        }

        // ===== Definición de la tabla a partir del SQL del modelador =====
        try {
            $sql = (new SqlSource($this))->capture(
                $this->option('sql'),
                $this->input->isInteractive(),
            );
        } catch (RuntimeException $e) {
            $this->components->error($e->getMessage());

            return Command::FAILURE;
        }

        $table = null;
        if ($sql !== null) {
            $table = $this->resolveTable($sql, $naming, $console);
            if ($table === null) {
                return Command::FAILURE;
            }

            $console->tableSummary($table, strcasecmp($table->name, $naming->table) === 0);
        }

        $ctx = $this->askModuleShape($naming, $config, $table);
        $console->header($ctx);

        // ===== Decisiones de migración/seeder (Suite o respaldo local) =====
        $locator = new SuiteLocator($this, $config);
        $suitePath = $locator->resolve();
        if ($suitePath === null) {
            return Command::FAILURE;
        }

        $isLocal = $suitePath === SuiteLocator::LOCAL_TARGET;
        $migrationProject = null;
        $wantSeeder = false;

        if (! $isLocal) {
            $migrationProject = $locator->chooseProject("{$suitePath}/database/migrations/tenant", 'migraciones');
            $wantSeeder = $this->confirm('¿Desea agregar un seeder para este módulo?', false);
        }

        $writer = new ModuleWriter();
        $console->runPhases($this->buildPhases($ctx, $config, $writer, $suitePath, $migrationProject, $wantSeeder));

        $this->components->task('Limpiando cachés', function (): bool {
            $this->callSilently('optimize:clear');

            return true;
        });

        $console->summary(
            $ctx,
            $writer->count(),
            $isLocal ? 'local (dentro del módulo)' : "Suite · {$migrationProject}",
        );
        $console->manualSteps($ctx);

        return Command::SUCCESS;
    }

    /**
     * Localiza en el SQL la tabla del módulo. Si el nombre no coincide con la
     * convención (pluralización + snake_case + prefijo), lista las tablas
     * detectadas y deja elegir, en vez de fallar.
     */
    private function resolveTable(string $sql, ModuleNaming $naming, ModuleConsole $console): ?TableDefinition
    {
        $tables = SqlTableParser::parse($sql);

        if ($tables === []) {
            $this->components->error('No se encontró ningún bloque CREATE TABLE en el SQL proporcionado.');

            return null;
        }

        $match = SqlTableParser::find($tables, $naming->table);
        if ($match !== null) {
            return $match;
        }

        $console->tableNotFound($naming->table, $tables);

        $names = array_map(static fn (TableDefinition $t): string => $t->name, $tables);
        $choice = $this->choice(
            'Selecciona la tabla que corresponde al módulo',
            array_merge($names, [self::CANCEL]),
        );

        if ($choice === self::CANCEL) {
            $this->components->info('Operación cancelada. No se creó nada.');

            return null;
        }

        return SqlTableParser::find($tables, (string) $choice);
    }

    /**
     * Rutas que ya existen y bloquean la generación: módulo, contratos
     * compartidos y vistas Vue.
     *
     * @return list<string>
     */
    private function existingPaths(string $plural, GeneratorConfig $config): array
    {
        return array_values(array_filter([
            $config->modulePath($plural),
            $config->sharedPath($plural),
            $config->pagesDir($plural),
        ], static fn (string $path): bool => File::exists($path)));
    }

    /**
     * Preguntas interactivas que definen la forma del módulo. Cuando el SQL está
     * disponible, la tabla, los campos y la presencia de "code" salen de él y no
     * se preguntan; solo se pide lo que el SQL no sabe (tipo, app, nombre, URL).
     */
    private function askModuleShape(ModuleNaming $naming, GeneratorConfig $config, ?TableDefinition $table): ModuleContext
    {
        // Con SQL: code, campos y nombre de tabla se detectan. Sin SQL: se pregunta.
        $fields = $table !== null ? FieldMapper::map($table) : [];
        $tableName = $table?->name;
        $hasCode = $table !== null
            ? $table->hasColumn('code')
            : $this->confirm('¿El módulo lleva código (columna "code" con unicidad)?', true);

        $type = $this->choice(
            '¿Qué tipo de módulo es?',
            ['usuario (Casbin + menú)', 'interno (solo Sodeker, sin menú)'],
            'usuario (Casbin + menú)'
        );
        $isInternal = str_starts_with($type, 'interno');

        $appSlug = 'suite';
        $visibleName = $naming->plural;

        // Los módulos internos no tienen app destino ni nombre visible: no
        // aparecen en el menú.
        if (! $isInternal) {
            $appSlug = Str::lower(trim((string) $this->choice(
                'App destino (para seeders/permisos)',
                ['suite', 'sat', 'iris', 'tut', 'econnect', 'f16', 'fintegra'],
                'suite'
            )));
            $visibleName = trim((string) $this->ask('Nombre visible en español (ej. Tipos Email)', $naming->plural));
        }

        $indexUrl = trim((string) $this->ask('URL del índice en español (ej. /tipos-email)', '/'.$naming->kebab));

        return ModuleContext::make(
            naming: $naming,
            config: $config,
            hasCode: $hasCode,
            isInternal: $isInternal,
            appSlug: $appSlug,
            visibleName: $visibleName,
            indexUrl: '/'.ltrim($indexUrl, '/'),
            fields: $fields,
            table: $tableName,
        );
    }

    /**
     * Fases de generación, en orden. Cada una se ejecuta bajo la barra de progreso.
     *
     * @return array<string, callable>
     */
    private function buildPhases(
        ModuleContext $ctx,
        GeneratorConfig $config,
        ModuleWriter $writer,
        string $suitePath,
        ?string $migrationProject,
        bool $wantSeeder,
    ): array {
        $stubs = StubRenderer::default();

        $migration = new MigrationGenerator($ctx, $stubs, $writer);
        $domain = new DomainGenerator($ctx, $stubs, $writer);
        $application = new ApplicationGenerator($ctx, $stubs, $writer);
        $infrastructure = new InfrastructureGenerator($ctx, $stubs, $writer);
        $http = new HttpGenerator($ctx, $stubs, $writer);
        $frontend = new FrontendGenerator($ctx, $stubs, $writer, $config);
        $dataBridge = new DataBridgeGenerator($ctx, $stubs, $writer, $config);
        $provider = new ProviderGenerator($ctx, $stubs, $writer);

        $isLocal = $suitePath === SuiteLocator::LOCAL_TARGET;

        $phases = [];

        $phases[$isLocal ? 'Migración (local)' : 'Migración (Suite)'] = $isLocal
            ? fn () => $migration->local()
            : fn () => $migration->suite($suitePath, (string) $migrationProject);

        $phases['Value Object (Status)'] = fn () => $domain->statusValueObject();
        $phases['Modelo Eloquent'] = fn () => $infrastructure->model();
        $phases['Entidad de dominio'] = fn () => $domain->entity();
        $phases['DTOs'] = fn () => $application->dtos();
        $phases['Repositorio (interfaz)'] = fn () => $domain->repositoryInterface();
        $phases['Commands'] = fn () => $application->commands();
        $phases['Handlers'] = fn () => $application->handlers();
        $phases['Repositorio (Eloquent)'] = fn () => $infrastructure->repository();
        $phases['Excepciones'] = fn () => $domain->notFoundException();
        $phases['Form Requests'] = fn () => $http->requests();
        $phases['Controlador'] = fn () => $http->controller();
        $phases['Vistas Vue'] = fn () => $frontend->pages();
        $phases['Rutas'] = fn () => $http->routes();
        $phases['DataBridge (contratos)'] = fn () => $dataBridge->generate();
        $phases['ServiceProvider'] = fn () => $provider->serviceProvider();
        $phases['Registro en config/app.php'] = function () use ($provider): void {
            $warning = $provider->registerInConfig();
            if ($warning !== null) {
                $this->warn($warning);
            }
        };

        if (! $isLocal) {
            $phases['Test (List Service)'] = fn () => (new TestGenerator($ctx, $stubs, $writer))->listService($suitePath);
        }

        if ($wantSeeder) {
            $phases['Seeder (Suite)'] = fn () => (new SeederGenerator($ctx, $stubs, $writer))
                ->suite($suitePath, (string) $migrationProject);
        }

        return $phases;
    }
}
