<?php

namespace App\Http\Requests;

use App\Enums\JobTypeFilter;
use App\Enums\PostDateRange;
use App\Enums\WorkArrangementFilter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SearchProfileRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'keyword' => ['required', 'array', 'min:1'],
            'keyword.*' => ['required', 'string', 'max:255', 'distinct:ignore_case'],
            'location' => ['nullable', 'string', 'max:255'],
            'country_code' => ['required', 'string', 'max:10'],
            'min_experience_years' => ['nullable', 'integer', 'min:0', 'max:60'],
            'max_experience_years' => ['nullable', 'integer', 'min:0', 'max:60', Rule::when($this->filled('min_experience_years'), 'gte:min_experience_years')],
            'post_date_range' => ['required', Rule::enum(PostDateRange::class)],
            'work_arrangement' => ['required', Rule::enum(WorkArrangementFilter::class)],
            'job_type' => ['required', Rule::enum(JobTypeFilter::class)],
        ];
    }

    /**
     * The form submits keywords as one-per-line text; an already-split array is accepted too.
     * Either way, blank entries are dropped and the rest trimmed.
     */
    protected function prepareForValidation(): void
    {
        $keyword = $this->input('keyword');

        if (is_string($keyword)) {
            $keyword = preg_split('/\R/', $keyword);
        }

        if (is_array($keyword)) {
            $keyword = array_values(array_filter(
                array_map(fn ($value) => is_string($value) ? trim($value) : $value, $keyword),
                fn ($value) => $value !== '' && $value !== null,
            ));
        }

        $this->merge(['keyword' => $keyword]);
    }
}
