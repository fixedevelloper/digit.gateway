<?php

namespace App\Services\Security;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * 2FA par application d'authentification (TOTP, RFC 6238 : HMAC-SHA1, 6 chiffres, 30 s),
 * avec codes de secours à usage unique et protection contre le rejeu d'un code déjà utilisé.
 * Aucun SMS ni service tiers : fonctionne avec Google Authenticator, Authy, 1Password...
 */
class TwoFactorService
{
    private const PERIOD = 30;

    private const DIGITS = 6;

    /** Dérive tolérée (en pas de 30 s) de chaque côté de l'heure du serveur. */
    private const WINDOW = 1;

    private const CHALLENGE_TTL = 300;

    private const CHALLENGE_MAX_ATTEMPTS = 5;

    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    // ----------------------------------------------------------------- TOTP

    public function generateSecret(): string
    {
        return $this->base32Encode(random_bytes(20));
    }

    public function provisioningUri(User $user, string $secret): string
    {
        $issuer = (string) config('security.two_factor_issuer');
        $account = $user->email ?: $user->phone ?: (string) $user->id;

        return 'otpauth://totp/'.rawurlencode("{$issuer}:{$account}")
            .'?secret='.$secret.'&issuer='.rawurlencode($issuer).'&algorithm=SHA1&digits='.self::DIGITS.'&period='.self::PERIOD;
    }

    public function codeAt(string $secret, int $step): string
    {
        $hash = hash_hmac('sha1', pack('N*', 0, $step), $this->base32Decode($secret), true);
        $offset = ord($hash[19]) & 0xF;
        $binary = ((ord($hash[$offset]) & 0x7F) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);

        return str_pad((string) ($binary % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Vérifie un code TOTP et le consomme : un même code (même pas de temps) n'est jamais
     * accepté deux fois, même s'il est intercepté dans sa fenêtre de validité.
     */
    public function verifyCode(User $user, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code);

        if (! $user->two_factor_secret || ! preg_match('/^\d{'.self::DIGITS.'}$/', $code)) {
            return false;
        }

        $current = intdiv(time(), self::PERIOD);

        for ($step = $current - self::WINDOW; $step <= $current + self::WINDOW; $step++) {
            if (! hash_equals($this->codeAt($user->two_factor_secret, $step), $code)) {
                continue;
            }

            // Mise à jour conditionnelle : atomique, une seule requête gagne en cas de course.
            return User::whereKey($user->id)
                ->where(fn ($q) => $q->whereNull('two_factor_last_step')->orWhere('two_factor_last_step', '<', $step))
                ->update(['two_factor_last_step' => $step]) === 1;
        }

        return false;
    }

    // ------------------------------------------------------- Codes de secours

    /**
     * @return array<int, string> codes en clair (affichés une seule fois) ; seuls leurs hashs sont stockés
     */
    public function generateRecoveryCodes(User $user): array
    {
        $codes = collect(range(1, 8))
            ->map(fn () => strtolower(Str::random(5).'-'.Str::random(5)))
            ->all();

        $user->forceFill(['two_factor_recovery_codes' => array_map(fn ($c) => hash('sha256', $c), $codes)])->save();

        return $codes;
    }

    public function useRecoveryCode(User $user, string $code): bool
    {
        $hash = hash('sha256', strtolower(trim($code)));
        $codes = $user->two_factor_recovery_codes ?? [];

        if (! in_array($hash, $codes, true)) {
            return false;
        }

        $user->forceFill(['two_factor_recovery_codes' => array_values(array_diff($codes, [$hash]))])->save();

        return true;
    }

    /** Code TOTP ou code de secours. */
    public function verifyAny(User $user, ?string $code, ?string $recoveryCode): bool
    {
        if ($code !== null && $code !== '') {
            return $this->verifyCode($user, $code);
        }

        return $recoveryCode !== null && $recoveryCode !== '' && $this->useRecoveryCode($user, $recoveryCode);
    }

    // ------------------------------------------------- Défi à la connexion

    /** Jeton à usage limité renvoyé après un mot de passe correct, à échanger contre un code 2FA. */
    public function issueChallenge(User $user, string $area): string
    {
        $token = Str::random(48);
        Cache::put($this->challengeKey($token), ['user_id' => $user->id, 'area' => $area, 'attempts' => 0], self::CHALLENGE_TTL);

        return $token;
    }

    /**
     * Valide le code fourni pour un défi. Le défi est détruit après réussite ou après
     * trop d'échecs (force brute du code à 6 chiffres impossible).
     */
    public function resolveChallenge(string $token, string $area, ?string $code, ?string $recoveryCode): ?User
    {
        $key = $this->challengeKey($token);
        $challenge = Cache::get($key);

        if (! $challenge || $challenge['area'] !== $area) {
            return null;
        }

        $user = User::find($challenge['user_id']);

        if (! $user || ! $user->status || ! $user->hasTwoFactorEnabled()) {
            Cache::forget($key);

            return null;
        }

        if ($this->verifyAny($user, $code, $recoveryCode)) {
            Cache::forget($key);

            return $user;
        }

        $challenge['attempts']++;
        $challenge['attempts'] >= self::CHALLENGE_MAX_ATTEMPTS
            ? Cache::forget($key)
            : Cache::put($key, $challenge, self::CHALLENGE_TTL);

        return null;
    }

    private function challengeKey(string $token): string
    {
        return '2fa:challenge:'.hash('sha256', $token);
    }

    // --------------------------------------------------------------- Base32

    private function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::BASE32[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    private function base32Decode(string $secret): string
    {
        $bits = '';
        foreach (str_split(strtoupper($secret)) as $char) {
            $bits .= str_pad(decbin((int) strpos(self::BASE32, $char)), 5, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }
}
