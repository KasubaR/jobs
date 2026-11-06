<?php

namespace App\Services;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/** Authenticator-app (TOTP) login codes and one-time recovery codes. */
class TwoFactor
{
    public const RECOVERY_CODES = 8;

    public function __construct(private Google2FA $totp) {}

    public function newSecret(): string
    {
        return $this->totp->generateSecretKey(32);
    }

    public function uri(User $user, string $secret): string
    {
        return $this->totp->getQRCodeUrl(config('app.name'), $user->email, $secret);
    }

    public function qrSvg(string $uri): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(220, 1), new SvgImageBackEnd));

        return $writer->writeString($uri);
    }

    /** Is this code valid for this secret? Marks it used so it cannot be replayed. */
    public function verify(User $user, string $secret, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code);

        if (! preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        // Pass 0, not null: with null the library answers a plain `true` instead of the time step.
        $step = $this->totp->verifyKeyNewer($secret, $code, (int) $user->app_authentication_last_step, 1);

        if (! is_int($step)) {
            return false;
        }

        $user->forceFill(['app_authentication_last_step' => $step])->save();

        return true;
    }

    /** Check a code against a secret without saving anything (used while setting up). */
    public function check(string $secret, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code);

        return (bool) preg_match('/^\d{6}$/', $code) && $this->totp->verifyKey($secret, $code, 1);
    }

    /**
     * Replace the recovery codes. Only hashes are stored.
     *
     * @return list<string> the codes to show the person once
     */
    public function issueRecoveryCodes(User $user): array
    {
        // No 0/o, 1/l/i: these are read off a screen or paper, so look-alikes are left out.
        $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
        $part = fn () => implode('', array_map(fn () => $alphabet[random_int(0, strlen($alphabet) - 1)], range(1, 5)));

        $codes = [];
        for ($i = 0; $i < self::RECOVERY_CODES; $i++) {
            $codes[] = $part().'-'.$part();
        }

        $user->forceFill(['app_authentication_recovery_codes' => array_map($this->hash(...), $codes)])->save();

        return $codes;
    }

    /** A recovery code works once. */
    public function useRecoveryCode(User $user, string $code): bool
    {
        $hash = $this->hash(strtolower(trim($code)));
        $stored = $user->app_authentication_recovery_codes ?? [];

        $index = array_search($hash, $stored, true);
        if ($index === false) {
            return false;
        }

        unset($stored[$index]);
        $user->forceFill(['app_authentication_recovery_codes' => array_values($stored)])->save();

        return true;
    }

    private function hash(string $code): string
    {
        return hash('sha256', $code);
    }
}
