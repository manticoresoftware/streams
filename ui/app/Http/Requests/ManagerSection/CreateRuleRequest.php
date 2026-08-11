<?php

namespace App\Http\Requests\ManagerSection;

use Illuminate\Foundation\Http\FormRequest;

class CreateRuleRequest extends FormRequest
{

    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [

            'rule_text'         => ['required_without_all:rule_text,rule_filters', 'string', 'nullable'],
            'rule_filters'      => ['required_without_all:rule_text,rule_filters', 'string', 'nullable'],
            'rule_highlighting' => ['nullable'],
            'rule_id'           => ['numeric'],
            'rule_tags'         => ['string', 'nullable'],
            'rule_external'     => ['string', 'nullable'],
            'duplication_check' => ['nullable'],
        ];
    }

}
