<?php

namespace JosephCrowell\GDPR\Tests;

require_once dirname(__DIR__, 4) . '/vendor/autoload.php';

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Cookie\CookieJar;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Routing\ResponseFactory;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory as ValidationFactory;
use Illuminate\Validation\DatabasePresenceVerifier;
use JosephCrowell\GDPR\Classes\Cookies\ConsentCookie;
use JosephCrowell\GDPR\Components\CookieBannerWide;
use JosephCrowell\GDPR\Components\CookieManager;
use JosephCrowell\GDPR\Models\Log;
use PHPUnit\Framework\TestCase;
use System\Behaviors\SettingsModel;
use Winter\Storm\Database\Model;
use Winter\Storm\Exception\ValidationException;
use Winter\Storm\Filesystem\Filesystem;
use Winter\Storm\Support\ClassLoader;

// Register the plugin and CMS classes before declaring the test component below.
$root = dirname(__DIR__, 4);
$wideTestLoader = new ClassLoader(new Filesystem(), $root, '');
$wideTestLoader->manifest = [];
$wideTestLoader->autoloadPackage('System', $root . '/modules/system');
$wideTestLoader->autoloadPackage('Cms', $root . '/modules/cms');
$wideTestLoader->autoloadPackage('JosephCrowell\\GDPR', dirname(__DIR__));
$wideTestLoader->addAliases([
    Model::class => 'Model',
    \Winter\Storm\Support\Str::class => 'Str',
    \Illuminate\Support\Facades\Config::class => 'Config',
    \Illuminate\Support\Facades\Lang::class => 'Lang',
    \Illuminate\Support\Facades\Event::class => 'Event',
    \Illuminate\Support\Facades\Session::class => 'Session',
    \Illuminate\Support\Facades\File::class => 'File',
]);
spl_autoload_register([$wideTestLoader, 'load']);

class CookieBannerWideTest extends TestCase
{
    private $previousContainer;
    private $previousFacades;
    private $previousResolver;
    private $previousDispatcher;
    private $database;
    private $container;

    protected function setUp(): void
    {
        $this->previousContainer = Container::getInstance();
        $this->previousFacades = Facade::getFacadeApplication();
        $this->previousResolver = Model::getConnectionResolver();
        $this->previousDispatcher = Model::getEventDispatcher();
        $this->container = new Container();
        Container::setInstance($this->container);
        $this->container->instance('app', $this->container);
        $this->container->instance('config', new Repository(['app' => ['locale' => 'en']]));
        $this->container->instance('files', new Filesystem());
        $this->database = new Manager($this->container);
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => 'test_']);
        $events = new Dispatcher($this->container);
        $this->container->instance('events', $events);
        $this->database->setEventDispatcher($events);
        $this->database->bootEloquent();
        Model::clearBootedModels();
        $this->container->instance('db', $this->database->getDatabaseManager());
        $this->container->instance('db.schema', $this->database->getConnection()->getSchemaBuilder());
        $this->container->instance('cache', new CacheRepository(new ArrayStore()));
        $loader = new ArrayLoader();
        $loader->addMessages('en', 'lang', require dirname(__DIR__) . '/lang/en/lang.php', 'josephcrowell.gdpr');
        $translator = new Translator($loader, 'en');
        $this->container->instance('translator', $translator);
        $presence = new DatabasePresenceVerifier($this->database->getDatabaseManager());
        $validator = new ValidationFactory($translator, $this->container);
        $validator->setPresenceVerifier($presence);
        $this->container->instance('validator', $validator);
        $this->container->instance('validation.presence', $presence);
        $session = new Store('gdpr-test', new ArraySessionHandler(120));
        $session->start();
        $this->container->instance('session', $session);
        $this->container->instance('cookie', new CookieJar());
        $this->container->instance('Illuminate\\Contracts\\Routing\\ResponseFactory', new ResponseFactory(
            $this->createMock(ViewFactory::class), $this->createMock(Redirector::class)
        ));
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->container);
        SettingsModel::clearInternalCache();
        $this->request([]);
        $this->createStorage();
    }

    protected function tearDown(): void
    {
        SettingsModel::clearInternalCache();
        $this->database->getDatabaseManager()->disconnect();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacades);
        Container::setInstance($this->previousContainer);
        if ($this->previousResolver) Model::setConnectionResolver($this->previousResolver);
        else Model::unsetConnectionResolver();
        if ($this->previousDispatcher) Model::setEventDispatcher($this->previousDispatcher);
        else Model::unsetEventDispatcher();
    }

    private function request(array $data): void
    {
        $this->container->instance('request', Request::create('https://example.test/', 'POST', $data, [], [], [
            'HTTP_USER_AGENT' => 'Mozilla/5.0',
        ]));
        Facade::clearResolvedInstance('request');
    }

    private function createStorage(): void
    {
        Schema::create('system_settings', function ($table) {
            $table->increments('id');
            $table->string('item')->unique();
            $table->text('value');
        });
        DB::table('system_settings')->insert([
            'item' => 'josephcrowell_gdpr_general_settings', 'value' => '{"log_enabled":false}',
        ]);
        Schema::create('josephcrowell_gdpr_cookie_groups', function ($table) {
            $table->increments('id');
            $table->string('slug');
            $table->string('name');
            $table->text('description');
            $table->boolean('required');
            $table->integer('sort_order')->default(0);
        });
        Schema::create('josephcrowell_gdpr_cookies', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('cookie_group_id');
            $table->string('code');
            $table->string('name');
            $table->text('description');
            $table->boolean('initial_status');
            $table->text('levels');
            $table->integer('sort_order')->default(0);
        });
        Schema::create('josephcrowell_gdpr_logs', function ($table) {
            $table->increments('id');
            $table->string('session_id');
            $table->string('decision');
            $table->text('useragent')->nullable();
            $table->timestamps();
        });
        foreach ([1 => true, 2 => false, 3 => false] as $id => $required) {
            DB::table('josephcrowell_gdpr_cookie_groups')->insert([
                'id' => $id, 'slug' => 'group-' . $id, 'name' => 'Group ' . $id,
                'description' => 'Description', 'required' => $required, 'sort_order' => $id,
            ]);
        }
        foreach ([
            [1, 'session', false, []],
            [2, 'analytics', true, [['level_name' => 'Basic'], ['level_name' => 'Detailed']]],
            [2, 'disabled-preset', false, []],
            [3, 'inactive', false, []],
        ] as [$group, $code, $initial, $levels]) {
            DB::table('josephcrowell_gdpr_cookies')->insert([
                'cookie_group_id' => $group, 'code' => $code, 'name' => $code,
                'description' => 'Description', 'initial_status' => $initial, 'levels' => json_encode($levels),
            ]);
        }
    }

    private function banner(array $properties = []): TestableWideBanner
    {
        $banner = new TestableWideBanner(null, $properties);
        $banner->alias = 'cookieBannerWide';
        $banner->init();
        return $banner;
    }

    private function consent($response): array
    {
        $cookies = $response->headers->getCookies();
        $this->assertCount(1, $cookies);
        $this->assertSame('gdpr_cookie_consent', $cookies[0]->getName());
        $this->assertTrue($cookies[0]->isSecure());
        $this->assertTrue($cookies[0]->isHttpOnly());
        $this->assertSame('lax', $cookies[0]->getSameSite());
        return json_decode($cookies[0]->getValue(), true);
    }

    public function testInitialSelectionRespectsRequiredGroupsAndActiveCookiePresets(): void
    {
        $banner = $this->banner();
        $this->assertSame([1, 2], $banner->selectedGroupIds);
        $this->assertSame('blue', $banner->colorScheme);
        $this->assertFalse($banner->bgCookies);
        $this->assertCount(17, $banner->getColor_SchemeOptions());
        $this->assertSame('Green', $banner->getColor_SchemeOptions()['green']);
    }

    public function testAppearancePropertiesAreValidatedAndNormalized(): void
    {
        $banner = $this->banner(['color_scheme' => 'rose', 'bg_cookie' => '1']);
        $this->assertSame('rose', $banner->colorScheme);
        $this->assertTrue($banner->bgCookies);
        $banner = $this->banner(['color_scheme' => 'invalid" class', 'bg_cookie' => 'false']);
        $this->assertSame('blue', $banner->colorScheme);
        $this->assertFalse($banner->bgCookies);
    }

    public function testGroupSelectionUsesActivePresetsAndAlwaysAddsRequiredCookies(): void
    {
        $banner = $this->banner();
        $this->request(['cookies' => ['2', '2', '999']]);
        $this->assertSame(['session' => 0, 'analytics' => 2], $this->consent($banner->onSubmit()));
    }

    public function testEmptyAndInactiveSelectionsKeepOnlyRequiredCookies(): void
    {
        $banner = $this->banner();
        $this->assertSame(['session' => 0], $this->consent($banner->onSubmit()));
        $this->request(['cookies' => ['3']]);
        $this->assertSame(['session' => 0], $this->consent($banner->onSubmit()));
    }

    public function testMalformedSelectionIsRejectedBeforeConsentChanges(): void
    {
        foreach (['2', null, [['id' => 2]], [false], [-1], ['1 OR 1=1'], [PHP_INT_MAX . '0']] as $invalid) {
            $this->request(['cookies' => $invalid]);
            try {
                $this->banner()->onSubmit();
                $this->fail('Invalid selection must be rejected');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('cookies', $exception->getFields());
            }
        }
        $this->assertNull($this->container['session']->get('gdpr_cookie_consent'));
    }

    public function testSelectionReturnsUpdatedPartialAndConsentMetadata(): void
    {
        $banner = $this->banner(['update_partial' => 'privacy/content', 'update_selector' => '#privacy']);
        $this->request(['cookies' => ['2']]);
        $response = $banner->onSubmit();
        $payload = json_decode($response->getContent(), true);
        $this->assertSame('<p>Updated</p>', $payload['#privacy']);
        $this->assertSame('<p>Updated</p>', $payload['content']);
        $this->assertSame(['session' => 0, 'analytics' => 2], $payload['consentCookie']);
        $this->assertSame('privacy/content', $banner->rendered[0]);
        $this->assertInstanceOf(ConsentCookie::class, $banner->rendered[1]['consentCookie']);
    }

    public function testAcceptAndDeclineRemainCompatibleWithBaseBanner(): void
    {
        $banner = $this->banner();
        $this->assertSame(['session' => 0, 'analytics' => 2], $this->consent($banner->onAccept()));
        $this->assertSame(['session' => 0], $this->consent($banner->onDecline()));
    }

    public function testCookieManagerPageSuppressesBannerAndAssets(): void
    {
        $banner = $this->banner();
        $banner->attachPage(new WideBannerPageFixture('privacy', 'Privacy', true));
        $banner->onRun();
        $this->assertTrue($banner->hide);
        $this->assertSame([], $banner->css);
        $this->assertSame([], $banner->js);
    }

    public function testDecidedVisitorsDoNotSeeBanner(): void
    {
        $banner = $this->banner();
        $this->container['request']->cookies->set('gdpr_cookie_consent', '{"session":0}');
        $banner->onRun();
        $this->assertTrue($banner->hide);
        $this->assertSame([], $banner->css);
    }

    public function testStandaloneAssetsAndOptOutOfCss(): void
    {
        $banner = $this->banner();
        $banner->onRun();
        $this->assertFalse($banner->hide);
        $this->assertSame(['assets/frontend/ui.css', 'assets/cookieBannerWide/banner.css'], $banner->css);
        $this->assertSame(['assets/cookieBanner/banner.js', 'assets/cookieBannerWide/banner.js'], $banner->js);
        $banner = $this->banner(['include_css' => 0]);
        $banner->onRun();
        $this->assertSame([], $banner->css);
        $this->assertCount(2, $banner->js);
    }

    public function testUndecidedAndSelectionLoggingUseExistingNamespacedModels(): void
    {
        $settings = \JosephCrowell\GDPR\Models\GDPRSettings::instance();
        $settings->log_enabled = true;
        $settings->ignore_useragents = [];
        $banner = $this->banner();
        $this->assertTrue((bool) \JosephCrowell\GDPR\Models\GDPRSettings::get('log_enabled'));
        $banner->onRun();
        $this->assertSame(Log::UNDECIDED, DB::table('josephcrowell_gdpr_logs')->value('decision'));
        $this->request(['cookies' => ['2']]);
        $banner->onSubmit();
        $this->assertSame(Log::ACCEPTED, DB::table('josephcrowell_gdpr_logs')->value('decision'));
        $this->assertSame(1, DB::table('josephcrowell_gdpr_logs')->count());
    }

    public function testOnlyManagerPagesAreOfferedIncludingAliasedComponents(): void
    {
        $banner = $this->banner();
        $banner->pages = [
            new WideBannerPageFixture('home', 'Home', false),
            new WideBannerPageFixture('privacy', 'Privacy settings', true),
        ];
        $options = $banner->getCookie_manager_pageOptions();
        $this->assertArrayNotHasKey('home', $options);
        $this->assertSame('Privacy settings (privacy)', $options['privacy']);
        $this->assertArrayHasKey('', $options);
    }
}

class TestableWideBanner extends CookieBannerWide
{
    public $css = [];
    public $js = [];
    public $pages = [];
    public $rendered = [];

    public function addCss(array|string $name, array|string $attributes = []): void { $this->css[] = $name; }
    public function addJs(array|string $name, array|string $attributes = []): void { $this->js[] = $name; }
    public function renderPartial() { $this->rendered = func_get_args(); return '<p>Updated</p>'; }
    protected function getManagerPages(): array { return $this->pages; }

    public function attachPage($page): void
    {
        $this->controller = new class($page) {
            private $page;
            public function __construct($page) { $this->page = $page; }
            public function getPage() { return $this->page; }
        };
    }
}

class WideBannerPageFixture
{
    public $baseFileName;
    public $title;
    private $manager;

    public function __construct($name, $title, $manager)
    {
        $this->baseFileName = $name;
        $this->title = $title;
        $this->manager = $manager;
    }

    public function hasComponent($name)
    {
        return $this->manager && $name === CookieManager::class ? 'cookieManager preferences' : false;
    }
}
