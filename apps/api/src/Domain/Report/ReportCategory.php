<?php

declare(strict_types=1);

namespace Central\Api\Domain\Report;

/**
 * How the report catalogue is grouped for a person choosing one.
 *
 * The families a report can belong to. Adding one is an enum case and its
 * reports — see {@see ReportCatalogue}. A handful of legacy families are
 * absent because the data they read was never migrated: surveys and
 * satisfaction, training cost and budget, content-access analytics, security
 * and GDPR logs. A report with nothing truthful to read is not built rather
 * than built to return zeroes.
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
    case Gamification = 'gamification';
    case Path         = 'path';
    case Ilt          = 'ilt';
    case Onboarding   = 'onboarding';
    case Audit        = 'audit';
    case Manager      = 'manager';
    case Cohort       = 'cohort';
    case System       = 'system';

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
            self::Gamification => 'Gamification',
            self::Path        => 'Learning paths',
            self::Ilt         => 'Live training',
            self::Onboarding  => 'Onboarding',
            self::Audit       => 'Audit and records',
            self::Manager     => 'For managers',
            self::Cohort      => 'Cohorts and groups',
            self::System      => 'System',
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
            self::Gamification => 'Points, badges and who is on top.',
            self::Path        => 'Progress through the multi-course journeys.',
            self::Ilt         => 'Attendance, no-shows and how full the rooms are.',
            self::Onboarding  => 'How quickly new joiners get up to speed.',
            self::Audit       => 'The trail of who did what, and the records to prove it.',
            self::Manager     => 'One team, for the person who runs it.',
            self::Cohort      => 'Groups measured against each other.',
            self::System      => 'The platform itself — usage and storage.',
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
