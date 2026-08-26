<?php

declare(strict_types=1);

namespace Central\Api\Domain\Report;

/**
 * How the report catalogue is grouped for a person choosing one.
 *
 * The legacy's twelve categories, minus the three whose modules this port has
 * not reached. Adding one back is an enum case and its reports — see
 * {@see ReportCatalogue} for what is deferred and why.
 */
enum ReportCategory: string
{
    case Enrollment  = 'enrollment';
    case Completion  = 'completion';
    case Compliance  = 'compliance';
    case Assessment  = 'assessment';
    case Course      = 'course';
    case User        = 'user';
    case Time        = 'time';
    case Certificate = 'certificate';
    case Executive   = 'executive';

    public function label(): string
    {
        return match ($this) {
            self::Enrollment  => 'Enrolment',
            self::Completion  => 'Completion',
            self::Compliance  => 'Compliance',
            self::Assessment  => 'Assessment',
            self::Course      => 'Courses',
            self::User        => 'People',
            self::Time        => 'Time and engagement',
            self::Certificate => 'Certificates',
            self::Executive   => 'Executive',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Enrollment  => 'Who has been put on what, and how they got there.',
            self::Completion  => 'How far people have got, and who is behind.',
            self::Compliance  => 'Mandatory training, due dates and what is out of date.',
            self::Assessment  => 'Marks, attempts and where a paper is going wrong.',
            self::Course      => 'What the catalogue holds and how it is being used.',
            self::User        => 'Activity, joiners and who has stopped logging in.',
            self::Time        => 'Where the hours go.',
            self::Certificate => 'What has been issued, checked and is about to lapse.',
            self::Executive   => 'The organisation at a glance.',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
