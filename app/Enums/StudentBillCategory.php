<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a non-tuition charge is for.
 *
 * The category says what the institution is owed, never how much or whether it
 * has been paid — those live on the bill itself. It exists so a charge the
 * college levied for a fixed purpose cannot be silently re-labelled as another
 * one: a laboratory charge that becomes a "miscellaneous" charge stops being
 * the laboratory fee it was assessed as.
 */
enum StudentBillCategory: string
{
    /** The catch-all the registrar posts when nothing more specific applies. */
    case MISCELLANEOUS = 'miscellaneous';

    /** Consumables and equipment used in a laboratory subject. */
    case LABORATORY = 'laboratory';

    /** Enrolment processed at the start of a term. */
    case REGISTRATION = 'registration';

    /** Sitting an examination, separate from the tuition itself. */
    case EXAMINATION = 'examination';

    public function label(): string
    {
        return match ($this) {
            self::MISCELLANEOUS => 'Miscellaneous fee',
            self::LABORATORY => 'Laboratory fee',
            self::REGISTRATION => 'Registration fee',
            self::EXAMINATION => 'Examination fee',
        };
    }

    /**
     * What the student is told this charge is, which never claims it has been
     * paid and never invites a second payment.
     */
    public function guidance(): string
    {
        return match ($this) {
            self::MISCELLANEOUS => 'Assessed by the college with the amount you were given. Check the description below if it does not look like yours.',
            self::LABORATORY => 'Covers laboratory use for your enrolled subjects this term. Any payment already applied is recorded against this bill.',
            self::REGISTRATION => 'Charged when you enrol for the term. It is separate from tuition and settles on its own.',
            self::EXAMINATION => 'Charged for sitting your examinations. It is not part of tuition, so settling tuition does not settle it.',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
