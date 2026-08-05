<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\NotaryProfile;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Creates the admin account that also operates as the system-native notary
 * ("Naija Virtual Notary") for fallback notarizations.
 *
 * IMPORTANT: this account must belong to a real, duly appointed Notary Public,
 * since it applies the system-native seal during fallback sessions
 * (see Build Plan sections 3 and 4.6).
 */
class SystemNativeNotarySeeder extends Seeder
{
    public function run(): void
    {
        $email = config('nvn.system_native_email');

        $admin = User::firstOrCreate(
            ['email' => $email],
            [
                'full_name'         => 'Naija Virtual Notary',
                'password'          => Hash::make(env('NVN_ADMIN_PASSWORD', 'change-me-now')),
                'role'              => UserRole::Admin,
                'status'            => 'active',
                'email_verified_at' => now(),
            ]
        );

        $profile = NotaryProfile::firstOrCreate(
            ['user_id' => $admin->id],
            [
                'entity_type'            => 'individual',
                'verification_status'    => 'approved',
                'approved_at'            => now(),
                'commission_rate'        => config('nvn.default_commission_rate'),
                'is_system_native'       => true,
                // Listed like any partner: clients can book the platform notary
                // directly instead of only reaching it through fallback. Unlist it
                // from Notaries → List/Unlist if that is not wanted.
                'public_listing_enabled' => true,
                'delegation_consent'     => true,
                'delegation_consent_at'  => now(),
            ]
        );

        // A profile with no active service cannot be booked, so give the system
        // notary a starting price list. Adjust it under Platform settings →
        // "Admin / system notarization pricing". firstOrCreate keeps any edits.
        foreach (self::DEFAULT_SERVICES as $service) {
            $profile->services()->firstOrCreate(
                ['service_type' => $service['service_type']],
                $service + ['active' => true],
            );
        }
    }

    /** Prices are in kobo / cents. */
    private const DEFAULT_SERVICES = [
        [
            'service_type'               => 'Affidavits',
            'price_ngn'                  => 2000000,
            'price_usd'                  => 2000,
            'estimated_duration_minutes' => 30,
        ],
        [
            'service_type'               => 'Travel Consent Letters',
            'price_ngn'                  => 2500000,
            'price_usd'                  => 2500,
            'estimated_duration_minutes' => 30,
        ],
    ];
}
