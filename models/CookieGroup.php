<?php namespace JosephCrowell\GDPR\Models;

use Model;
use Winter\Storm\Database\Traits\Sluggable;
use Winter\Storm\Database\Traits\Sortable;
use JosephCrowell\GDPR\Classes\Traits\SortableRelation;

class CookieGroup extends Model
{
    use \Winter\Storm\Database\Traits\Validation;
    use Sluggable;
    use Sortable;
    use SortableRelation;

    public $table = 'josephcrowell_gdpr_cookie_groups';
    public $implement = ['@Winter.Translate.Behaviors.TranslatableModel'];
    public $translatable = ['name', 'description'];
    public $fillable = [
        'sort_order',
        'slug',
        'name',
        'description',
        'required',
    ];
    public $slugs = [
        'slug' => 'name',
    ];
    public $rules = [
        'name' => 'required',
        'description' => 'required',
    ];
    public $hasMany = [
        'cookies' => [
            Cookie::class,
            'key'      => 'cookie_group_id',
            'otherKey' => 'id',
            'order'    => 'sort_order',
            'table'    => 'josephcrowell_gdpr_cookies',
        ],
    ];

    public function getMorphClass()
    {
        // Preserve the model type used by existing translated cookie groups.
        return 'OFFLINE\\GDPR\\Models\\CookieGroup';
    }
}
