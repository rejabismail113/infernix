<?php

declare(strict_types=1);

namespace App\Modules\Commercial\Models;

use App\Modules\Commercial\Enums\DocumentType;

/** Appointment letter (M03-06). @see SRS M03 */
final class AppointmentLetter extends CommercialDocument
{
    public static function documentType(): DocumentType
    {
        return DocumentType::AppointmentLetter;
    }
}
