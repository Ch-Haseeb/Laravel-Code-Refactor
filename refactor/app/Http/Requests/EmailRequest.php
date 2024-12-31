<?php

namespace DTApi\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EmailRequest extends FormRequest
{
    public function authorize()
    {
     
        return true;
    }

    public function rules()
    {
        return [
         
        ];
    }
}
