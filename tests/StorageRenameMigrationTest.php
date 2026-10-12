<?php

namespace JosephCrowell\GDPR\Tests;

require_once dirname(__DIR__, 4) . '/vendor/autoload.php';

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use JosephCrowell\GDPR\Updates\CreateOfflineGdprCookieGroups;
use JosephCrowell\GDPR\Updates\CreateOfflineGdprCookies;
use JosephCrowell\GDPR\Updates\CreateOfflineGdprLogs;
use JosephCrowell\GDPR\Updates\RenameGdprStorage;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use Winter\Storm\Filesystem\Filesystem;
use Winter\Storm\Support\ClassLoader;

class StorageRenameMigrationTest extends TestCase
{
    private const TABLES = [
        'offline_gdpr_cookie_groups' => 'josephcrowell_gdpr_cookie_groups',
        'offline_gdpr_cookies' => 'josephcrowell_gdpr_cookies',
        'offline_gdpr_logs' => 'josephcrowell_gdpr_logs',
    ];

    private const SETTINGS = [
        'offline_gdpr_general_settings' => 'josephcrowell_gdpr_general_settings',
        'offline_gdpr_cookie_consent_settings' => 'josephcrowell_gdpr_cookie_consent_settings',
        'offline_gdpr_data_retention_settings' => 'josephcrowell_gdpr_data_retention_settings',
    ];

    private static $loader;
    private $previousFacadeApplication;
    private $database;
    private $migration;

    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__, 4);
        self::$loader = new ClassLoader(new Filesystem(), $root, '');
        self::$loader->manifest = [];
        self::$loader->autoloadPackage('System', $root . '/modules/system');
        self::$loader->autoloadPackage('JosephCrowell\\GDPR', dirname(__DIR__));
        self::$loader->addAliases([\Winter\Storm\Database\Model::class => 'Model']);
        spl_autoload_register([self::$loader, 'load']);
        if (!class_exists('Schema', false)) {
            class_alias(Schema::class, 'Schema');
        }
        foreach (['create_offline_gdpr_cookie_groups.php', 'create_offline_gdpr_cookies.php', 'create_offline_gdpr_logs.php', 'rename_gdpr_storage.php'] as $file) {
            require_once dirname(__DIR__) . '/updates/' . $file;
        }
    }

    public static function tearDownAfterClass(): void
    {
        spl_autoload_unregister([self::$loader, 'load']);
    }

    protected function setUp(): void
    {
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $container = new Container();
        $this->database = new Manager($container);
        $this->database->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => 'test_',
            'foreign_key_constraints' => true,
        ]);
        $container->instance('db', $this->database->getDatabaseManager());
        $container->instance('db.schema', $this->database->getConnection()->getSchemaBuilder());
        $container->instance('cache', new Repository(new ArrayStore()));
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
        Schema::create('system_settings', function ($table) {
            $table->increments('id');
            $table->string('item')->unique();
            $table->text('value');
        });
        $this->migration = new RenameGdprStorage();
    }

    protected function tearDown(): void
    {
        $this->database->getDatabaseManager()->disconnect();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacadeApplication);
    }

    private function createLegacyStorage(): void
    {
        (new CreateOfflineGdprCookieGroups())->up();
        (new CreateOfflineGdprCookies())->up();
        (new CreateOfflineGdprLogs())->up();
        DB::table('offline_gdpr_cookie_groups')->insert(['id' => 7, 'slug' => 'essential', 'name' => 'Essential']);
        DB::table('offline_gdpr_cookies')->insert([
            'id' => 11, 'code' => 'session', 'name' => 'Session', 'cookie_group_id' => 7,
            'levels' => '[{"level_name":"Required"}]',
        ]);
        DB::table('offline_gdpr_logs')->insert(['id' => 19, 'session_id' => 'visitor', 'decision' => 'accepted']);
        foreach (array_keys(self::SETTINGS) as $index => $item) {
            DB::table('system_settings')->insert(['id' => $index + 1, 'item' => $item, 'value' => '{"enabled":true,"keep_days":42}']);
        }
        DB::table('system_settings')->insert(['id' => 9, 'item' => 'unrelated_settings', 'value' => '{"keep":true}']);
    }

    private function rows(string $table): array
    {
        return DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    }

    private function assertLegacyTablesUnchanged(): void
    {
        foreach (self::TABLES as $source => $destination) {
            $this->assertTrue(Schema::hasTable($source), $source);
        }
        $this->assertSame(7, DB::table('offline_gdpr_cookie_groups')->value('id'));
        $this->assertSame(11, DB::table('offline_gdpr_cookies')->value('id'));
    }

    private function expectConflict(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected the rename to refuse a conflict');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('both ', $exception->getMessage());
        }
    }

    public function testUpgradePreservesTableRecordsAndSettingsValues(): void
    {
        $this->createLegacyStorage();
        $tables = [];
        foreach (self::TABLES as $source => $destination) {
            $tables[$destination] = $this->rows($source);
        }
        $settings = $this->rows('system_settings');
        foreach ($settings as &$row) {
            $row['item'] = self::SETTINGS[$row['item']] ?? $row['item'];
        }
        unset($row);
        $this->migration->up();
        foreach (self::TABLES as $source => $destination) {
            $this->assertFalse(Schema::hasTable($source));
            $this->assertSame($tables[$destination], $this->rows($destination));
        }
        $this->assertSame($settings, $this->rows('system_settings'));
    }

    public function testRollbackRestoresAllOriginalNamesAndData(): void
    {
        $this->createLegacyStorage();
        $settings = $this->rows('system_settings');
        $tables = [];
        foreach (self::TABLES as $source => $destination) {
            $tables[$source] = $this->rows($source);
        }
        $this->migration->up();
        $this->migration->down();
        foreach (self::TABLES as $source => $destination) {
            $this->assertFalse(Schema::hasTable($destination));
            $this->assertSame($tables[$source], $this->rows($source));
        }
        $this->assertSame($settings, $this->rows('system_settings'));
    }

    public function testUpgradeAndRollbackCanBeRepeated(): void
    {
        $this->createLegacyStorage();
        $this->migration->up();
        $settings = $this->rows('system_settings');
        $this->migration->up();
        $this->assertSame($settings, $this->rows('system_settings'));
        $this->migration->down();
        $this->migration->down();
        $this->assertLegacyTablesUnchanged();
    }

    public function testTableConflictIsDetectedBeforeAnyRename(): void
    {
        $this->createLegacyStorage();
        Schema::create('josephcrowell_gdpr_logs', function ($table) {
            $table->increments('id');
        });
        $this->expectConflict(fn () => $this->migration->up());
        $this->assertLegacyTablesUnchanged();
        $this->assertFalse(Schema::hasTable('josephcrowell_gdpr_cookie_groups'));
    }

    public function testSettingsConflictIsDetectedBeforeAnyRename(): void
    {
        $this->createLegacyStorage();
        DB::table('system_settings')->insert(['item' => 'josephcrowell_gdpr_cookie_consent_settings', 'value' => '{"keep":"destination"}']);
        $settings = $this->rows('system_settings');
        $this->expectConflict(fn () => $this->migration->up());
        $this->assertLegacyTablesUnchanged();
        $this->assertSame($settings, $this->rows('system_settings'));
    }

    public function testRollbackRefusesToOverwriteLegacyTables(): void
    {
        $this->createLegacyStorage();
        $this->migration->up();
        Schema::create('offline_gdpr_logs', function ($table) {
            $table->increments('id');
        });
        $this->expectConflict(fn () => $this->migration->down());
        $this->assertFalse(Schema::hasTable('offline_gdpr_cookie_groups'));
        $this->assertTrue(Schema::hasTable('josephcrowell_gdpr_cookie_groups'));
    }

    public function testRollbackRefusesToOverwriteLegacySettings(): void
    {
        $this->createLegacyStorage();
        $this->migration->up();
        DB::table('system_settings')->insert(['item' => 'offline_gdpr_general_settings', 'value' => '{"keep":"legacy"}']);
        $settings = $this->rows('system_settings');
        $this->expectConflict(fn () => $this->migration->down());
        $this->assertFalse(Schema::hasTable('offline_gdpr_cookie_groups'));
        $this->assertSame($settings, $this->rows('system_settings'));
    }

    public function testPartiallyRenamedStorageCanResume(): void
    {
        $this->createLegacyStorage();
        Schema::rename('offline_gdpr_cookie_groups', 'josephcrowell_gdpr_cookie_groups');
        DB::table('system_settings')->where('item', 'offline_gdpr_general_settings')->update(['item' => 'josephcrowell_gdpr_general_settings']);
        $this->migration->up();
        $this->assertSame(7, DB::table('josephcrowell_gdpr_cookie_groups')->value('id'));
        foreach (self::SETTINGS as $source => $destination) {
            $this->assertFalse(DB::table('system_settings')->where('item', $source)->exists());
            $this->assertSame('{"enabled":true,"keep_days":42}', DB::table('system_settings')->where('item', $destination)->value('value'));
        }
    }

    public function testMissingTableFailsBeforeRenamingOtherTables(): void
    {
        $this->createLegacyStorage();
        Schema::drop('offline_gdpr_logs');
        try {
            $this->migration->up();
            $this->fail('Expected missing storage to be reported');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('neither ', $exception->getMessage());
        }
        $this->assertTrue(Schema::hasTable('offline_gdpr_cookie_groups'));
        $this->assertFalse(Schema::hasTable('josephcrowell_gdpr_cookie_groups'));
    }

    public function testUnsetSettingsAreNotCreated(): void
    {
        $this->createLegacyStorage();
        DB::table('system_settings')->whereIn('item', array_keys(self::SETTINGS))->delete();
        $this->migration->up();
        $this->assertSame(['unrelated_settings'], DB::table('system_settings')->pluck('item')->all());
    }

    public function testSettingsFailureRollsBackValuesAndCanResumeAfterTableRenames(): void
    {
        $this->createLegacyStorage();
        $settings = $this->rows('system_settings');
        DB::unprepared("CREATE TRIGGER block_settings_rename BEFORE UPDATE OF item ON test_system_settings
            WHEN NEW.item = 'josephcrowell_gdpr_data_retention_settings'
            BEGIN SELECT RAISE(ABORT, 'forced settings failure'); END");
        try {
            $this->migration->up();
            $this->fail('Expected the simulated settings write failure');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('forced settings failure', $exception->getMessage());
        }
        $this->assertSame($settings, $this->rows('system_settings'));
        foreach (self::TABLES as $source => $destination) {
            $this->assertTrue(Schema::hasTable($destination));
            $this->assertFalse(Schema::hasTable($source));
        }
        DB::unprepared('DROP TRIGGER block_settings_rename');
        $this->migration->up();
        foreach (self::SETTINGS as $source => $destination) {
            $this->assertTrue(DB::table('system_settings')->where('item', $destination)->exists());
        }
    }

    public function testRenamedCookieTableRetainsUniqueCodeConstraint(): void
    {
        $this->createLegacyStorage();
        $this->migration->up();
        $this->expectException(QueryException::class);
        DB::table('josephcrowell_gdpr_cookies')->insert(['code' => 'session', 'name' => 'Duplicate', 'cookie_group_id' => 7]);
    }

    public function testRuntimeModelsUseRenamedStorage(): void
    {
        foreach (['CookieGroup' => 'cookie_groups', 'Cookie' => 'cookies', 'Log' => 'logs'] as $model => $suffix) {
            $properties = (new \ReflectionClass('JosephCrowell\\GDPR\\Models\\' . $model))->getDefaultProperties();
            $this->assertSame('josephcrowell_gdpr_' . $suffix, $properties['table']);
        }
        $properties = (new \ReflectionClass('JosephCrowell\\GDPR\\Models\\CookieGroup'))->getDefaultProperties();
        $this->assertSame('josephcrowell_gdpr_cookies', $properties['hasMany']['cookies']['table']);
        foreach (['GDPRSettings' => 'general_settings', 'CookieConsentSettings' => 'cookie_consent_settings', 'DataRetentionSettings' => 'data_retention_settings'] as $model => $suffix) {
            $properties = (new \ReflectionClass('JosephCrowell\\GDPR\\Models\\' . $model))->getDefaultProperties();
            $this->assertSame('josephcrowell_gdpr_' . $suffix, $properties['settingsCode']);
        }
    }

    public function testBothOldAndNewSettingsCacheKeysAreCleared(): void
    {
        $this->createLegacyStorage();
        foreach (self::SETTINGS as $source => $destination) {
            Cache::forever('system::settings.' . $source, 'stale');
            Cache::forever('system::settings.' . $destination, 'stale');
        }
        $this->migration->up();
        foreach (self::SETTINGS as $source => $destination) {
            $this->assertNull(Cache::get('system::settings.' . $source));
            $this->assertNull(Cache::get('system::settings.' . $destination));
        }
    }

    public function testRuntimeReplacementKeepsOnlyOfflineWhileComposerReplacesBothPackages(): void
    {
        $metadata = Yaml::parseFile(dirname(__DIR__) . '/plugin.yaml');
        // UpdateManager refuses more than one runtime replacement. OFFLINE is
        // the one required to preserve existing migration history and aliases.
        $this->assertSame(['OFFLINE.GDPR' => '*'], $metadata['plugin']['replaces']);

        $composer = json_decode(file_get_contents(dirname(__DIR__) . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('*', $composer['replace']['offline/oc-gdpr-plugin']);
        $this->assertSame('*', $composer['replace']['hounddd/wn-gdprplus-plugin']);
    }

    public function testFreshInstallationRunsHistoricalMigrationsBeforeRename(): void
    {
        $versions = Yaml::parseFile(dirname(__DIR__) . '/updates/version.yaml');
        foreach ($versions as $details) {
            foreach ($details as $detail) {
                if (!str_ends_with($detail, '.php')) {
                    continue;
                }
                require_once dirname(__DIR__) . '/updates/' . $detail;
                $class = 'JosephCrowell\\GDPR\\Updates\\' . Str::studly(pathinfo($detail, PATHINFO_FILENAME));
                (new $class())->up();
            }
        }
        foreach (self::TABLES as $source => $destination) {
            $this->assertFalse(Schema::hasTable($source));
            $this->assertTrue(Schema::hasTable($destination));
        }
        $this->assertTrue(Schema::hasColumn('josephcrowell_gdpr_logs', 'useragent'));
        $this->assertTrue(Schema::hasColumn('josephcrowell_gdpr_cookies', 'provider'));
        $this->assertSame([], $this->rows('system_settings'));
    }
}
