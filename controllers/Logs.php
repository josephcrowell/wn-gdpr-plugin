<?php namespace JosephCrowell\GDPR\Controllers;

use Backend\Behaviors\ListController;
use Backend\Classes\Controller;
use BackendMenu;
use JosephCrowell\GDPR\Models\Log;
use DB;

class Logs extends Controller
{
    public $implement = [ListController::class];

    public $listConfig = 'config_list.yaml';

    public $requiredPermissions = [
        'josephcrowell.gdpr.manage_logs',
    ];

    public function __construct()
    {
        parent::__construct();
        BackendMenu::setContext('JosephCrowell.GDPR', 'main-menu-item');
    }

    public function index()
    {
        parent::index();
        $this->vars['stats'] = collect([
            'total' => Log::count(),
            'byState' => DB
                ::table('josephcrowell_gdpr_logs')
                ->select(
                    "decision as label",
                    DB::raw('count(session_id) as value')
                )
                ->groupBy("decision")
                ->get(),
        ]);
    }
}
