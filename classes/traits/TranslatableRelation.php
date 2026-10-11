<?php

namespace JosephCrowell\GDPR\Classes\Traits;

use Illuminate\Support\Facades\DB;
use Winter\Translate\Models\Attribute;

/**
 * @see https://github.com/rainlab/translate-plugin/issues/209#issuecomment-362088300
 */
trait TranslatableRelation
{
    /**
     * This is a temporary fix until
     * https://github.com/rainlab/translate-plugin/issues/209
     * is resolved.
     */
    protected function setTranslatableFields()
    {
        if ( ! post('RLTranslate')) {
            return;
        }

        foreach (post('RLTranslate') as $key => $value) {
            $data = collect($value)->intersectByKeys(array_flip($this->translatable));

            $obj = Attribute::where('locale', $key)
                     ->where('model_id', $this->id)
                     ->where('model_type', $this->getMorphClass());

            if ($obj->count() > 0) {
                $obj->update(['attribute_data' => $data->toJson()]);
                continue;
            }

            Attribute::create([
                'locale'         => $key,
                'model_id'       => $this->id,
                'model_type'     => $this->getMorphClass(),
                'attribute_data' => $data->toJson(),
            ]);
        }
    }

    public function afterSave()
    {
        $this->setTranslatableFields();
    }
}
