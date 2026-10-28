<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Personal, contact and employment details of a driver (one per driver user).
 * Name, phone, email, notes and "active" stay on the user; identity documents
 * and the licence are DriverDocument rows.
 */
class DriverProfile extends Model implements HasMedia
{
    use Audited, InteractsWithMedia;

    protected $guarded = ['id'];

    /** Fields the admin can make mandatory (Business settings › Drivers). */
    public const FIELDS = [
        'photo' => 'Profile photo',
        'legal_name' => 'Full legal name',
        'date_of_birth' => 'Date of birth',
        'nationality' => 'Nationality',
        'gender' => 'Gender',
        'address' => 'Residential address',
        'emergency_name' => 'Emergency contact name',
        'emergency_phone' => 'Emergency contact phone',
        'employee_ref' => 'Driver ID / employee ref',
        'joined_on' => 'Joining date',
    ];

    /** Fields a driver may be allowed to update themselves. */
    public const SELF_SERVICE_FIELDS = [
        'phone' => 'Phone number',
        'address' => 'Residential address',
        'emergency_name' => 'Emergency contact name',
        'emergency_phone' => 'Emergency contact phone',
        'photo' => 'Profile photo',
    ];

    /** What a person in the driver module can be given (28 Oct: captains and crew for charters). */
    public const WORK_TYPES = [
        'driver' => 'Driver (cars)',
        'captain' => 'Captain (yachts)',
        'crew' => 'Crew (yachts)',
    ];

    /** Empty = driver only, as before 28 Oct. */
    public function does(string $type): bool
    {
        $types = $this->work_types ?: ['driver'];

        return in_array($type, $types, true);
    }

    protected function casts(): array
    {
        return [
            'work_types' => 'array',
            'date_of_birth' => 'date',
            'joined_on' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('photo')->useDisk('private')->singleFile();
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')->width(240)->height(240)->nonQueued();
    }

    /** Mandatory profile fields that are still empty. */
    public function missingFields(): array
    {
        $required = (array) Setting::read('driver_required_fields', []);

        return collect($required)
            ->filter(fn ($field) => isset(self::FIELDS[$field]))
            ->reject(fn ($field) => $field === 'photo' ? $this->hasMedia('photo') : filled($this->{$field}))
            ->map(fn ($field) => self::FIELDS[$field])
            ->values()->all();
    }
}
