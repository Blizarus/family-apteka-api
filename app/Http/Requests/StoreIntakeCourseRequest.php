<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreIntakeCourseRequest extends FormRequest
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
            'profile_id' => ['required', 'integer', 'exists:profiles,id'],
            'medicine_id' => ['required', 'integer', 'exists:medicines,id'],
            'title' => ['nullable', 'string', 'max:255'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'times_per_day' => ['nullable', 'integer', 'min:1', 'max:10'],
            'time_slots' => ['nullable', 'array', 'min:1'],
            'time_slots.*' => ['string', 'regex:/^\d{1,2}:\d{2}$/'],
            'times' => ['nullable', 'array', 'min:1'],
            'times.*' => ['string', 'regex:/^\d{1,2}:\d{2}$/'],
            'dosage_quantity' => ['required', 'numeric', 'min:0.001'],
            'rule' => ['nullable', 'string', 'max:255'],
            'dosage_instruction' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Custom messages for validation errors.
     */
    public function messages(): array
    {
        return [
            'profile_id.required' => 'Необходимо указать профиль члена семьи.',
            'profile_id.exists' => 'Указанный профиль не существует.',
            'medicine_id.required' => 'Необходимо выбрать препарат из аптечки.',
            'medicine_id.exists' => 'Выбранный препарат не найден в аптечке.',
            'start_date.required' => 'Дата начала курса обязательна.',
            'end_date.after_or_equal' => 'Дата окончания курса не может быть раньше даты начала.',
            'dosage_quantity.required' => 'Дозировка препарата обязательна для заполнения.',
            'dosage_quantity.min' => 'Дозировка должна быть больше нуля.',
            'time_slots.*.regex' => 'Время приёма должно быть в формате ЧЧ:ММ (например, 08:00).',
            'times.*.regex' => 'Время приёма должно быть в формате ЧЧ:ММ (например, 08:00).',
        ];
    }
}
