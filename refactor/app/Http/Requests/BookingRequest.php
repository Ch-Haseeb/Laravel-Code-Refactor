<?php

namespace DTApi\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BookingRequest extends FormRequest
{
    public function authorize()
    {
     
        return true;
    }

    public function rules()
    {
        return [
            'user_id' => 'nullable|integer|exists:users,id', 
        ];
    }
}
