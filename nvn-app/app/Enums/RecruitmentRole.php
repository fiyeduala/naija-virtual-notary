<?php

namespace App\Enums;

/**
 * The four role categories NVN is recruiting for.
 *
 * This is the category a vacancy belongs to, which is what decides which
 * assessment template it draws from. It is NOT a job title: the office may run
 * two Marketing Executive vacancies with different titles, locations and
 * deadlines, and both sit under the same category and the same assessment.
 */
enum RecruitmentRole: string
{
    case CustomerService    = 'customer_service';
    case MarketingExecutive = 'marketing_executive';
    case DigitalMarketing   = 'digital_marketing';
    case HrManager          = 'hr_manager';

    public function label(): string
    {
        return match ($this) {
            self::CustomerService    => 'Customer Service',
            self::MarketingExecutive => 'Marketing Executive',
            self::DigitalMarketing   => 'Digital Marketing',
            self::HrManager          => 'Human Resources Manager',
        };
    }

    /** The department shown on the careers listing. */
    public function department(): string
    {
        return match ($this) {
            self::CustomerService    => 'Customer Operations',
            self::MarketingExecutive => 'Commercial',
            self::DigitalMarketing   => 'Marketing',
            self::HrManager          => 'People & Operations',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return array_reduce(
            self::cases(),
            fn (array $carry, self $role) => $carry + [$role->value => $role->label()],
            [],
        );
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
