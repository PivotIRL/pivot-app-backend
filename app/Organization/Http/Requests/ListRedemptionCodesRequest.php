<?php

namespace App\Organization\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ListRedemptionCodesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['per_page' => ['sometimes', 'integer', 'between:1,100']];
    }

    public function perPage(): int
    {
        return $this->integer('per_page', 25);
    }
}
