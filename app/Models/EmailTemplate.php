<?php

namespace App\Models;

use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Model;

/** Email templates with placeholders (4 Nov, 14.3), in English and Arabic. */
class EmailTemplate extends Model
{
    use Audited;

    protected $guarded = ['id'];

    public const AUDIENCES = ['customer' => 'Customers', 'driver' => 'Drivers & crew', 'staff' => 'Staff', 'any' => 'Anyone'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
