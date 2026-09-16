<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * The user's ground-truth personal info, links, skills, and their Experience, Education
 * and Project entries. A single mutable row with no user_id and no versioning; it exists
 * only once the user first saves it, and entries can only be added after that.
 */
#[Table('master_profile')]
#[Fillable([
    'full_name',
    'email',
    'phone',
    'location',
    'professional_summary',
    'links',
    'skills',
    'photo_path',
    'show_photo',
])]
class MasterProfile extends Model
{
    /**
     * The profile photo lives on the private disk; the dashboard serves it through a route.
     */
    public const PHOTO_DISK = 'local';

    public const PHOTO_DIRECTORY = 'master-profile';

    protected $attributes = [
        'links' => '[]',
        'skills' => '[]',
        'show_photo' => false,
    ];

    /**
     * The MasterProfile row, or an unsaved blank one when nothing has been saved yet.
     */
    public static function current(): self
    {
        return static::query()->firstOrNew();
    }

    /**
     * @return HasMany<Experience, $this>
     */
    public function experiences(): HasMany
    {
        return $this->hasMany(Experience::class)->newestFirst();
    }

    /**
     * @return HasMany<Education, $this>
     */
    public function educations(): HasMany
    {
        return $this->hasMany(Education::class)->newestFirst();
    }

    /**
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class)->newestFirst();
    }

    /**
     * Every Experience, Education and Project, in that order.
     *
     * @return Collection<int, MasterProfileEntry>
     */
    public function entries(): Collection
    {
        return collect([...$this->experiences, ...$this->educations, ...$this->projects]);
    }

    /**
     * Store a newly uploaded photo, save, and only then delete the file it replaced.
     * Whether the photo appears on CVs is show_photo's business, not the upload's.
     */
    public function replacePhoto(UploadedFile $photo): void
    {
        $replacedPhotoPath = $this->photo_path;

        $this->photo_path = $photo->store(self::PHOTO_DIRECTORY, self::PHOTO_DISK);
        $this->save();

        if ($replacedPhotoPath !== null) {
            Storage::disk(self::PHOTO_DISK)->delete($replacedPhotoPath);
        }
    }

    /**
     * Forget the photo, save, and only then delete its file. show_photo is left as it is.
     */
    public function removePhoto(): void
    {
        $removedPhotoPath = $this->photo_path;

        if ($removedPhotoPath === null) {
            return;
        }

        $this->photo_path = null;
        $this->save();

        Storage::disk(self::PHOTO_DISK)->delete($removedPhotoPath);
    }

    /**
     * Copy the photo into $directory on the photo disk, so a snapshot keeps its own copy however
     * the photo is later replaced or removed.
     *
     * @return ?string the copy's path, or null when there is no photo file to copy
     */
    public function copyPhotoTo(string $directory): ?string
    {
        $disk = Storage::disk(self::PHOTO_DISK);

        if ($this->photo_path === null || ! $disk->exists($this->photo_path)) {
            return null;
        }

        $copyPath = $directory.'/'.basename($this->photo_path);
        $disk->copy($this->photo_path, $copyPath);

        return $copyPath;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'links' => 'array',
            'skills' => 'array',
            'show_photo' => 'boolean',
        ];
    }
}
