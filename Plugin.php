<?php

namespace JosephCrowell\GDPR;

use Backend\Facades\Backend;
use Event;
use JosephCrowell\GDPR\Classes\Cookies\ConsentCookie;
use JosephCrowell\GDPR\Components\ConsentManager;
use JosephCrowell\GDPR\Components\CookieBanner;
use JosephCrowell\GDPR\Components\CookieBannerWide;
use JosephCrowell\GDPR\Components\CookieManager;
use JosephCrowell\GDPR\Console\CleanUp;
use JosephCrowell\GDPR\Console\ImportPresets;
use JosephCrowell\GDPR\Models\CookieConsentSettings;
use JosephCrowell\GDPR\Models\DataRetentionSettings;
use JosephCrowell\GDPR\Models\GDPRSettings;
use JosephCrowell\GDPR\Models\Log;
use System\Classes\PluginBase;
use Winter\Translate\Models\Attribute;

class Plugin extends PluginBase
{
    const EVENT_CLEANUP_REGISTER = 'josephcrowell.gdpr::cleanup.register';
    // Keep registrations from plugins that still use the upstream event.
    const EVENT_CLEANUP_REGISTER_LEGACY = 'offline.gdpr::cleanup.register';

    public function register()
    {
        // Explicit config-file keys need the legacy loader hint as well as an alias.
        $this->app['config']->package('offline.gdpr', __DIR__ . '/config');
        $this->registerConsoleCommand('gdpr.cleanup', CleanUp::class);
        $this->registerConsoleCommand('gdpr.import', ImportPresets::class);
    }

    public function registerSchedule($schedule)
    {
        $schedule->command('gdpr:cleanup')->daily();
    }

    public function registerComponents()
    {
        return [
            ConsentManager::class => 'consentManager',
            CookieManager::class => 'cookieManager',
            CookieBanner::class => 'cookieBanner',
            CookieBannerWide::class => 'cookieBannerWide',
        ];
    }

    public function registerMarkupTags()
    {
        return [
            'functions' => [
                'gdprCookieAllowed' => function ($code, $level = 0) {
                    return (new ConsentCookie())->isAllowed($code, $level);
                },
                'gdprAllowedCookieLevel' => function ($code) {
                    return (new ConsentCookie())->allowedCookieLevel($code);
                },
                'gdprIsUndecided' => function () {
                    return (new ConsentCookie())->isUndecided();
                },
                'gdprHasAnyOptional' => function () {
                    return (new ConsentCookie())->hasAnyOptional();
                },
            ],
        ];
    }

    public function boot()
    {
        \Event::listen('backend.menu.extendItems', function ($manager) {
            if ((bool) GDPRSettings::get('log_enabled') !== true) {
                $manager->removeMainMenuItem('JosephCrowell.GDPR', 'main-menu-item');
            }
        });
        \Event::listen('josephcrowell.gdpr::cleanup.register', function () {
            return [
                'id' => 'josephcrowell-gdpr-logs',
                'label' => trans('josephcrowell.gdpr::lang.plugin.name'),
                'models' => [
                    [
                        'label' => trans('josephcrowell.gdpr::lang.log.log'),
                        'comment' => trans('josephcrowell.gdpr::lang.log.log_comment'),
                        // This class name is also the key for saved retention settings.
                        'class' => 'OFFLINE\\GDPR\\Models\\Log',
                    ],
                ],
            ];
        });
        if (class_exists(\Winter\Translate\Models\Attribute::class)) {
            Attribute::extend(function ($model) {
                $model->addFillable([
                    'locale',
                    'model_id',
                    'model_type',
                    'attribute_data',
                ]);
                $model->timestamps = false;
            });
        }
    }

    public function registerSettings()
    {
        return [
            'gdpr_info' => [
                'label' => trans('josephcrowell.gdpr::lang.settings.info.label'),
                'description' => trans('josephcrowell.gdpr::lang.settings.info.description'),
                'category' => 'GDPR and ePrivacy',
                'icon' => 'icon-info-circle',
                'url' => Backend::url('josephcrowell/gdpr/info'),
                'order' => 200,
                'keywords' => 'gdpr',
                'permissions' => ['josephcrowell.gdpr.manage_cookie_info'],
            ],
            'gdpr_cookies' => [
                'label' => trans('josephcrowell.gdpr::lang.settings.cookies.label'),
                'description' => trans('josephcrowell.gdpr::lang.settings.cookies.description'),
                'category' => 'GDPR and ePrivacy',
                'icon' => 'wn-icon-list',
                'url' => Backend::url('josephcrowell/gdpr/cookiegroups'),
                'order' => 210,
                'keywords' => 'gdpr',
                'permissions' => ['josephcrowell.gdpr.manage_cookie_groups'],
            ],
            'gdpr_settings' => [
                'label' => trans('josephcrowell.gdpr::lang.settings.general.label'),
                'description' => trans('josephcrowell.gdpr::lang.settings.general.description'),
                'category' => 'GDPR and ePrivacy',
                'icon' => 'wn-icon-gear',
                'class' => GDPRSettings::class,
                'order' => 210,
                'keywords' => 'gdpr',
                'permissions' => ['josephcrowell.gdpr.manage_logs'],
            ],
            'gdpr_data_retention' => [
                'label' => trans('josephcrowell.gdpr::lang.settings.data_retention.label'),
                'description' => trans('josephcrowell.gdpr::lang.settings.data_retention.description'),
                'category' => 'GDPR and ePrivacy',
                'icon' => 'wn-icon-trash',
                'class' => DataRetentionSettings::class,
                'order' => 220,
                'keywords' => 'gdpr',
                'permissions' => ['josephcrowell.gdpr.manage_data_retention'],
            ],
        ];
    }
}
