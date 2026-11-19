<?php

namespace App\Support\Contracts;

/** 19 Nov (suggestion 5): areas of a car on the damage diagram (top view). */
class CarZones
{
    public const ZONES = [
        'front_bumper' => 'Front bumper', 'bonnet' => 'Bonnet', 'windscreen' => 'Windscreen', 'roof' => 'Roof',
        'rear_window' => 'Rear window', 'boot' => 'Boot', 'rear_bumper' => 'Rear bumper',
        'front_left' => 'Front left door / wing', 'rear_left' => 'Rear left door / wing', 'front_right' => 'Front right door / wing', 'rear_right' => 'Rear right door / wing',
        'wheel_fl' => 'Front left wheel', 'wheel_fr' => 'Front right wheel', 'wheel_rl' => 'Rear left wheel', 'wheel_rr' => 'Rear right wheel',
        'interior' => 'Interior',
    ];

    /** Labels for the chosen keys. */
    public static function labels(array $keys): array
    {
        return array_values(array_intersect_key(self::ZONES, array_flip($keys)));
    }
}
