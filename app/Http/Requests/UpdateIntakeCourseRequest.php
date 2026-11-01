<?php

namespace App\Http\Requests;

use App\Models\IntakeCourse;
use Illuminate\Foundation\Http\FormRequest;

class UpdateIntakeCourseRequest extends FormRequest
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
        $startDateRule = 'after_or_equal:start_date';
        if (! $this->has('start_date')) {
            $course = $this->route('intakeCourse') ?? $this->route('intake_course');
            if ($course instanceof IntakeCourse && $course->start_date) {
                $startDateRule = 'after_or_equal:' . $course->start_date->toDateString();
            }
        }

        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'dosage_quantity' => ['sometimes', 'numeric', 'min:0.01'],
            'dosage_instruction' => ['sometimes', 'string', 'max:255'],
            'start_date' => ['sometimes', 'date'],
            'end_date' => ['nullable', 'date', $startDateRule],
            'notes' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Custom messages for validation errors.
     */
    public function messages(): array
    {
        return [
            'end_date.after_or_equal' => 'Дата окончания курса не может быть раньше даты начала.',
            'dosage_quantity.min' => 'Дозировка должна быть не менее 0.01.',
        ];
    }
}
