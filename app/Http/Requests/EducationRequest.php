<?php

namespace App\Http\Requests;

class EducationRequest extends MasterProfileEntryRequest
{
    protected function section(): string
    {
        return 'educations';
    }

    /**
     * @return array<string, mixed>
     */
    protected function fieldRules(): array
    {
        return [
            'institution' => ['required', 'string', 'max:255'],
            'degree' => ['nullable', 'string', 'max:255'],
            'field_of_study' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
