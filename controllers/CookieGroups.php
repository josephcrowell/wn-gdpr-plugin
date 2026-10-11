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

class CookieGroups extends Controller
{
    public $implement = [
        ListController::class,
        FormController::class,
        ReorderController::class,
        RelationController::class
    ];

    public $listConfig = 'config_list.yaml';
    public $formConfig = 'config_form.yaml';
    public $reorderConfig = 'config_reorder.yaml';
    public $relationConfig = 'config_relation.yaml';

    public $requiredPermissions = [
        'josephcrowell.gdpr.manage_cookie_groups',
    ];

    public function __construct()
    {
        parent::__construct();
        BackendMenu::setContext('Winter.System', 'system', 'settings');
        SettingsManager::setContext('JosephCrowell.GDPR', 'gdpr_cookies');
        $this->addCss('/plugins/josephcrowell/gdpr/assets/backend/styles.css');
        $this->addJs('/plugins/josephcrowell/gdpr/assets/backend/sortable.js');
        $this->addJs('/plugins/josephcrowell/gdpr/assets/backend/backend.js');
    }

    public function onReorderRelation()
    {
        $records = request()->input('rcd');
        $model   = CookieGroup::findOrFail($this->params[0]);

        $model->setRelationOrder('cookies', $records, range(1, count($records)));

        Flash::success(trans('josephcrowell.gdpr::lang.common.sorted'));
    }
}
