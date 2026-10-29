<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'terms_accepted' => ['required', 'accepted'],
            'sync_enabled' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Custom validation messages.
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Имя пользователя обязательно для заполнения.',
            'email.required' => 'Адрес электронной почты обязателен.',
            'email.email' => 'Некорректный формат адреса электронной почты.',
            'email.unique' => 'Пользователь с таким email уже зарегистрирован.',
            'password.required' => 'Пароль обязателен для заполнения.',
            'password.min' => 'Пароль должен содержать не менее 8 символов.',
            'password.confirmed' => 'Подтверждение пароля не совпадает.',
            'terms_accepted.required' => 'Необходимо подтвердить согласие на обработку персональных данных (152-ФЗ).',
            'terms_accepted.accepted' => 'Необходимо подтвердить согласие на обработку персональных данных (152-ФЗ).',
        ];
    }
}
