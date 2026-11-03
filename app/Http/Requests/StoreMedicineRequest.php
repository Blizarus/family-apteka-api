<?php

namespace App\Http\Requests;

use App\MedicineQuantityUnit;
use App\StorageCondition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class StoreMedicineRequest extends FormRequest
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
            'medicine_box_id' => ['required', 'integer', 'exists:medicine_boxes,id'],
            'reference_medicine_id' => [
                'required_without:custom_trade_name',
                'nullable',
                'integer',
                'exists:reference_medicines,id',
            ],
            // Строгая проверка против инъекций и спецсимволов: допустимы только буквы, цифры, дефис, точка, скобки
            'custom_trade_name' => [
                'required_without:reference_medicine_id',
                'nullable',
                'string',
                'min:2',
                'max:255',
                'regex:/^[\pL\pN\s\-_.,()]+$/u',
            ],
            'barcode' => ['nullable', 'string', 'max:64', 'regex:/^[0-9A-Za-z]+$/'],
            'quantity' => ['required', 'numeric', 'min:0.001', 'max:999999.999'],
            'quantity_unit' => ['required', new Enum(MedicineQuantityUnit::class)],
            'expiration_date' => ['required', 'date', 'after:today'],
            'opened_at' => ['nullable', 'date', 'before_or_equal:today'],
            'days_after_opening' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'low_stock_threshold' => ['nullable', 'numeric', 'min:0'],
            'storage_condition' => ['nullable', new Enum(StorageCondition::class)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Custom error messages for failed validation.
     */
    public function messages(): array
    {
        return [
            'medicine_box_id.required' => 'Идентификатор аптечки обязателен для заполнения.',
            'custom_trade_name.required_without' => 'Укажите наименование препарата или выберите его из справочника.',
            'custom_trade_name.min' => 'Наименование препарата должно содержать не менее 2 символов.',
            'reference_medicine_id.required_without' => 'Выберите препарат из справочника или укажите наименование вручную.',
            'custom_trade_name.regex' => 'Наименование препарата содержит недопустимые спецсимволы (защита от инъекций).',
            'barcode.regex' => 'Штрихкод может содержать только латинские буквы и цифры.',
            'quantity.required' => 'Количество препарата обязательно.',
            'quantity.min' => 'Количество препарата должно быть больше нуля.',
            'quantity_unit.required' => 'Единица измерения обязательна.',
            'expiration_date.required' => 'Срок годности обязателен.',
            'expiration_date.after' => 'Срок годности должен быть позже текущей даты.',
        ];
    }
}
