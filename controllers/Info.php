<?php namespace JosephCrowell\GDPR\Controllers;

use Backend\Behaviors\RelationController;
use Backend\Classes\Controller;
use BackendMenu;
use Flash;
use JosephCrowell\GDPR\Models\CookieGroup;
use System\Classes\SettingsManager;
use Backend\Behaviors\ListController;
use Backend\Behaviors\FormController;
use Backend\Behaviors\ReorderController;

class Info extends Controller
{
	
    public $requiredPermissions = [
        'josephcrowell.gdpr.manage_cookie_info',
    ];	

    public function __construct()
    {
        parent::__construct();
        BackendMenu::setContext('Winter.System', 'system', 'settings');
        SettingsManager::setContext('JosephCrowell.GDPR', 'gdpr_info');
        $this->addCss('/plugins/josephcrowell/gdpr/assets/backend/styles.css');
		$this->addCss('/plugins/josephcrowell/gdpr/assets/backend/prism.css');

        // Follow the backend skin's light/dark/auto selection, without a new switch.
        $this->addCss('/plugins/josephcrowell/gdpr/assets/backend/info.css');
		$this->addJs('/plugins/josephcrowell/gdpr/assets/backend/prism.js');
    }

    public function index()
    {
        $this->pageTitle = 'Info';
    }

    public function dataretention()
    {
        $this->pageTitle = 'Data retention';
    }

    public function bannerlaws()
    {
        $this->pageTitle = 'Banner laws';
    }

    public function cookieconsent()
    {
        $this->pageTitle = 'Cookie consent';
    }

    public function cookiesmodulesuserguide()
    {
        $this->pageTitle = 'Cookies and Modules User Guide';
    }
	
    public function cookiesmodulessetup()
    {
        $this->pageTitle = 'Cookie and Modules Setup';
    }	

    public function overallinfo()
    {
        $this->pageTitle = 'Information about GDPR and ePrivacy';
    }
	
    public function dpolinks()
    {
        $this->pageTitle = 'Contact Links for DPO of Most Common Cookies';
    }	
								
}
