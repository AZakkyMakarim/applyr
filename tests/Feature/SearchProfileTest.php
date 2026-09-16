<?php

namespace Tests\Feature;

use App\Enums\JobTypeFilter;
use App\Enums\PostDateRange;
use App\Enums\WorkArrangementFilter;
use App\Models\SearchProfile;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SearchProfileTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function validInput(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Backend roles in Jakarta',
            'keyword' => ['laravel', 'php developer'],
            'location' => 'Jakarta Selatan',
            'country_code' => 'ID',
            'min_experience_years' => 2,
            'max_experience_years' => 5,
            'post_date_range' => 'PAST_WEEK',
            'work_arrangement' => 'hybrid',
            'job_type' => 'full_time',
        ], $overrides);
    }

    public function test_a_created_search_profile_is_listed_as_active(): void
    {
        $this->post('/search-profiles', $this->validInput())
            ->assertRedirect('/search-profiles');

        $this->get('/search-profiles')
            ->assertOk()
            ->assertSeeInOrder(['Backend roles in Jakarta', 'laravel', 'php developer', 'Jakarta Selatan', 'Active']);
    }

    public function test_a_created_search_profile_stores_every_field(): void
    {
        $this->post('/search-profiles', $this->validInput());

        $searchProfile = SearchProfile::sole();
        $this->assertSame('Backend roles in Jakarta', $searchProfile->name);
        $this->assertTrue($searchProfile->is_active);
        $this->assertSame(['laravel', 'php developer'], $searchProfile->keyword);
        $this->assertSame('Jakarta Selatan', $searchProfile->location);
        $this->assertSame('ID', $searchProfile->country_code);
        $this->assertSame(2, $searchProfile->min_experience_years);
        $this->assertSame(5, $searchProfile->max_experience_years);
        $this->assertSame(PostDateRange::PastWeek, $searchProfile->post_date_range);
        $this->assertSame(WorkArrangementFilter::Hybrid, $searchProfile->work_arrangement);
        $this->assertSame(JobTypeFilter::FullTime, $searchProfile->job_type);
    }

    public function test_keywords_submitted_one_per_line_are_stored_as_a_list(): void
    {
        $this->post('/search-profiles', $this->validInput(['keyword' => "laravel\r\n  php developer \n\n"]))
            ->assertSessionHasNoErrors();

        $this->assertSame(['laravel', 'php developer'], SearchProfile::sole()->keyword);
    }

    public function test_experience_years_are_optional(): void
    {
        $this->post('/search-profiles', $this->validInput([
            'min_experience_years' => null,
            'max_experience_years' => null,
            'location' => null,
        ]))->assertSessionHasNoErrors();

        $searchProfile = SearchProfile::sole();
        $this->assertNull($searchProfile->min_experience_years);
        $this->assertNull($searchProfile->max_experience_years);
    }

    public function test_max_experience_may_be_set_without_min(): void
    {
        $this->post('/search-profiles', $this->validInput(['min_experience_years' => null, 'max_experience_years' => 3]))
            ->assertSessionHasNoErrors();

        $this->assertSame(3, SearchProfile::sole()->max_experience_years);
    }

    public function test_min_experience_equal_to_max_is_allowed(): void
    {
        $this->post('/search-profiles', $this->validInput(['min_experience_years' => 3, 'max_experience_years' => 3]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('search_profiles', 1);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidInputs(): array
    {
        return [
            'missing name' => [['name' => ''], 'name'],
            'missing keywords' => [['keyword' => []], 'keyword'],
            'blank keyword text' => [['keyword' => " \n "], 'keyword'],
            'missing country code' => [['country_code' => ''], 'country_code'],
            'missing post date range' => [['post_date_range' => ''], 'post_date_range'],
            'unknown post date range' => [['post_date_range' => 'PAST_YEAR'], 'post_date_range'],
            'unknown work arrangement' => [['work_arrangement' => 'office'], 'work_arrangement'],
            'unknown job type' => [['job_type' => 'freelance'], 'job_type'],
            'negative min experience' => [['min_experience_years' => -1], 'min_experience_years'],
            'non-numeric max experience' => [['max_experience_years' => 'lots'], 'max_experience_years'],
            'min experience above max' => [['min_experience_years' => 6, 'max_experience_years' => 5], 'max_experience_years'],
        ];
    }

    #[DataProvider('invalidInputs')]
    public function test_creating_with_invalid_input_is_rejected(array $overrides, string $field): void
    {
        $this->from('/search-profiles/create')
            ->post('/search-profiles', $this->validInput($overrides))
            ->assertRedirect('/search-profiles/create')
            ->assertSessionHasErrors($field);

        $this->assertDatabaseCount('search_profiles', 0);
    }

    #[DataProvider('invalidInputs')]
    public function test_updating_with_invalid_input_is_rejected(array $overrides, string $field): void
    {
        $searchProfile = SearchProfile::factory()->create(['name' => 'Original']);

        $this->put("/search-profiles/{$searchProfile->id}", $this->validInput($overrides))
            ->assertSessionHasErrors($field);

        $this->assertSame('Original', $searchProfile->fresh()->name);
    }

    public function test_the_create_form_renders_every_choice(): void
    {
        $this->get('/search-profiles/create')
            ->assertOk()
            ->assertSee('name="name"', false)
            ->assertSee('name="keyword"', false)
            ->assertSee('value="PAST_24_HOURS"', false)
            ->assertSee('value="ANY_TIME"', false)
            ->assertSee('value="onsite"', false)
            ->assertSee('value="internship"', false);
    }

    public function test_the_edit_form_is_prefilled(): void
    {
        $searchProfile = SearchProfile::factory()->create([
            'name' => 'Data roles',
            'keyword' => ['data engineer', 'analytics'],
        ]);

        $this->get("/search-profiles/{$searchProfile->id}/edit")
            ->assertOk()
            ->assertSee('value="Data roles"', false)
            ->assertSee("data engineer\nanalytics", false);
    }

    public function test_a_search_profile_can_be_edited(): void
    {
        $searchProfile = SearchProfile::factory()->create();

        $this->put("/search-profiles/{$searchProfile->id}", $this->validInput([
            'name' => 'Remote contract work',
            'keyword' => ['golang'],
            'work_arrangement' => 'remote',
            'job_type' => 'contract',
            'post_date_range' => 'ANY_TIME',
        ]))->assertRedirect('/search-profiles');

        $searchProfile->refresh();
        $this->assertSame('Remote contract work', $searchProfile->name);
        $this->assertSame(['golang'], $searchProfile->keyword);
        $this->assertSame(WorkArrangementFilter::Remote, $searchProfile->work_arrangement);
        $this->assertSame(JobTypeFilter::Contract, $searchProfile->job_type);
        $this->assertSame(PostDateRange::AnyTime, $searchProfile->post_date_range);
    }

    public function test_editing_does_not_resume_a_paused_search_profile(): void
    {
        $searchProfile = SearchProfile::factory()->paused()->create();

        $this->put("/search-profiles/{$searchProfile->id}", $this->validInput());

        $this->assertFalse($searchProfile->fresh()->is_active);
    }

    public function test_a_search_profile_can_be_deleted(): void
    {
        $searchProfile = SearchProfile::factory()->create(['name' => 'Short-lived']);

        $this->delete("/search-profiles/{$searchProfile->id}")
            ->assertRedirect('/search-profiles');

        $this->assertModelMissing($searchProfile);
        $this->get('/search-profiles')->assertSee('No SearchProfiles yet');
    }

    public function test_a_search_profile_can_be_paused_and_resumed_without_losing_it(): void
    {
        $searchProfile = SearchProfile::factory()->create(['name' => 'Toggle me']);

        $this->patch("/search-profiles/{$searchProfile->id}/pause")
            ->assertRedirect('/search-profiles');
        $this->assertFalse($searchProfile->fresh()->is_active);
        $this->get('/search-profiles')->assertSeeInOrder(['Toggle me', 'Paused', 'Resume']);

        $this->patch("/search-profiles/{$searchProfile->id}/resume")
            ->assertRedirect('/search-profiles');
        $this->assertTrue($searchProfile->fresh()->is_active);
        $this->get('/search-profiles')->assertSeeInOrder(['Toggle me', 'Active', 'Pause']);

        $this->assertDatabaseCount('search_profiles', 1);
    }

    public function test_pausing_one_search_profile_leaves_the_others_active(): void
    {
        [$first, $second, $third] = SearchProfile::factory()->count(3)->create();

        $this->patch("/search-profiles/{$second->id}/pause");

        $this->assertTrue($first->fresh()->is_active);
        $this->assertFalse($second->fresh()->is_active);
        $this->assertTrue($third->fresh()->is_active);
    }

    public function test_the_list_shows_every_search_profile_with_its_state(): void
    {
        SearchProfile::factory()->create(['name' => 'Alpha search']);
        SearchProfile::factory()->paused()->create(['name' => 'Beta search']);

        $this->get('/search-profiles')
            ->assertOk()
            ->assertSeeInOrder(['Alpha search', 'Active', 'Beta search', 'Paused']);
    }

    public function test_the_dashboard_nav_links_to_search_profiles(): void
    {
        $this->get('/')->assertSee('href="'.route('search-profiles.index').'"', false);
    }

    public function test_actions_on_a_missing_search_profile_return_not_found(): void
    {
        $this->get('/search-profiles/999/edit')->assertNotFound();
        $this->put('/search-profiles/999', $this->validInput())->assertNotFound();
        $this->delete('/search-profiles/999')->assertNotFound();
        $this->patch('/search-profiles/999/pause')->assertNotFound();
        $this->patch('/search-profiles/999/resume')->assertNotFound();
    }
}
