<?php

declare(strict_types=1);

namespace App\Kernel\Documents\Contracts;

use App\Kernel\Identity\Models\User;

/**
 * Issue a generated M03 document from another module without depending on M03 (training certificates from M02).
 * The certificate carries a serial number and a public verification token (M03-06).
 *
 * @see SRS M03-06, M02-06
 */
interface GeneratedDocumentIssuer
{
    /**
     * @param  array{participant_name: string, organisation: string|null, course_title: string, course_date: string, project_id: int, source_id: int, trainer: string|null}  $data
     * @return array{id: int, number: string, pdf_document_id: int|null, verify_url: string}
     */
    public function issueCertificate(array $data, User $actor): array;
}
