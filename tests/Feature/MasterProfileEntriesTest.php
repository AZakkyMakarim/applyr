<?php

namespace Tests\Feature;

use App\Enums\EmploymentType;
use App\Models\Education;
use App\Models\Experience;
use App\Models\MasterProfile;
use App\Models\Project;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MasterProfileEntriesTest extends TestCase
{
    private MasterProfile $masterProfile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->masterProfile = MasterProfile::create([
            'full_name' => 'Ahmad Zakky',
            'email' => 'zakky@example.com',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function experienceInput(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Backend Engineer',
            'company' => 'Acme',
            'location' => 'Jakarta, Indonesia',
            'start_date' => '2021-03',
            'end_date' => '2023-08',
            'is_current' => '0',
            'employment_type' => 'full_time',
            'description' => 'Built the payments platform.',
            'achievements' => "Cut checkout latency by 40%\n\n  Led the Laravel 11 upgrade  \n",
            'skills_used' => 'PHP, Laravel , MySQL,',
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private static function educationInput(array $overrides = []): array
    {
        return array_merge([
            'institution' => 'Universitas Indonesia',
            'degree' => 'Bachelor of Computer Science',
            'field_of_study' => 'Computer Science',
            'start_date' => '2015-08',
            'end_date' => '2019-07',
            'is_current' => '0',
            'description' => 'Thesis on distributed queues.',
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private static function projectInput(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Applyr',
            'start_date' => '2026-01',
            'end_date' => '2026-06',
            'is_current' => '0',
            'tech_stack' => 'Laravel, Tailwind',
            'link' => 'https://github.com/zakky/applyr',
            'description' => 'Job-application assistant.',
            'achievements' => "Polls two job boards\nTailors CVs with AI",
        ], $overrides);
    }

    /**
     * Each entry type: its URL segment, model, input builder and the field that names it on the page.
     *
     * @return array<string, array{string, class-string<Model>, string, string}>
     */
    public static function entryTypes(): array
    {
        return [
            'experience' => ['experiences', Experience::class, 'experienceInput', 'title'],
            'education' => ['educations', Education::class, 'educationInput', 'institution'],
            'project' => ['projects', Project::class, 'projectInput', 'name'],
        ];
    }

    private function input(string $builder, array $overrides = []): array
    {
        return self::$builder($overrides);
    }

    public function test_an_experience_is_added_with_month_dates_and_listed_on_the_page(): void
    {
        $this->post('/master-profile/experiences', self::experienceInput())
            ->assertSessionHasNoErrors()
            ->assertRedirect('/master-profile#experiences');

        $experience = Experience::sole();
        $this->assertTrue($experience->masterProfile->is($this->masterProfile));
        $this->assertSame('Backend Engineer', $experience->title);
        $this->assertSame('Acme', $experience->company);
        $this->assertSame('Jakarta, Indonesia', $experience->location);
        $this->assertSame('2021-03-01', $experience->start_date->format('Y-m-d'));
        $this->assertSame('2023-08-01', $experience->end_date->format('Y-m-d'));
        $this->assertDatabaseHas('experiences', ['id' => $experience->id, 'start_date' => '2021-03-01', 'end_date' => '2023-08-01']);
        $this->assertFalse($experience->is_current);
        $this->assertSame(EmploymentType::FullTime, $experience->employment_type);
        $this->assertSame('Built the payments platform.', $experience->description);
        $this->assertSame(['Cut checkout latency by 40%', 'Led the Laravel 11 upgrade'], $experience->achievements);
        $this->assertSame(['PHP', 'Laravel', 'MySQL'], $experience->skills_used);

        $this->get('/master-profile')
            ->assertOk()
            ->assertSee('Experience added.')
            ->assertSeeInOrder(['Experience', 'value="Backend Engineer"', 'value="Acme"', 'value="2021-03"', 'value="2023-08"', 'PHP, Laravel, MySQL'], false);
    }

    public function test_an_education_is_added_with_month_dates(): void
    {
        $this->post('/master-profile/educations', self::educationInput())
            ->assertSessionHasNoErrors()
            ->assertRedirect('/master-profile#educations');

        $education = Education::sole();
        $this->assertTrue($education->masterProfile->is($this->masterProfile));
        $this->assertSame('Universitas Indonesia', $education->institution);
        $this->assertSame('Bachelor of Computer Science', $education->degree);
        $this->assertSame('Computer Science', $education->field_of_study);
        $this->assertSame('2015-08-01', $education->start_date->format('Y-m-d'));
        $this->assertSame('2019-07-01', $education->end_date->format('Y-m-d'));
        $this->assertFalse($education->is_current);
        $this->assertSame('Thesis on distributed queues.', $education->description);

        $this->get('/master-profile')
            ->assertSee('Education added.')
            ->assertSeeInOrder(['Education', 'value="Universitas Indonesia"', 'value="2015-08"'], false);
    }

    public function test_a_project_is_added_with_month_dates(): void
    {
        $this->post('/master-profile/projects', self::projectInput())
            ->assertSessionHasNoErrors()
            ->assertRedirect('/master-profile#projects');

        $project = Project::sole();
        $this->assertTrue($project->masterProfile->is($this->masterProfile));
        $this->assertSame('Applyr', $project->name);
        $this->assertSame('2026-01-01', $project->start_date->format('Y-m-d'));
        $this->assertSame('2026-06-01', $project->end_date->format('Y-m-d'));
        $this->assertFalse($project->is_current);
        $this->assertSame(['Laravel', 'Tailwind'], $project->tech_stack);
        $this->assertSame('https://github.com/zakky/applyr', $project->link);
        $this->assertSame('Job-application assistant.', $project->description);
        $this->assertSame(['Polls two job boards', 'Tailors CVs with AI'], $project->achievements);

        $this->get('/master-profile')
            ->assertSee('Project added.')
            ->assertSeeInOrder(['Projects', 'value="Applyr"', 'value="2026-01"', 'Laravel, Tailwind'], false);
    }

    public function test_a_project_may_have_no_dates_at_all(): void
    {
        $this->post('/master-profile/projects', self::projectInput(['start_date' => '', 'end_date' => '']))
            ->assertSessionHasNoErrors();

        $project = Project::sole();
        $this->assertNull($project->start_date);
        $this->assertNull($project->end_date);
    }

    public function test_optional_entry_fields_may_be_left_blank(): void
    {
        $this->post('/master-profile/experiences', self::experienceInput([
            'location' => '', 'employment_type' => '', 'description' => '', 'achievements' => '', 'skills_used' => '',
        ]))->assertSessionHasNoErrors();
        $this->post('/master-profile/educations', self::educationInput(['degree' => '', 'field_of_study' => '', 'description' => '']))
            ->assertSessionHasNoErrors();
        $this->post('/master-profile/projects', self::projectInput(['tech_stack' => '', 'link' => '', 'description' => '', 'achievements' => '']))
            ->assertSessionHasNoErrors();

        $experience = Experience::sole();
        $this->assertNull($experience->location);
        $this->assertNull($experience->employment_type);
        $this->assertSame([], $experience->achievements);
        $this->assertSame([], $experience->skills_used);
        $this->assertNull(Education::sole()->degree);
        $this->assertSame([], Project::sole()->tech_stack);
        $this->assertNull(Project::sole()->link);
    }

    #[DataProvider('entryTypes')]
    public function test_an_entry_is_edited(string $segment, string $model, string $builder, string $nameField): void
    {
        $this->post("/master-profile/{$segment}", $this->input($builder));
        $entry = $model::sole();

        $this->put("/master-profile/{$segment}/{$entry->id}", $this->input($builder, [
            $nameField => 'Renamed',
            'start_date' => '2020-02',
            'end_date' => '2020-11',
        ]))->assertSessionHasNoErrors()->assertRedirect("/master-profile#{$segment}");

        $entry = $model::sole();
        $this->assertSame('Renamed', $entry->{$nameField});
        $this->assertSame('2020-02-01', $entry->start_date->format('Y-m-d'));
        $this->assertSame('2020-11-01', $entry->end_date->format('Y-m-d'));
        $this->get('/master-profile')->assertSee('value="Renamed"', false)->assertSee('updated.');
    }

    #[DataProvider('entryTypes')]
    public function test_an_entry_is_deleted(string $segment, string $model, string $builder, string $nameField): void
    {
        $this->post("/master-profile/{$segment}", $this->input($builder, [$nameField => 'Keep']));
        $this->post("/master-profile/{$segment}", $this->input($builder, [$nameField => 'Remove']));
        $removed = $model::query()->where($nameField, 'Remove')->sole();

        $this->delete("/master-profile/{$segment}/{$removed->id}")
            ->assertRedirect("/master-profile#{$segment}");

        $this->assertSame(['Keep'], $model::query()->pluck($nameField)->all());
        $this->get('/master-profile')->assertSee('deleted.')->assertDontSee('value="Remove"', false);
    }

    #[DataProvider('entryTypes')]
    public function test_a_current_entry_stores_no_end_date(string $segment, string $model, string $builder): void
    {
        $this->post("/master-profile/{$segment}", $this->input($builder, ['is_current' => '1', 'end_date' => '2023-08']))
            ->assertSessionHasNoErrors();

        $entry = $model::sole();
        $this->assertTrue($entry->is_current);
        $this->assertNull($entry->end_date);

        $this->put("/master-profile/{$segment}/{$entry->id}", $this->input($builder, ['is_current' => '0', 'end_date' => '2026-09']))
            ->assertSessionHasNoErrors();
        $this->assertFalse($model::sole()->is_current);
        $this->assertSame('2026-09-01', $model::sole()->end_date->format('Y-m-d'));

        $this->put("/master-profile/{$segment}/{$entry->id}", $this->input($builder, ['is_current' => '1']))
            ->assertSessionHasNoErrors();
        $this->assertNull($model::sole()->end_date);
    }

    #[DataProvider('entryTypes')]
    public function test_an_end_date_in_the_same_month_as_the_start_is_allowed(string $segment, string $model, string $builder): void
    {
        $this->post("/master-profile/{$segment}", $this->input($builder, ['start_date' => '2022-05', 'end_date' => '2022-05']))
            ->assertSessionHasNoErrors();

        $this->assertSame('2022-05-01', $model::sole()->end_date->format('Y-m-d'));
    }

    /**
     * @return array<string, array{string, class-string<Model>, string, array<string, mixed>, string}>
     */
    public static function invalidEntries(): array
    {
        $cases = [];

        foreach (self::entryTypes() as $type => [$segment, $model, $builder, $nameField]) {
            $cases += [
                "{$type} ending before it starts" => [$segment, $model, $builder, ['start_date' => '2023-05', 'end_date' => '2023-04'], 'end_date'],
                "{$type} missing its end date while not current" => [$segment, $model, $builder, ['end_date' => '', 'is_current' => '0'], 'end_date'],
                "{$type} with a full date instead of a month" => [$segment, $model, $builder, ['start_date' => '2023-05-17'], 'start_date'],
                "{$type} with a malformed end month" => [$segment, $model, $builder, ['end_date' => '2023-13'], 'end_date'],
                "{$type} without its name" => [$segment, $model, $builder, [$nameField => ''], $nameField],
            ];
        }

        return $cases + [
            'experience without a start date' => ['experiences', Experience::class, 'experienceInput', ['start_date' => ''], 'start_date'],
            'experience without a company' => ['experiences', Experience::class, 'experienceInput', ['company' => ''], 'company'],
            'experience with an unknown employment type' => ['experiences', Experience::class, 'experienceInput', ['employment_type' => 'volunteer'], 'employment_type'],
            'education without a start date' => ['educations', Education::class, 'educationInput', ['start_date' => ''], 'start_date'],
            'project with an end date but no start date' => ['projects', Project::class, 'projectInput', ['start_date' => ''], 'start_date'],
            'project with a malformed link' => ['projects', Project::class, 'projectInput', ['link' => 'github dot com'], 'link'],
        ];
    }

    #[DataProvider('invalidEntries')]
    public function test_an_invalid_entry_is_rejected(string $segment, string $model, string $builder, array $overrides, string $field): void
    {
        $this->post("/master-profile/{$segment}", $this->input($builder, $overrides))
            ->assertRedirect("/master-profile#{$segment}")
            ->assertSessionHasErrorsIn("{$segment}-new", $field);

        $this->assertSame(0, $model::query()->count());
    }

    #[DataProvider('entryTypes')]
    public function test_an_invalid_edit_leaves_the_entry_unchanged(string $segment, string $model, string $builder, string $nameField): void
    {
        $this->post("/master-profile/{$segment}", $this->input($builder, [$nameField => 'Original']));
        $entry = $model::sole();

        $this->put("/master-profile/{$segment}/{$entry->id}", $this->input($builder, [$nameField => 'Changed', 'start_date' => '2030-01', 'end_date' => '2029-12']))
            ->assertSessionHasErrorsIn("{$segment}-{$entry->id}", 'end_date');

        $this->assertSame('Original', $model::sole()->{$nameField});
    }

    public function test_a_rejected_entry_is_redisplayed_in_its_own_form_with_the_error(): void
    {
        $this->post('/master-profile/experiences', self::experienceInput(['title' => 'Saved role']));
        $saved = Experience::sole();

        $this->followingRedirects()
            ->put("/master-profile/experiences/{$saved->id}", self::experienceInput(['title' => 'Edited role', 'start_date' => '2023-05', 'end_date' => '2023-04']))
            ->assertOk()
            ->assertSee('The end date field must be a date after or equal to start date.')
            ->assertSee('value="Edited role"', false)
            // The empty "add" form keeps its blank fields rather than echoing the rejected edit.
            ->assertSeeInOrder(['value="Edited role"', 'Add experience', 'name="title" type="text" required value=""'], false);
    }

    #[DataProvider('entryTypes')]
    public function test_entries_are_listed_newest_first_by_start_date(string $segment, string $model, string $builder, string $nameField): void
    {
        $this->post("/master-profile/{$segment}", $this->input($builder, [$nameField => 'Middle', 'start_date' => '2019-06', 'end_date' => '2020-01']));
        $this->post("/master-profile/{$segment}", $this->input($builder, [$nameField => 'Newest', 'start_date' => '2024-02', 'end_date' => '', 'is_current' => '1']));
        $this->post("/master-profile/{$segment}", $this->input($builder, [$nameField => 'Oldest', 'start_date' => '2012-09', 'end_date' => '2014-03']));

        $relation = $segment;
        $this->assertSame(['Newest', 'Middle', 'Oldest'], $this->masterProfile->{$relation}()->pluck($nameField)->all());

        $this->get('/master-profile')
            ->assertSeeInOrder(['value="Newest"', 'value="Middle"', 'value="Oldest"'], false);
    }

    public function test_undated_projects_are_listed_after_dated_ones(): void
    {
        $this->post('/master-profile/projects', self::projectInput(['name' => 'Undated', 'start_date' => '', 'end_date' => '']));
        $this->post('/master-profile/projects', self::projectInput(['name' => 'Dated', 'start_date' => '2018-01', 'end_date' => '2018-03']));

        $this->assertSame(['Dated', 'Undated'], $this->masterProfile->projects()->pluck('name')->all());
    }

    #[DataProvider('entryTypes')]
    public function test_entries_need_a_saved_master_profile(string $segment, string $model, string $builder): void
    {
        $this->masterProfile->delete();

        $this->post("/master-profile/{$segment}", $this->input($builder))->assertNotFound();
        $this->assertSame(0, $model::query()->count());

        $this->get('/master-profile')
            ->assertOk()
            ->assertSee('Save your personal info first to add experience, education and projects.')
            ->assertDontSee('Add experience');
    }

    public function test_the_page_shows_empty_entry_sections_with_add_forms(): void
    {
        $this->get('/master-profile')
            ->assertOk()
            ->assertSeeInOrder(['Personal info', 'Photo', 'Experience', 'Add experience', 'Education', 'Add education', 'Projects', 'Add project'])
            ->assertSee('type="month"', false)
            ->assertSee('data-current-toggle', false);
    }
}
