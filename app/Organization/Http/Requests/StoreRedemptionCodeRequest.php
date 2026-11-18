<?php

namespace App\Organization\Http\Requests;

use App\Organization\Enums\RedemptionCodeType;
use App\Organization\Http\Requests\Concerns\HasOrganizationValidationMessages;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRedemptionCodeRequest extends FormRequest
{
    use HasOrganizationValidationMessages;

    public function authorize(): bool
    {
        return true;
    }

    protected function enumFields(): array
    {
        return [
            'code_type' => RedemptionCodeType::class,
        ];
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'code_type' => ['required', Rule::enum(RedemptionCodeType::class)],
            'max_uses' => ['nullable', 'integer', 'min:1'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after:starts_at'],
        ];
    }
}
