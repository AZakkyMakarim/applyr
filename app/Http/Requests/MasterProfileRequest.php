<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MasterProfileRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'location' => ['nullable', 'string', 'max:255'],
            'professional_summary' => ['nullable', 'string', 'max:5000'],
            'links' => ['present', 'array'],
            'links.*' => ['array:label,url'],
            'links.*.label' => ['required', 'string', 'max:100'],
            'links.*.url' => ['required', 'string', 'url:http,https', 'max:2048'],
            'skills' => ['present', 'array'],
            'skills.*' => ['array:category,skills'],
            'skills.*.category' => ['required', 'string', 'max:100', 'distinct:ignore_case'],
            'skills.*.skills' => ['required', 'array', 'min:1'],
            'skills.*.skills.*' => ['required', 'string', 'max:100'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'show_photo' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'links.*.label' => 'link label',
            'links.*.url' => 'link URL',
            'skills.*.category' => 'skill category',
            'skills.*.skills' => 'skills',
            'skills.*.skills.*' => 'skill',
        ];
    }

    /**
     * Link and skill rows are keyed by whatever the form named them, so they are re-indexed,
     * and a row the user left completely blank is dropped rather than failing validation.
     * A category's skills arrive as comma-separated text; an already-split list works too.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'show_photo' => $this->boolean('show_photo'),
            'links' => $this->rows('links', fn (array $link) => [
                'label' => $this->trimmedText($link['label'] ?? null),
                'url' => $this->trimmedText($link['url'] ?? null),
            ]),
            'skills' => $this->rows('skills', fn (array $group) => [
                'category' => $this->trimmedText($group['category'] ?? null),
                'skills' => $this->skillList($group['skills'] ?? []),
            ]),
        ]);
    }

    /**
     * Normalizes each row, re-indexes, and drops rows whose every field is blank.
     *
     * @param  callable(array<string, mixed>): array<string, mixed>  $normalize
     */
    private function rows(string $key, callable $normalize): mixed
    {
        $rows = $this->input($key, []);

        if (! is_array($rows)) {
            return $rows;
        }

        $rows = array_map(fn ($row) => is_array($row) ? $normalize($row) : $row, $rows);

        return array_values(array_filter(
            $rows,
            fn ($row) => ! is_array($row) || array_filter($row, fn ($value) => $value !== '' && $value !== []) !== [],
        ));
    }

    private function skillList(mixed $skills): mixed
    {
        if (is_string($skills)) {
            $skills = explode(',', $skills);
        }

        if (! is_array($skills)) {
            return $skills;
        }

        return array_values(array_filter(array_map($this->trimmedText(...), $skills), fn ($skill) => $skill !== ''));
    }

    /**
     * Trims a string; a missing value becomes an empty string so blank-row detection sees it.
     */
    private function trimmedText(mixed $value): mixed
    {
        return is_string($value) ? trim($value) : ($value ?? '');
    }
}
