<?php

namespace App\Http\Requests\HolidayRequests;

use Illuminate\Foundation\Http\FormRequest;

class StoreHolidayRequest extends FormRequest
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
     * @return array<string, mixed>
     */
    public function rules()
    {
        return [
            'name' => ['required'],
            'from' => ['required'],
            'to' => ['required'],
        ];
    }

    public function messages()
    {
        return [
            'name.required' => __("The name is required"),
            'from.required' => __("The date is required"),
            'to.required' => __("The date is required"),
        ];
    }
}
