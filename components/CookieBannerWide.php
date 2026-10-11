<?php namespace JosephCrowell\GDPR\Components;

use Cms\Classes\Page;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Response;
use Winter\Storm\Exception\ValidationException;

class CookieBannerWide extends CookieBanner
{
    public const COLOR_SCHEMES = [
        'blue', 'red', 'orange', 'amber', 'yellow', 'lime', 'green', 'emerald',
        'teal', 'cyan', 'sky', 'indigo', 'violet', 'purple', 'fuchsia', 'pink', 'rose',
    ];

    public $bgCookies = false;
    public $colorScheme = 'blue';
    public $selectedGroupIds = [];

    public function componentDetails()
    {
        return [
            'name' => 'josephcrowell.gdpr::lang.components.bannerwide.name',
            'description' => 'josephcrowell.gdpr::lang.components.bannerwide.description',
        ];
    }

    public function defineProperties()
    {
        return parent::defineProperties() + [
            'bg_cookie' => [
                'title' => 'josephcrowell.gdpr::lang.components.bannerwide.bg_cookie.title',
                'description' => 'josephcrowell.gdpr::lang.components.bannerwide.bg_cookie.description',
                'default' => 0,
                'type' => 'checkbox',
            ],
            'color_scheme' => [
                'title' => 'josephcrowell.gdpr::lang.components.bannerwide.color_scheme.title',
                'description' => 'josephcrowell.gdpr::lang.components.bannerwide.color_scheme.description',
                'default' => 'blue',
                'type' => 'dropdown',
            ],
        ];
    }

    public function init()
    {
        parent::init();
        $this->bgCookies = filter_var($this->property('bg_cookie', false), FILTER_VALIDATE_BOOLEAN);
        $scheme = $this->property('color_scheme', 'blue');
        $this->colorScheme = in_array($scheme, self::COLOR_SCHEMES, true) ? $scheme : 'blue';
        $this->selectedGroupIds = $this->getInitiallySelectedGroupIds();
    }

    public function onRun()
    {
        if ($this->hasCookieManagerOnPage()) {
            $this->hide = true;
            return;
        }

        // Reuse consent detection and logging, including all of their imports.
        parent::onRun();
    }

    protected function loadAssets()
    {
        if ($this->property('include_css')) {
            $this->addCss('assets/frontend/ui.css');
            $this->addCss('assets/cookieBannerWide/banner.css');
        }
        $this->addJs('assets/cookieBanner/banner.js');
        $this->addJs('assets/cookieBannerWide/banner.js');
    }

    public function onSubmit()
    {
        $groupIds = $this->normalizeGroupIds(Request::input('cookies', []));
        $cookies = $this->getSelectionForGroups($groupIds);

        // ConsentCookie always adds required cookies, even if the client omits them.
        $cookie = $this->consentCookie->set($cookies);
        $response = Response::make([]);
        if ($this->updateSelector && $this->updatePartial) {
            $content = $this->renderPartial($this->updatePartial, [
                'cookieGroups' => $this->cookieGroups,
                'consentCookie' => $this->consentCookie,
            ]);
            $response = Response::make([
                $this->updateSelector => $content,
                'content' => $content,
                'consentCookie' => $this->consentCookie->get(),
            ]);
        }

        return $response->withCookie($cookie);
    }

    protected function normalizeGroupIds($submitted): array
    {
        if (!is_array($submitted)) {
            throw new ValidationException(['cookies' => trans('josephcrowell.gdpr::lang.components.bannerwide.invalid_selection')]);
        }
        $ids = [];
        foreach ($submitted as $id) {
            if (
                (!is_int($id) && !is_string($id))
                || !ctype_digit((string) $id)
                || (int) $id < 1
                || (string) (int) $id !== ltrim((string) $id, '0')
            ) {
                throw new ValidationException(['cookies' => trans('josephcrowell.gdpr::lang.components.bannerwide.invalid_selection')]);
            }
            $ids[] = (int) $id;
        }

        return array_values(array_unique($ids));
    }

    protected function getCookieGroups()
    {
        return $this->cookieGroups ?? parent::getCookieGroups();
    }

    protected function getSelectionForGroups(array $groupIds): array
    {
        return $this->getCookieGroups()->filter(function ($group) use ($groupIds) {
            return in_array((int) $group->id, $groupIds, true);
        })->flatMap(function ($group) {
            return $group->cookies;
        })->filter(function ($cookie) {
            return (bool) $cookie->initial_status;
        })->pluck('max_level', 'code')->toArray();
    }

    protected function getInitiallySelectedGroupIds(): array
    {
        return $this->getCookieGroups()->filter(function ($group) {
            return $group->required || $group->cookies->contains(function ($cookie) {
                return (bool) $cookie->initial_status;
            });
        })->pluck('id')->map(function ($id) {
            return (int) $id;
        })->values()->all();
    }

    protected function hasCookieManagerOnPage(): bool
    {
        if (!isset($this->controller)) {
            return false;
        }
        $page = $this->controller->getPage();

        return $page && $page->hasComponent(CookieManager::class) !== false;
    }

    protected function getManagerPages(): array
    {
        return Page::sortBy('baseFileName')->all();
    }

    public function getCookie_manager_pageOptions(): array
    {
        $options = ['' => '-- ' . trans('josephcrowell.gdpr::lang.cookie_banner.cookie_manager_page.empty') . ' --'];
        foreach ($this->getManagerPages() as $page) {
            if ($page->hasComponent(CookieManager::class) !== false) {
                $options[$page->baseFileName] = $page->title . ' (' . $page->baseFileName . ')';
            }
        }

        return $options;
    }

    public function getColor_SchemeOptions(): array
    {
        $options = [];
        foreach (self::COLOR_SCHEMES as $scheme) {
            $options[$scheme] = trans('josephcrowell.gdpr::lang.components.bannerwide.color_scheme.colors.' . $scheme);
        }

        return $options;
    }
}
