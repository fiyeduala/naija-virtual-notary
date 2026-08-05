<?php

namespace App\Enums;

enum Specialty: string
{
    case Affidavits         = 'affidavits';
    case PropertyDocuments  = 'property_documents';
    case Contracts          = 'contracts';
    case TravelConsent      = 'travel_consent_letters';
    case AcademicCredentials = 'academic_credentials';
    case ImmigrationDocuments = 'immigration_documents';
    case Other              = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Affidavits          => 'Affidavits',
            self::PropertyDocuments   => 'Property Documents',
            self::Contracts           => 'Contracts',
            self::TravelConsent       => 'Travel Consent Letters',
            self::AcademicCredentials => 'Academic Credentials',
            self::ImmigrationDocuments => 'Immigration Documents',
            self::Other               => 'Other',
        };
    }
}
