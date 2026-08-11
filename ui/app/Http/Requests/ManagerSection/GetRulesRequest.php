<?php

namespace App\Http\Requests\ManagerSection;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GetRulesRequest extends FormRequest
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

            'length'         => ['required','string'],
            'start'          => ['required','string'],
            'order.0.column' => ['string'],
            'order.0.dir'    => [
                'string',
                Rule::in(['asc', 'desc']),
            ],
        ];
    }

}
