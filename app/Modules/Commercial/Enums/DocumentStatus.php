<?php

declare(strict_types=1);

namespace App\Modules\Commercial\Enums;

use App\Kernel\StateMachine\TransitionTable;

/**
 * Commercial document lifecycle (mirrored by the commercial_documents_immutable trigger for issued rows).
 *
 * @see SRS M03-01, M03-07, §5.1
 */
enum DocumentStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Issued = 'issued';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Void = 'void';
    case Superseded = 'superseded';

    public static function transitions(): TransitionTable
    {
        return new TransitionTable([
            'draft' => ['pending_approval'],
            'pending_approval' => ['approved', 'draft'],
            'approved' => ['issued', 'draft'],
            'issued' => ['accepted', 'rejected', 'void', 'superseded'],
            'accepted' => ['void', 'superseded'],
            'rejected' => ['void', 'superseded'],
        ]);
    }

    /** Generated text documents (memo, letter, appointment, certificate) are issued directly — no approval step. */
    public static function generatedTransitions(): TransitionTable
    {
        return new TransitionTable(['draft' => ['issued'], 'issued' => ['void']]);
    }

    public function isIssued(): bool
    {
        return in_array($this, [self::Issued, self::Accepted, self::Rejected, self::Void, self::Superseded], true);
    }
}
