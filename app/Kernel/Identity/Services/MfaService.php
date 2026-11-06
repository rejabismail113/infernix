<?php

declare(strict_types=1);

namespace App\Kernel\Identity\Services;

use App\Kernel\Audit\Audit;
use App\Kernel\Identity\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP (RFC 6238) enrolment, verification and recovery codes. Secrets are encrypted at rest and never
 * serialised; the only output is the one-time provisioning QR at enrolment.
 *
 * @see SRS G-01, M12-01, NFR-01
 */
final class MfaService
{
    public function __construct(private readonly Google2FA $google2fa) {}

    /**
     * Start enrolment: store a pending secret (not yet confirmed) and return the provisioning data.
     *
     * @return array{secret: string, qr_svg: string}
     */
    public function beginEnrolment(User $user): array
    {
        $secret = $this->google2fa->generateSecretKey(32);
        $user->forceFill(['mfa_secret' => $secret, 'mfa_confirmed_at' => null])->save();
        $uri = $this->google2fa->getQRCodeUrl((string) config('infernix.security.mfa_issuer', 'Infernix Workspace'), $user->email, $secret);
        $svg = (new Writer(new ImageRenderer(new RendererStyle(200), new SvgImageBackEnd)))->writeString($uri);

        return ['secret' => $secret, 'qr_svg' => $svg];
    }

    /** @return list<string> plaintext recovery codes (shown once) */
    public function confirmEnrolment(User $user, string $code): array
    {
        if ($user->mfa_secret === null || ! $this->verifyTotp($user->mfa_secret, $code)) {
            throw new \DomainException('Invalid authentication code.');
        }
        $plain = collect(range(1, 8))->map(fn () => Str::upper(Str::random(5)).'-'.Str::upper(Str::random(5)))->all();

        DB::transaction(function () use ($user, $plain): void {
            $user->forceFill([
                'mfa_confirmed_at' => now(),
                'mfa_recovery_codes' => array_map(fn (string $c): string => Hash::make($c), $plain),
            ])->save();
            Audit::log('mfa_enabled', $user, null, null, ['mfa_confirmed_at' => now()->toIso8601String()], null, $user);
        });

        return $plain;
    }

    public function verify(User $user, string $code): bool
    {
        if (! $user->mfaEnabled() || $user->mfa_secret === null) {
            return false;
        }
        if ($this->verifyTotp($user->mfa_secret, $code)) {
            return true;
        }

        return $this->consumeRecoveryCode($user, $code);
    }

    private function verifyTotp(string $secret, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? $code;

        return (bool) $this->google2fa->verifyKey($secret, $code, 1);
    }

    private function consumeRecoveryCode(User $user, string $code): bool
    {
        $codes = $user->mfa_recovery_codes ?? [];
        foreach ($codes as $i => $hash) {
            if (Hash::check(strtoupper(trim($code)), $hash)) {
                unset($codes[$i]);
                $user->forceFill(['mfa_recovery_codes' => array_values($codes)])->save();
                Audit::log('mfa_recovery_used', $user, null, null, ['remaining' => count($codes)], null, $user);

                return true;
            }
        }

        return false;
    }

    public function currentCode(string $secret): string
    {
        return $this->google2fa->getCurrentOtp($secret);
    }
}
