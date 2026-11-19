<?php

namespace App\Modules\User\Requests\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Super admins change their password directly; others confirm with an emailed code.
            'code' => [Rule::requiredIf(fn () => !$this->user()?->hasRole('admin')), 'nullable', 'digits:6'],
            'new_password' => ['required', 'string', 'min:8'],
            'new_password_confirmation' => ['required', 'same:new_password'],
        ];
    }
}

