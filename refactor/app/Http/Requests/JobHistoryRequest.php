<?php

namespace DTApi\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class JobHistoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        
        return true; 
    }

    public function rules(): array
    {
        return [
            'user_id' => 'required|integer|exists:users,id',
        ];
    }

   
    public function messages(): array
    {
        return [
            'user_id.required' => 'User ID is required to fetch job history.',
            'user_id.integer'  => 'User ID must be a valid integer.',
            'user_id.exists'   => 'The specified User ID does not exist.',
        ];
    }
}
