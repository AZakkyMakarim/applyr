<?php

namespace App\Http\Requests;

class ProjectRequest extends MasterProfileEntryRequest
{
    protected function section(): string
    {
        return 'projects';
    }

    /**
     * A Project may be undated; once it has a start date the usual end date rules apply.
     */
    protected function requiresStartDate(): bool
    {
        return false;
    }

    /**
     * @return array<string, mixed>
     */
    protected function fieldRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'tech_stack' => ['present', 'array'],
            'tech_stack.*' => ['string', 'max:100'],
            'link' => ['nullable', 'string', 'url:http,https', 'max:2048'],
            'description' => ['nullable', 'string', 'max:5000'],
            'achievements' => ['present', 'array'],
            'achievements.*' => ['string', 'max:1000'],
        ];
    }

    /**
     * Tech stack is typed comma-separated, achievements one per line.
     *
     * @return array<string, mixed>
     */
    protected function listFields(): array
    {
        return [
            'tech_stack' => $this->commaSeparated('tech_stack'),
            'achievements' => $this->lines('achievements'),
        ];
    }
}
