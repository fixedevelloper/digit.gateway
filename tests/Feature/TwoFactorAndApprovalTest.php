<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WalletAdjustment;
use App\Notifications\AdjustmentPendingNotification;
use App\Notifications\AdjustmentReviewedNotification;
use App\Services\Security\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TwoFactorAndApprovalTest extends TestCase
{
    use RefreshDatabase;

    private TwoFactorService $twoFactor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->twoFactor = app(TwoFactorService::class);
    }

    private function admin(string $state = 'admin', bool $with2fa = false): User
    {
        $user = User::factory()->{$state}()->create(['password' => Hash::make('secret123')]);

        if ($with2fa) {
            $user->forceFill(['two_factor_secret' => $this->twoFactor->generateSecret(), 'two_factor_confirmed_at' => now()])->save();
        }

        return $user->fresh();
    }

    /** Code valide pour le pas de temps suivant celui déjà consommé, pour enchaîner plusieurs vérifications. */
    private function validCode(User $user): string
    {
        $step = intdiv(time(), 30);
        $user = $user->fresh();
        // Le pas courant, ou le suivant si déjà consommé : tous deux sont dans la fenêtre ±1.
        $candidate = ($user->two_factor_last_step ?? 0) >= $step ? $step + 1 : $step;

        return $this->twoFactor->codeAt($user->two_factor_secret, $candidate);
    }

    // ------------------------------------------------------------------- TOTP

    public function test_totp_matches_the_rfc6238_test_vector(): void
    {
        // RFC 6238, secret ASCII "12345678901234567890", T=59 s → 94287082 (8 chiffres) ; 6 chiffres = 287082.
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

        $this->assertSame('287082', $this->twoFactor->codeAt($secret, intdiv(59, 30)));
    }

    public function test_enrollment_requires_password_then_a_valid_code_and_returns_recovery_codes_once(): void
    {
        $user = $this->admin();
        Sanctum::actingAs($user, ['*']);

        $this->postJson('/api/admin/2fa/setup', ['password' => 'wrong'])->assertStatus(422);
        $setup = $this->postJson('/api/admin/2fa/setup', ['password' => 'secret123'])->assertOk();
        $this->assertStringStartsWith('otpauth://totp/', $setup->json('data.otpauth_url'));
        $this->assertFalse($user->fresh()->hasTwoFactorEnabled());

        $this->postJson('/api/admin/2fa/confirm', ['code' => '000000'])->assertStatus(422);
        $res = $this->postJson('/api/admin/2fa/confirm', ['code' => $this->validCode($user)])->assertOk();

        $this->assertCount(8, $res->json('data.recovery_codes'));
        $this->assertTrue($user->fresh()->hasTwoFactorEnabled());
        $this->getJson('/api/admin/2fa')->assertJsonPath('data.recovery_codes_left', 8)->assertJsonMissingPath('data.recovery_codes');
    }

    public function test_a_used_code_cannot_be_replayed(): void
    {
        $user = $this->admin('admin', true);
        $code = $this->twoFactor->codeAt($user->two_factor_secret, intdiv(time(), 30));

        $this->assertTrue($this->twoFactor->verifyCode($user, $code));
        $this->assertFalse($this->twoFactor->verifyCode($user->fresh(), $code));
    }

    // ------------------------------------------------------------- Connexion

    public function test_admin_login_with_2fa_returns_a_challenge_and_no_token(): void
    {
        $admin = $this->admin('admin', true);

        $res = $this->postJson('/api/admin/auth/login', ['phone' => $admin->phone, 'password' => 'secret123'])
            ->assertOk()->assertJsonPath('status', 'two_factor_required')->assertJsonMissingPath('token');

        $this->postJson('/api/admin/auth/2fa', ['challenge' => $res->json('challenge'), 'code' => $this->validCode($admin)])
            ->assertOk()->assertJsonPath('status', 'success')->assertJsonStructure(['token']);

        // Le défi est à usage unique.
        $this->postJson('/api/admin/auth/2fa', ['challenge' => $res->json('challenge'), 'code' => $this->validCode($admin)])
            ->assertStatus(422);
    }

    public function test_challenge_is_destroyed_after_five_wrong_codes(): void
    {
        $admin = $this->admin('admin', true);
        $challenge = $this->twoFactor->issueChallenge($admin, 'admin');

        // Appel direct du service : la route est de toute façon limitée par le throttle `auth`.
        foreach (range(1, 5) as $i) {
            $this->assertNull($this->twoFactor->resolveChallenge($challenge, 'admin', '12345'.$i, null));
        }

        // Même avec le bon code, le défi n'existe plus.
        $this->assertNull($this->twoFactor->resolveChallenge($challenge, 'admin', $this->validCode($admin), null));
    }

    public function test_recovery_code_logs_in_once_and_is_consumed(): void
    {
        $admin = $this->admin('admin', true);
        [$first] = $this->twoFactor->generateRecoveryCodes($admin);

        $login = fn () => $this->postJson('/api/admin/auth/login', ['phone' => $admin->phone, 'password' => 'secret123'])->json('challenge');

        $this->postJson('/api/admin/auth/2fa', ['challenge' => $login(), 'recovery_code' => $first])->assertOk();
        $this->postJson('/api/admin/auth/2fa', ['challenge' => $login(), 'recovery_code' => $first])->assertStatus(422);
    }

    public function test_a_challenge_from_the_merchant_area_cannot_open_an_admin_session(): void
    {
        $merchant = User::factory()->merchant()->create(['password' => Hash::make('secret123')]);
        $merchant->forceFill(['two_factor_secret' => $this->twoFactor->generateSecret(), 'two_factor_confirmed_at' => now()])->save();

        $challenge = $this->postJson('/api/merchants/login', ['email' => $merchant->email, 'password' => 'secret123'])
            ->assertJsonPath('status', 'two_factor_required')->json('challenge');

        $this->postJson('/api/admin/auth/2fa', ['challenge' => $challenge, 'code' => $this->validCode($merchant)])->assertStatus(422);
        $this->postJson('/api/merchants/2fa', ['challenge' => $challenge, 'code' => $this->validCode($merchant)])
            ->assertOk()->assertJsonStructure(['token']);
    }

    public function test_disabling_needs_password_and_a_code(): void
    {
        $admin = $this->admin('admin', true);
        Sanctum::actingAs($admin, ['*']);

        $this->postJson('/api/admin/2fa/disable', ['password' => 'secret123', 'code' => '000000'])->assertStatus(422);
        $this->postJson('/api/admin/2fa/disable', ['password' => 'secret123', 'code' => $this->validCode($admin)])->assertOk();
        $this->assertFalse($admin->fresh()->hasTwoFactorEnabled());
    }

    // ------------------------------------------------- Actions sensibles / 2FA

    public function test_sensitive_actions_require_2fa_when_enforced(): void
    {
        config(['security.require_admin_2fa' => true]);
        $target = User::factory()->create();
        $payload = ['type' => 'credit', 'amount' => 100, 'reason' => 'Régularisation test'];

        Sanctum::actingAs($this->admin('superadmin'), ['*']);
        $this->postJson("/api/admin/wallets/{$target->wallet->id}/adjust", $payload)
            ->assertStatus(403)->assertJsonPath('error_code', 'TWO_FACTOR_REQUIRED');

        Sanctum::actingAs($this->admin('superadmin', true), ['*']);
        $this->postJson("/api/admin/wallets/{$target->wallet->id}/adjust", $payload)->assertOk();
    }

    // ------------------------------------------------------ Double validation

    private function adjust(User $by, User $target, float $amount, string $type = 'credit')
    {
        Sanctum::actingAs($by, ['*']);

        return $this->postJson("/api/admin/wallets/{$target->wallet->id}/adjust", [
            'type' => $type, 'amount' => $amount, 'reason' => 'Régularisation test',
        ]);
    }

    public function test_superadmin_applies_small_adjustments_directly(): void
    {
        $target = User::factory()->create();
        $this->adjust($this->admin('superadmin'), $target, 500)->assertOk();

        $this->assertSame(500.0, (float) $target->wallet->fresh()->balance);
    }

    public function test_large_adjustment_waits_for_another_superadmin(): void
    {
        $requester = $this->admin('superadmin');
        $approver = $this->admin('superadmin');
        $target = User::factory()->create();
        $target->wallet()->update(['balance' => 1000]);

        $res = $this->adjust($requester, $target, 250000)->assertStatus(202);
        $id = $res->json('data.id');

        $this->assertSame(1000.0, (float) $target->wallet->fresh()->balance);
        $this->assertSame(1, $approver->notifications()->where('type', AdjustmentPendingNotification::class)->count());
        $this->assertSame(0, $requester->notifications()->count());

        // Pas d'auto-approbation.
        Sanctum::actingAs($requester, ['*']);
        $this->postJson("/api/admin/wallet-adjustments/{$id}/approve")->assertStatus(403)->assertJsonPath('error_code', 'SELF_APPROVAL');

        Sanctum::actingAs($approver, ['*']);
        $this->postJson("/api/admin/wallet-adjustments/{$id}/approve")->assertOk();
        $this->assertSame(251000.0, (float) $target->wallet->fresh()->balance);
        $this->assertSame(1, $requester->notifications()->where('type', AdjustmentReviewedNotification::class)->count());

        // Une seule application, jamais deux.
        $this->postJson("/api/admin/wallet-adjustments/{$id}/approve")->assertStatus(409);
        $this->assertSame(251000.0, (float) $target->wallet->fresh()->balance);

        $adjustment = WalletAdjustment::find($id);
        $this->assertSame($approver->id, $adjustment->reviewed_by);
        $this->assertEquals(1000, $adjustment->balance_before);
    }

    public function test_plain_admin_cannot_approve_and_rejection_leaves_balance_untouched(): void
    {
        $target = User::factory()->create();
        $id = $this->adjust($this->admin('admin'), $target, 300)->assertStatus(202)->json('data.id');

        Sanctum::actingAs($this->admin('admin'), ['*']);
        $this->postJson("/api/admin/wallet-adjustments/{$id}/approve")->assertForbidden();
        $this->getJson('/api/admin/wallet-adjustments')->assertOk()->assertJsonPath('data.0.id', $id);

        Sanctum::actingAs($this->admin('superadmin'), ['*']);
        $this->postJson("/api/admin/wallet-adjustments/{$id}/reject", [])->assertStatus(422);
        $this->postJson("/api/admin/wallet-adjustments/{$id}/reject", ['reason' => 'Justificatif manquant'])->assertOk();
        $this->postJson("/api/admin/wallet-adjustments/{$id}/approve")->assertStatus(409);

        $this->assertSame(0.0, (float) $target->wallet->fresh()->balance);
    }

    public function test_pending_debit_is_rechecked_against_the_balance_at_approval(): void
    {
        $target = User::factory()->create();
        $target->wallet()->update(['balance' => 1000]);
        $id = $this->adjust($this->admin('admin'), $target, 800, 'debit')->assertStatus(202)->json('data.id');

        // Le solde baisse entre la demande et l'approbation.
        $target->wallet()->update(['balance' => 100]);

        Sanctum::actingAs($this->admin('superadmin'), ['*']);
        $this->postJson("/api/admin/wallet-adjustments/{$id}/approve")->assertStatus(409);

        $this->assertSame(100.0, (float) $target->wallet->fresh()->balance);
        $this->assertSame('pending', WalletAdjustment::find($id)->status);
    }
}
