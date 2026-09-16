<?php

namespace Tests\Feature;

use App\Models\MasterProfile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MasterProfileTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function validInput(array $overrides = []): array
    {
        return array_merge([
            'full_name' => 'Ahmad Zakky',
            'email' => 'zakky@example.com',
            'phone' => '+62 812 3456 7890',
            'location' => 'Jakarta, Indonesia',
            'professional_summary' => 'Backend engineer focused on Laravel.',
            'links' => [],
            'skills' => [],
            'show_photo' => '0',
        ], $overrides);
    }

    public function test_the_master_profile_page_opens_on_an_empty_database(): void
    {
        $this->get('/master-profile')
            ->assertOk()
            ->assertSeeInOrder(['MasterProfile', 'Full name', 'Email', 'Phone', 'Location', 'Professional summary', 'Links', 'Skills', 'Photo']);

        $this->assertDatabaseCount('master_profile', 0);
    }

    public function test_saving_personal_info_creates_the_master_profile_and_shows_it_on_the_page(): void
    {
        $this->put('/master-profile', $this->validInput())
            ->assertSessionHasNoErrors()
            ->assertRedirect('/master-profile');

        $masterProfile = MasterProfile::sole();
        $this->assertSame('Ahmad Zakky', $masterProfile->full_name);
        $this->assertSame('zakky@example.com', $masterProfile->email);
        $this->assertSame('+62 812 3456 7890', $masterProfile->phone);
        $this->assertSame('Jakarta, Indonesia', $masterProfile->location);
        $this->assertSame('Backend engineer focused on Laravel.', $masterProfile->professional_summary);

        $this->get('/master-profile')
            ->assertOk()
            ->assertSee('MasterProfile saved.')
            ->assertSee('value="Ahmad Zakky"', false)
            ->assertSee('Backend engineer focused on Laravel.');
    }

    public function test_saving_again_updates_the_single_master_profile_row(): void
    {
        $this->put('/master-profile', $this->validInput());
        $this->put('/master-profile', $this->validInput(['full_name' => 'Zakky Makarim', 'phone' => null, 'location' => null, 'professional_summary' => null]))
            ->assertSessionHasNoErrors();

        $masterProfile = MasterProfile::sole();
        $this->assertSame('Zakky Makarim', $masterProfile->full_name);
        $this->assertNull($masterProfile->phone);
        $this->assertNull($masterProfile->location);
        $this->assertNull($masterProfile->professional_summary);
    }

    public function test_any_number_of_labelled_links_are_stored_in_order(): void
    {
        $this->put('/master-profile', $this->validInput(['links' => [
            ['label' => 'LinkedIn', 'url' => 'https://linkedin.com/in/zakky'],
            ['label' => 'GitHub', 'url' => 'https://github.com/zakky'],
            ['label' => 'Portfolio', 'url' => 'https://zakky.dev'],
        ]]))->assertSessionHasNoErrors();

        $this->assertSame([
            ['label' => 'LinkedIn', 'url' => 'https://linkedin.com/in/zakky'],
            ['label' => 'GitHub', 'url' => 'https://github.com/zakky'],
            ['label' => 'Portfolio', 'url' => 'https://zakky.dev'],
        ], MasterProfile::sole()->links);

        $this->get('/master-profile')
            ->assertSeeInOrder(['value="LinkedIn"', 'value="https://linkedin.com/in/zakky"', 'value="GitHub"', 'value="Portfolio"'], false);
    }

    public function test_a_link_is_removed_by_saving_without_it(): void
    {
        $this->put('/master-profile', $this->validInput(['links' => [
            ['label' => 'LinkedIn', 'url' => 'https://linkedin.com/in/zakky'],
            ['label' => 'GitHub', 'url' => 'https://github.com/zakky'],
        ]]));

        // Keys are whatever the form's rows were named; removing a row leaves a gap.
        $this->put('/master-profile', $this->validInput(['links' => [
            1 => ['label' => 'GitHub', 'url' => 'https://github.com/zakky'],
        ]]))->assertSessionHasNoErrors();

        $this->assertSame([['label' => 'GitHub', 'url' => 'https://github.com/zakky']], MasterProfile::sole()->links);

        $this->put('/master-profile', array_diff_key($this->validInput(), ['links' => true]))
            ->assertSessionHasNoErrors();

        $this->assertSame([], MasterProfile::sole()->links);
    }

    public function test_a_completely_blank_link_row_is_ignored(): void
    {
        $this->put('/master-profile', $this->validInput(['links' => [
            ['label' => ' GitHub ', 'url' => ' https://github.com/zakky '],
            ['label' => '', 'url' => ''],
        ]]))->assertSessionHasNoErrors();

        $this->assertSame([['label' => 'GitHub', 'url' => 'https://github.com/zakky']], MasterProfile::sole()->links);
    }

    public function test_skills_are_stored_grouped_into_user_named_categories(): void
    {
        $this->put('/master-profile', $this->validInput(['skills' => [
            ['category' => 'Backend', 'skills' => 'PHP, Laravel , MySQL,'],
            ['category' => 'Tools', 'skills' => ['Docker', ' Git ']],
        ]]))->assertSessionHasNoErrors();

        $this->assertSame([
            ['category' => 'Backend', 'skills' => ['PHP', 'Laravel', 'MySQL']],
            ['category' => 'Tools', 'skills' => ['Docker', 'Git']],
        ], MasterProfile::sole()->skills);

        $this->get('/master-profile')
            ->assertSeeInOrder(['value="Backend"', 'value="PHP, Laravel, MySQL"', 'value="Tools"', 'value="Docker, Git"'], false);
    }

    public function test_a_skill_category_is_removed_by_saving_without_it(): void
    {
        $this->put('/master-profile', $this->validInput(['skills' => [
            ['category' => 'Backend', 'skills' => 'PHP'],
            ['category' => 'Tools', 'skills' => 'Docker'],
        ]]));

        $this->put('/master-profile', $this->validInput(['skills' => [
            5 => ['category' => 'Tools', 'skills' => 'Docker'],
            6 => ['category' => '', 'skills' => ''],
        ]]))->assertSessionHasNoErrors();

        $this->assertSame([['category' => 'Tools', 'skills' => ['Docker']]], MasterProfile::sole()->skills);
    }

    public function test_uploading_a_photo_stores_it_without_turning_on_show_photo(): void
    {
        Storage::fake('local');

        $this->put('/master-profile', $this->validInput(['photo' => UploadedFile::fake()->image('me.jpg', 400, 400)]))
            ->assertSessionHasNoErrors();

        $masterProfile = MasterProfile::sole();
        $this->assertNotNull($masterProfile->photo_path);
        Storage::disk('local')->assertExists($masterProfile->photo_path);
        $this->assertFalse($masterProfile->show_photo);

        $this->get('/master-profile/photo')->assertOk();
        $this->get('/master-profile')->assertSee('alt="Profile photo"', false);
    }

    public function test_show_photo_toggles_independently_of_uploading(): void
    {
        Storage::fake('local');

        $this->put('/master-profile', $this->validInput(['show_photo' => '1']))->assertSessionHasNoErrors();
        $this->assertTrue(MasterProfile::sole()->show_photo);
        $this->assertNull(MasterProfile::sole()->photo_path);

        $this->put('/master-profile', $this->validInput(['photo' => UploadedFile::fake()->image('me.png')]));
        $photoPath = MasterProfile::sole()->photo_path;

        $this->put('/master-profile', $this->validInput(['show_photo' => '1']))->assertSessionHasNoErrors();
        $this->assertTrue(MasterProfile::sole()->show_photo);
        $this->assertSame($photoPath, MasterProfile::sole()->photo_path);

        $this->put('/master-profile', $this->validInput(['show_photo' => '0']))->assertSessionHasNoErrors();
        $this->assertFalse(MasterProfile::sole()->show_photo);
        $this->assertSame($photoPath, MasterProfile::sole()->photo_path);
        Storage::disk('local')->assertExists($photoPath);
    }

    public function test_uploading_a_new_photo_replaces_the_old_file(): void
    {
        Storage::fake('local');

        $this->put('/master-profile', $this->validInput(['photo' => UploadedFile::fake()->image('old.jpg')]));
        $oldPath = MasterProfile::sole()->photo_path;

        $this->put('/master-profile', $this->validInput(['photo' => UploadedFile::fake()->image('new.jpg')]))
            ->assertSessionHasNoErrors();

        $newPath = MasterProfile::sole()->photo_path;
        $this->assertNotSame($oldPath, $newPath);
        Storage::disk('local')->assertMissing($oldPath);
        Storage::disk('local')->assertExists($newPath);
    }

    public function test_removing_the_photo_deletes_the_file_and_leaves_show_photo_alone(): void
    {
        Storage::fake('local');

        $this->put('/master-profile', $this->validInput(['show_photo' => '1', 'photo' => UploadedFile::fake()->image('me.jpg')]));
        $photoPath = MasterProfile::sole()->photo_path;

        $this->delete('/master-profile/photo')
            ->assertRedirect('/master-profile')
            ->assertSessionHas('status', 'Photo removed.');

        $this->assertNull(MasterProfile::sole()->photo_path);
        $this->assertTrue(MasterProfile::sole()->show_photo);
        Storage::disk('local')->assertMissing($photoPath);

        $this->get('/master-profile/photo')->assertNotFound();
        $this->get('/master-profile')
            ->assertDontSee('alt="Profile photo"', false)
            ->assertDontSee('Remove photo')
            ->assertSee('Upload photo');
    }

    public function test_the_remove_photo_action_is_offered_only_when_there_is_a_photo(): void
    {
        Storage::fake('local');

        $this->put('/master-profile', $this->validInput());
        $this->get('/master-profile')->assertDontSee('Remove photo');

        $this->put('/master-profile', $this->validInput(['photo' => UploadedFile::fake()->image('me.jpg')]));
        $this->get('/master-profile')->assertSee('Remove photo');
    }

    public function test_removing_a_photo_when_there_is_none_does_nothing(): void
    {
        $this->delete('/master-profile/photo')->assertRedirect('/master-profile');
        $this->assertSame(0, MasterProfile::count());

        $this->put('/master-profile', $this->validInput());

        $this->delete('/master-profile/photo')->assertRedirect('/master-profile');
        $this->assertNull(MasterProfile::sole()->photo_path);
    }

    public function test_there_is_no_photo_to_serve_before_one_is_uploaded(): void
    {
        $this->get('/master-profile/photo')->assertNotFound();

        $this->put('/master-profile', $this->validInput());

        $this->get('/master-profile/photo')->assertNotFound();
        $this->get('/master-profile')->assertDontSee('alt="Profile photo"', false);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidInputs(): array
    {
        return [
            'missing full name' => [['full_name' => ''], 'full_name'],
            'missing email' => [['email' => ''], 'email'],
            'malformed email' => [['email' => 'not-an-email'], 'email'],
            'link without label' => [['links' => [['label' => '', 'url' => 'https://github.com/zakky']]], 'links.0.label'],
            'link without url' => [['links' => [['label' => 'GitHub', 'url' => '']]], 'links.0.url'],
            'link with malformed url' => [['links' => [['label' => 'GitHub', 'url' => 'github dot com']]], 'links.0.url'],
            'skills without a category' => [['skills' => [['category' => '', 'skills' => 'PHP']]], 'skills.0.category'],
            'category without skills' => [['skills' => [['category' => 'Backend', 'skills' => ' , ']]], 'skills.0.skills'],
            'photo that is not an image' => [['photo' => UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf')], 'photo'],
            'photo that is too large' => [['photo' => UploadedFile::fake()->image('huge.jpg')->size(6000)], 'photo'],
            'duplicate category' => [['skills' => [['category' => 'Backend', 'skills' => 'PHP'], ['category' => 'backend', 'skills' => 'Go']]], 'skills.1.category'],
        ];
    }

    #[DataProvider('invalidInputs')]
    public function test_saving_invalid_input_is_rejected(array $overrides, string $field): void
    {
        $this->from('/master-profile')
            ->put('/master-profile', $this->validInput($overrides))
            ->assertRedirect('/master-profile')
            ->assertSessionHasErrors($field);

        $this->assertDatabaseCount('master_profile', 0);
    }

    public function test_a_rejected_save_redisplays_the_entered_rows_with_the_error(): void
    {
        $this->from('/master-profile')->followingRedirects()
            ->put('/master-profile', $this->validInput([
                'links' => [['label' => 'GitHub', 'url' => 'github dot com']],
                'skills' => [['category' => 'Backend', 'skills' => 'PHP, Laravel']],
            ]))
            ->assertOk()
            ->assertSee('The link URL field must be a valid URL.')
            ->assertSeeInOrder(['value="GitHub"', 'value="github dot com"', 'value="Backend"', 'value="PHP, Laravel"'], false);
    }

    public function test_the_dashboard_nav_links_to_the_master_profile(): void
    {
        $this->get('/')->assertSee('href="'.route('master-profile.edit').'"', false);
    }

    public function test_a_row_added_on_the_page_continues_after_the_rendered_rows(): void
    {
        $this->put('/master-profile', $this->validInput(['links' => [
            ['label' => 'LinkedIn', 'url' => 'https://linkedin.com/in/zakky'],
            ['label' => 'GitHub', 'url' => 'https://github.com/zakky'],
        ]]));

        $this->get('/master-profile')
            ->assertSee('name="links[__INDEX__][label]"', false)
            ->assertSee('let nextRowIndex = 2;', false);
    }
}
