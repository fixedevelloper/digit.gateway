<?php

namespace Tests\Feature;

use App\Models\MerchantDocument;
use App\Models\MerchantKybEvent;
use App\Models\User;
use App\Notifications\KybReviewedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Dossier de vérification des marchands (KYB) : pièces, soumission, examen, et blocage du passage en production.
 */
class MerchantKybTest extends TestCase
{
    use RefreshDatabase;

    private const PROFILE = [
        'registration_number' => 'RC/DLA/2020/B/1234', 'tax_id' => 'M012000012345A', 'country' => 'Cameroun',
        'address' => 'Rue de la Joie, Douala', 'business_description' => 'Plateforme de paiement de salaires', 'expected_monthly_volume' => 5000000,
    ];

    private const REQUIRED = ['registration_certificate', 'tax_certificate', 'company_statutes', 'legal_rep_id', 'address_proof', 'beneficial_owners'];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function merchant(): User
    {
        // fresh() : recharge les valeurs par défaut de la base (kyb_status = incomplete).
        return User::factory()->merchant()->create()->fresh();
    }

    private function file(string $name = 'doc.pdf'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 100, 'application/pdf');
    }

    private function upload(User $merchant, string $type, ?UploadedFile $file = null, array $extra = [])
    {
        Sanctum::actingAs($merchant, ['*']);

        return $this->post('/api/merchants/kyb/documents', ['type' => $type, 'file' => $file ?? $this->file()] + $extra, ['Accept' => 'application/json']);
    }

    /** Dossier prêt à soumettre : profil + 6 pièces obligatoires. */
    private function completeDossier(User $merchant): void
    {
        Sanctum::actingAs($merchant, ['*']);
        $this->putJson('/api/merchants/kyb/profile', self::PROFILE)->assertOk();

        foreach (self::REQUIRED as $type) {
            $this->upload($merchant, $type, null, in_array($type, ['legal_rep_id', 'address_proof']) ? ['expires_at' => now()->addYear()->toDateString()] : [])->assertCreated();
        }
    }

    private function submitted(): User
    {
        $merchant = $this->merchant();
        $this->completeDossier($merchant);
        $this->postJson('/api/merchants/kyb/submit')->assertOk();

        return $merchant->fresh();
    }

    // ------------------------------------------------------------- Portail

    public function test_a_new_merchant_sees_the_checklist_with_nothing_submitted(): void
    {
        Sanctum::actingAs($this->merchant(), ['*']);

        $res = $this->getJson('/api/merchants/kyb')->assertOk()->assertJsonPath('data.kyb_status', 'incomplete')->assertJsonPath('data.can_submit', false);

        $types = collect($res->json('data.documents'));
        $this->assertSame(8, $types->count());
        $this->assertSame(self::REQUIRED, $types->where('required', true)->pluck('type')->values()->all());
        $this->assertNull($types->firstWhere('type', 'tax_certificate')['document']);
    }

    public function test_a_document_is_stored_privately_and_never_exposes_its_path(): void
    {
        $merchant = $this->merchant();

        $res = $this->upload($merchant, 'tax_certificate')->assertCreated();

        $doc = MerchantDocument::firstOrFail();
        Storage::disk('local')->assertExists($doc->path);
        $this->assertSame(64, strlen($doc->sha256));
        $this->assertSame('pending', $res->json('document.status'));
        foreach (['path', 'disk', 'sha256'] as $hidden) {
            $this->assertArrayNotHasKey($hidden, $res->json('document'));
        }
    }

    public function test_upload_validation(): void
    {
        $merchant = $this->merchant();

        $this->upload($merchant, 'passport_of_the_cat')->assertStatus(422)->assertJsonValidationErrors('type');
        $this->upload($merchant, 'tax_certificate', UploadedFile::fake()->create('x.exe', 10, 'application/octet-stream'))->assertStatus(422)->assertJsonValidationErrors('file');
        $this->upload($merchant, 'tax_certificate', UploadedFile::fake()->create('big.pdf', 6000, 'application/pdf'))->assertStatus(422)->assertJsonValidationErrors('file');
        $this->upload($merchant, 'legal_rep_id', null, ['expires_at' => now()->subDay()->toDateString()])->assertStatus(422)->assertJsonValidationErrors('expires_at');
        $this->assertSame(0, MerchantDocument::count());
    }

    public function test_replacing_a_document_removes_the_old_file_and_resets_its_status(): void
    {
        $merchant = $this->merchant();
        $this->upload($merchant, 'tax_certificate')->assertCreated();
        $first = MerchantDocument::firstOrFail();
        $first->update(['status' => 'rejected', 'rejection_reason' => 'illisible']);

        $this->upload($merchant, 'tax_certificate')->assertCreated();

        $this->assertSame(1, MerchantDocument::count());
        $doc = MerchantDocument::firstOrFail();
        Storage::disk('local')->assertMissing($first->path);
        Storage::disk('local')->assertExists($doc->path);
        $this->assertSame(['pending', null], [$doc->status, $doc->rejection_reason]);
    }

    public function test_the_dossier_cannot_be_submitted_until_complete(): void
    {
        $merchant = $this->merchant();
        Sanctum::actingAs($merchant, ['*']);
        $this->postJson('/api/merchants/kyb/submit')->assertStatus(422);

        $this->putJson('/api/merchants/kyb/profile', self::PROFILE)->assertOk();
        $this->upload($merchant, 'tax_certificate');
        $this->postJson('/api/merchants/kyb/submit')->assertStatus(422)->assertSee('incomplet');

        $this->putJson('/api/merchants/kyb/profile', ['registration_number' => ''] + self::PROFILE)->assertStatus(422);
    }

    public function test_a_complete_dossier_is_submitted_and_then_locked(): void
    {
        $merchant = $this->merchant();
        $this->completeDossier($merchant);
        $this->getJson('/api/merchants/kyb')->assertJsonPath('data.can_submit', true);

        $this->postJson('/api/merchants/kyb/submit')->assertOk();

        $this->assertSame('in_review', $merchant->fresh()->kyb_status);
        $this->upload($merchant, 'bank_statement')->assertStatus(422);
        $this->putJson('/api/merchants/kyb/profile', self::PROFILE)->assertStatus(422);
        $this->postJson('/api/merchants/kyb/submit')->assertStatus(422);
    }

    public function test_a_merchant_only_reads_his_own_documents(): void
    {
        $owner = $this->merchant();
        $this->upload($owner, 'tax_certificate');
        $id = MerchantDocument::firstOrFail()->id;

        Sanctum::actingAs($owner, ['*']);
        $this->get("/api/merchants/kyb/documents/{$id}/file")->assertOk();

        Sanctum::actingAs($this->merchant(), ['*']);
        $this->get("/api/merchants/kyb/documents/{$id}/file")->assertNotFound();
    }

    // ------------------------------------------------------------- Examen admin

    public function test_a_document_rejection_sends_the_dossier_back_with_a_notification(): void
    {
        $merchant = $this->submitted();
        $doc = $merchant->merchantDocuments()->where('type', 'tax_certificate')->firstOrFail();
        Sanctum::actingAs(User::factory()->admin()->create(), ['*']);

        $this->postJson("/api/admin/merchants/{$merchant->id}/kyb/documents/{$doc->id}/reject", [])->assertStatus(422);
        $this->postJson("/api/admin/merchants/{$merchant->id}/kyb/documents/{$doc->id}/reject", ['reason' => 'Document illisible'])->assertOk();

        $this->assertSame('incomplete', $merchant->fresh()->kyb_status);
        $this->assertSame('rejected', $doc->fresh()->status);
        $this->assertSame(1, $merchant->notifications()->where('type', KybReviewedNotification::class)->count());

        // Le marchand corrige, soumet à nouveau (modèle rechargé : en production, l'utilisateur est lu en base à chaque requête).
        $merchant = $merchant->fresh();
        $this->upload($merchant, 'tax_certificate')->assertCreated();
        $this->postJson('/api/merchants/kyb/submit')->assertOk();
        $this->assertSame('in_review', $merchant->fresh()->kyb_status);
    }

    public function test_the_merchant_is_also_emailed_with_the_reason_and_a_link_to_the_dossier(): void
    {
        config(['app.frontend_url' => 'https://admin.example.org']);
        $merchant = $this->submitted();
        $doc = $merchant->merchantDocuments()->where('type', 'tax_certificate')->firstOrFail();
        Sanctum::actingAs(User::factory()->admin()->create(), ['*']);

        $this->postJson("/api/admin/merchants/{$merchant->id}/kyb/documents/{$doc->id}/reject", ['reason' => 'Document illisible'])->assertOk();

        $messages = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $mail = $messages[0]->getOriginalMessage();
        $this->assertSame($merchant->email, $mail->getTo()[0]->getAddress());
        $this->assertStringContainsString('refusée', $mail->getSubject());
        $body = $mail->getHtmlBody();
        $this->assertStringContainsString('Document illisible', $body);
        $this->assertStringContainsString('https://admin.example.org/portal/dashboard/kyb', $body);
    }

    public function test_approval_and_global_rejection_are_emailed_too_without_a_link_when_approved(): void
    {
        $merchant = $this->submitted();
        Sanctum::actingAs(User::factory()->superadmin()->create(), ['*']);

        $this->postJson("/api/admin/merchants/{$merchant->id}/kyb/reject", ['reason' => 'Activité non couverte'])->assertOk();
        $messages = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('Activité non couverte', $messages[0]->getOriginalMessage()->getHtmlBody());

        // Un marchand sans e-mail reste notifié dans le portail, sans erreur.
        $noMail = $this->merchant();
        $noMail->forceFill(['email' => null])->save();
        $noMail->notify(new KybReviewedNotification('approved'));
        $this->assertSame(1, $noMail->notifications()->count());
        $this->assertCount(1, app('mailer')->getSymfonyTransport()->messages());
    }

    public function test_the_dossier_cannot_be_approved_while_a_required_document_is_not_approved(): void
    {
        $merchant = $this->submitted();
        Sanctum::actingAs(User::factory()->superadmin()->create(), ['*']);

        $this->postJson("/api/admin/merchants/{$merchant->id}/kyb/approve")->assertStatus(409);
        $this->assertSame('in_review', $merchant->fresh()->kyb_status);
    }

    public function test_full_approval_unlocks_the_production_switch(): void
    {
        $merchant = $this->submitted();
        $admin = User::factory()->superadmin()->create();
        Sanctum::actingAs($admin, ['*']);

        foreach ($merchant->merchantDocuments as $doc) {
            $this->postJson("/api/admin/merchants/{$merchant->id}/kyb/documents/{$doc->id}/approve")->assertOk();
        }

        // Avant l'approbation finale : refusé.
        $this->putJson("/api/admin/merchants/{$merchant->id}", ['environment' => 'production'])->assertStatus(422)->assertJsonPath('error_code', 'KYB_REQUIRED');

        $this->postJson("/api/admin/merchants/{$merchant->id}/kyb/approve")->assertOk();
        $this->assertSame('approved', $merchant->fresh()->kyb_status);
        $this->assertSame($admin->id, $merchant->fresh()->kyb_reviewed_by);
        $this->assertSame(1, $merchant->notifications()->count());

        $this->putJson("/api/admin/merchants/{$merchant->id}", ['environment' => 'production'])->assertOk();
        $this->assertSame('production', $merchant->fresh()->environment);
    }

    public function test_an_expired_required_document_blocks_the_final_approval(): void
    {
        $merchant = $this->submitted();
        Sanctum::actingAs(User::factory()->superadmin()->create(), ['*']);
        foreach ($merchant->merchantDocuments as $doc) {
            $this->postJson("/api/admin/merchants/{$merchant->id}/kyb/documents/{$doc->id}/approve")->assertOk();
        }
        $merchant->merchantDocuments()->where('type', 'address_proof')->update(['expires_at' => now()->subDay()]);

        $this->postJson("/api/admin/merchants/{$merchant->id}/kyb/approve")->assertStatus(409);
    }

    public function test_documents_can_be_reviewed_and_the_dossier_approved_without_waiting_for_the_submission(): void
    {
        // Dossier « incomplet » : le marchand a tout déposé mais n'a pas cliqué sur « Soumettre ».
        $merchant = $this->merchant();
        $this->completeDossier($merchant);
        $this->assertSame('incomplete', $merchant->fresh()->kyb_status);
        $admin = User::factory()->superadmin()->create();
        Sanctum::actingAs($admin, ['*']);

        // Avant validation, l'équipe voit précisément ce qui bloque.
        $overview = $this->getJson("/api/admin/merchants/{$merchant->id}/kyb")->assertOk()->assertJsonPath('data.submitted', false);
        $this->assertContains('Pièce à valider : Registre de commerce / immatriculation', $overview->json('data.blockers'));
        $this->postJson("/api/admin/merchants/{$merchant->id}/kyb/approve")->assertStatus(409);

        foreach ($merchant->merchantDocuments as $doc) {
            $this->postJson("/api/admin/merchants/{$merchant->id}/kyb/documents/{$doc->id}/approve")->assertOk();
        }

        $this->getJson("/api/admin/merchants/{$merchant->id}/kyb")->assertJsonPath('data.blockers', []);
        $this->postJson("/api/admin/merchants/{$merchant->id}/kyb/approve")->assertOk();
        $this->assertSame('approved', $merchant->fresh()->kyb_status);
    }

    public function test_the_blockers_list_missing_expired_rejected_and_profile_problems(): void
    {
        $merchant = $this->merchant();
        $this->upload($merchant, 'tax_certificate');
        $this->upload($merchant, 'legal_rep_id', null, ['expires_at' => now()->addDay()->toDateString()]);
        $merchant->merchantDocuments()->where('type', 'legal_rep_id')->update(['expires_at' => now()->subDay()]);
        $merchant->merchantDocuments()->where('type', 'tax_certificate')->update(['status' => 'rejected', 'rejection_reason' => 'flou']);
        Sanctum::actingAs(User::factory()->admin()->create(), ['*']);

        $blockers = $this->getJson("/api/admin/merchants/{$merchant->id}/kyb")->json('data.blockers');

        $this->assertContains('Informations d\'entreprise non renseignées ou incomplètes', $blockers);
        $this->assertContains('Pièce manquante : Statuts de la société', $blockers);
        $this->assertContains('Pièce expirée : Pièce d\'identité du représentant légal', $blockers);
        $this->assertContains('Pièce refusée : Attestation d\'identifiant fiscal (NIF / NIU)', $blockers);
    }

    public function test_a_document_rejected_before_submission_keeps_the_dossier_incomplete_and_notifies(): void
    {
        $merchant = $this->merchant();
        $this->completeDossier($merchant);
        $doc = $merchant->merchantDocuments()->where('type', 'address_proof')->firstOrFail();
        Sanctum::actingAs(User::factory()->admin()->create(), ['*']);

        $this->postJson("/api/admin/merchants/{$merchant->id}/kyb/documents/{$doc->id}/reject", ['reason' => 'Facture trop ancienne'])->assertOk();

        $this->assertSame('incomplete', $merchant->fresh()->kyb_status);
        $this->assertSame(1, $merchant->notifications()->where('type', KybReviewedNotification::class)->count());
    }

    public function test_an_approved_dossier_can_no_longer_be_reviewed_or_decided_again(): void
    {
        $merchant = $this->merchant();
        $merchant->forceFill(['kyb_status' => 'approved'])->save();
        $this->upload($merchant->fresh(), 'bank_statement')->assertCreated(); // un remplacement le renvoie en examen
        User::whereKey($merchant->id)->update(['kyb_status' => 'approved']);
        $doc = $merchant->merchantDocuments()->firstOrFail();
        Sanctum::actingAs(User::factory()->superadmin()->create(), ['*']);

        $this->postJson("/api/admin/merchants/{$merchant->id}/kyb/documents/{$doc->id}/approve")->assertStatus(409);
        $this->postJson("/api/admin/merchants/{$merchant->id}/kyb/approve")->assertStatus(409);
        $this->postJson("/api/admin/merchants/{$merchant->id}/kyb/reject", ['reason' => 'trop tard'])->assertStatus(409);
    }

    public function test_a_global_rejection_requires_a_reason_and_allows_resubmission(): void
    {
        $merchant = $this->submitted();
        Sanctum::actingAs(User::factory()->superadmin()->create(), ['*']);

        $this->postJson("/api/admin/merchants/{$merchant->id}/kyb/reject", [])->assertStatus(422);
        $this->postJson("/api/admin/merchants/{$merchant->id}/kyb/reject", ['reason' => 'Activité non couverte'])->assertOk();

        $this->assertSame(['rejected', 'Activité non couverte'], [$merchant->fresh()->kyb_status, $merchant->fresh()->kyb_rejection_reason]);

        Sanctum::actingAs($merchant->fresh(), ['*']);
        $this->postJson('/api/merchants/kyb/submit')->assertOk();
        $this->assertSame('in_review', $merchant->fresh()->kyb_status);
    }

    public function test_the_final_decision_is_superadmin_only_and_a_plain_admin_cannot_approve_the_dossier(): void
    {
        $merchant = $this->submitted();
        Sanctum::actingAs(User::factory()->admin()->create(), ['*']);

        $this->postJson("/api/admin/merchants/{$merchant->id}/kyb/approve")->assertForbidden();
        $this->postJson("/api/admin/merchants/{$merchant->id}/kyb/reject", ['reason' => 'non'])->assertForbidden();
        $this->assertSame('in_review', $merchant->fresh()->kyb_status);
    }

    public function test_the_final_decision_requires_2fa_when_enforced(): void
    {
        config(['security.require_admin_2fa' => true]);
        $merchant = $this->submitted();
        Sanctum::actingAs(User::factory()->superadmin()->create(), ['*']);

        $this->postJson("/api/admin/merchants/{$merchant->id}/kyb/approve")->assertForbidden()->assertJsonPath('error_code', 'TWO_FACTOR_REQUIRED');
    }

    public function test_replacing_a_document_on_an_approved_dossier_sends_it_back_to_review_without_touching_production(): void
    {
        $merchant = $this->merchant();
        $merchant->forceFill(['kyb_status' => 'approved', 'environment' => 'production'])->save();

        $this->upload($merchant, 'address_proof')->assertCreated();

        $fresh = $merchant->fresh();
        $this->assertSame('in_review', $fresh->kyb_status);
        $this->assertSame('production', $fresh->environment);
    }

    public function test_an_unfinished_dossier_cannot_get_production_and_existing_production_merchants_are_unaffected(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create(), ['*']);
        $sandbox = $this->merchant();
        $live = User::factory()->merchant()->create(['environment' => 'production']);

        $this->putJson("/api/admin/merchants/{$sandbox->id}", ['environment' => 'production'])->assertStatus(422)->assertJsonPath('error_code', 'KYB_REQUIRED');
        $this->assertSame('sandbox', $sandbox->fresh()->environment);

        // Un marchand déjà en production reste modifiable (suspension, nom...), sans dossier approuvé.
        $this->putJson("/api/admin/merchants/{$live->id}", ['environment' => 'production', 'company_name' => 'Acme 2'])->assertOk();
        $this->putJson("/api/admin/merchants/{$sandbox->id}", ['environment' => 'sandbox', 'status' => false])->assertOk();
    }

    public function test_every_step_is_audited_including_document_views(): void
    {
        $merchant = $this->submitted();
        $admin = User::factory()->admin()->create();
        $doc = $merchant->merchantDocuments()->firstOrFail();
        Sanctum::actingAs($admin, ['*']);

        $this->get("/api/admin/merchants/{$merchant->id}/kyb/documents/{$doc->id}/file")->assertOk();

        $actions = MerchantKybEvent::where('user_id', $merchant->id)->pluck('action');
        $this->assertContains('document_uploaded', $actions);
        $this->assertContains('submitted', $actions);
        $view = MerchantKybEvent::where('action', 'document_viewed')->firstOrFail();
        $this->assertSame([$admin->id, $doc->type], [$view->actor_id, $view->document_type]);

        $this->expectException(\LogicException::class);
        $view->update(['comment' => 'altéré']);
    }

    public function test_admin_overview_shows_the_checklist_and_events(): void
    {
        $merchant = $this->submitted();
        Sanctum::actingAs(User::factory()->admin()->create(), ['*']);

        $this->getJson("/api/admin/merchants/{$merchant->id}/kyb")->assertOk()
            ->assertJsonPath('data.kyb_status', 'in_review')->assertJsonPath('data.merchant.id', $merchant->id)
            ->assertJsonPath('data.profile.country', 'Cameroun')->assertJsonStructure(['data' => ['events', 'documents']]);

        $this->getJson('/api/admin/merchants')->assertOk()->assertJsonPath('0.kyb_status', 'in_review')->assertJsonPath('0.merchant_documents_count', 6);
    }

    // -------------------------------------------------------------------- Droits

    public function test_customers_agents_and_other_merchants_have_no_access(): void
    {
        $merchant = $this->submitted();
        $doc = $merchant->merchantDocuments()->firstOrFail();

        foreach ([User::factory()->create(), User::factory()->agent()->create()] as $user) {
            Sanctum::actingAs($user, ['*']);
            $this->getJson('/api/merchants/kyb')->assertForbidden();
            $this->getJson("/api/admin/merchants/{$merchant->id}/kyb")->assertForbidden();
            $this->get("/api/admin/merchants/{$merchant->id}/kyb/documents/{$doc->id}/file")->assertForbidden();
            $this->postJson("/api/admin/merchants/{$merchant->id}/kyb/documents/{$doc->id}/approve")->assertForbidden();
        }

        // Un marchand n'accède pas à la console admin.
        Sanctum::actingAs($this->merchant(), ['*']);
        $this->getJson("/api/admin/merchants/{$merchant->id}/kyb")->assertForbidden();
    }

    // ------------------------------------------- Dépôt par l'équipe (pour le marchand)

    private function adminUpload(User $merchant, string $type, array $extra = [], ?UploadedFile $file = null)
    {
        return $this->post("/api/admin/merchants/{$merchant->id}/kyb/documents", array_merge(['type' => $type, 'file' => $file ?? $this->file(), 'comment' => 'Reçu par e-mail le 03/10'], $extra), ['Accept' => 'application/json']);
    }

    public function test_the_team_deposits_a_document_for_the_merchant_traced_and_still_pending(): void
    {
        $merchant = $this->merchant();
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin, ['*']);

        $res = $this->adminUpload($merchant, 'tax_certificate')->assertCreated();

        $doc = MerchantDocument::firstOrFail();
        $this->assertSame([$merchant->id, 'pending'], [$doc->user_id, $doc->status]); // à valider ensuite : le dépôt ne vaut pas validation
        Storage::disk('local')->assertExists($doc->path);
        $this->assertArrayNotHasKey('path', $res->json('data'));

        $event = MerchantKybEvent::where('action', 'document_uploaded_by_team')->firstOrFail();
        $this->assertSame([$admin->id, 'tax_certificate', 'Reçu par e-mail le 03/10'], [$event->actor_id, $event->document_type, $event->comment]);
        $this->assertSame(1, $merchant->notifications()->where('type', KybReviewedNotification::class)->count());
    }

    public function test_a_justification_is_mandatory_and_files_are_validated_like_the_merchants(): void
    {
        $merchant = $this->merchant();
        Sanctum::actingAs(User::factory()->admin()->create(), ['*']);

        $this->adminUpload($merchant, 'tax_certificate', ['comment' => ''])->assertStatus(422)->assertJsonValidationErrors('comment');
        $this->adminUpload($merchant, 'inconnu')->assertStatus(422)->assertJsonValidationErrors('type');
        $this->adminUpload($merchant, 'tax_certificate', [], UploadedFile::fake()->create('x.exe', 10, 'application/octet-stream'))->assertStatus(422)->assertJsonValidationErrors('file');
        $this->adminUpload($merchant, 'tax_certificate', [], UploadedFile::fake()->create('big.pdf', 6000, 'application/pdf'))->assertStatus(422);
        $this->assertSame(0, MerchantDocument::count());
    }

    public function test_the_team_can_deposit_even_while_the_dossier_is_locked_in_review_and_replaces_the_old_file(): void
    {
        $merchant = $this->submitted();
        $old = $merchant->merchantDocuments()->where('type', 'tax_certificate')->firstOrFail();
        Sanctum::actingAs(User::factory()->admin()->create(), ['*']);

        // Le marchand, lui, est bloqué.
        Sanctum::actingAs($merchant, ['*']);
        $this->upload($merchant, 'tax_certificate')->assertStatus(422);

        Sanctum::actingAs(User::factory()->admin()->create(), ['*']);
        $this->adminUpload($merchant, 'tax_certificate')->assertCreated();

        Storage::disk('local')->assertMissing($old->path);
        $this->assertSame(1, $merchant->merchantDocuments()->where('type', 'tax_certificate')->count());
        $this->assertSame('in_review', $merchant->fresh()->kyb_status);
        $this->assertTrue(MerchantKybEvent::where('action', 'document_replaced_by_team')->exists());
    }

    public function test_the_team_can_fill_the_profile_and_a_whole_dossier_received_by_email_can_be_approved(): void
    {
        $merchant = $this->merchant();
        $superadmin = User::factory()->superadmin()->create();
        Sanctum::actingAs($superadmin, ['*']);

        $this->putJson("/api/admin/merchants/{$merchant->id}/kyb/profile", self::PROFILE)->assertStatus(422)->assertJsonValidationErrors('comment');
        $this->putJson("/api/admin/merchants/{$merchant->id}/kyb/profile", self::PROFILE + ['comment' => 'Saisi d\'après le dossier papier'])->assertOk();
        $this->assertTrue(MerchantKybEvent::where('action', 'profile_updated_by_team')->where('comment', 'Saisi d\'après le dossier papier')->exists());

        foreach (self::REQUIRED as $type) {
            $this->adminUpload($merchant, $type, in_array($type, ['legal_rep_id', 'address_proof']) ? ['expires_at' => now()->addYear()->toDateString()] : [])->assertCreated();
        }
        foreach ($merchant->merchantDocuments as $doc) {
            $this->postJson("/api/admin/merchants/{$merchant->id}/kyb/documents/{$doc->id}/approve")->assertOk();
        }

        $this->getJson("/api/admin/merchants/{$merchant->id}/kyb")->assertJsonPath('data.blockers', []);
        $this->postJson("/api/admin/merchants/{$merchant->id}/kyb/approve")->assertOk();
        $this->assertSame('approved', $merchant->fresh()->kyb_status);
    }

    public function test_deposits_for_a_merchant_need_2fa_and_are_closed_to_everybody_else(): void
    {
        $merchant = $this->merchant();

        config(['security.require_admin_2fa' => true]);
        Sanctum::actingAs(User::factory()->admin()->create(), ['*']);
        $this->adminUpload($merchant, 'tax_certificate')->assertForbidden()->assertJsonPath('error_code', 'TWO_FACTOR_REQUIRED');
        $this->putJson("/api/admin/merchants/{$merchant->id}/kyb/profile", self::PROFILE + ['comment' => 'x y z'])->assertForbidden();
        config(['security.require_admin_2fa' => false]);

        foreach ([User::factory()->create(), User::factory()->agent()->create(), $this->merchant()] as $user) {
            Sanctum::actingAs($user, ['*']);
            $this->adminUpload($merchant, 'tax_certificate')->assertForbidden();
            $this->putJson("/api/admin/merchants/{$merchant->id}/kyb/profile", self::PROFILE + ['comment' => 'x y z'])->assertForbidden();
        }

        Sanctum::actingAs(User::factory()->admin()->create(), ['*']);
        $this->adminUpload(User::factory()->create(), 'tax_certificate')->assertNotFound(); // un client n'est pas un marchand
        $this->assertSame(0, MerchantDocument::count());
    }

    // ------------------------------------------------------ Rappels d'échéance

    private function liveMerchant(int $daysLeft, array $overrides = []): User
    {
        $merchant = User::factory()->merchant()->create(array_merge(['environment' => 'production'], $overrides))->fresh();
        $merchant->forceFill(['kyb_grace_until' => now()->startOfDay()->addDays($daysLeft)->setTime(10, 0)])->save();

        return $merchant->fresh();
    }

    private function reminders(User $merchant)
    {
        return $merchant->notifications()->where('type', \App\Notifications\KybGraceReminderNotification::class)->get();
    }

    public function test_reminders_go_out_at_each_stage_exactly_once(): void
    {
        $merchant = $this->liveMerchant(20);

        $this->artisan('kyb:remind-grace')->assertExitCode(0);
        $this->assertCount(0, $this->reminders($merchant), 'trop tôt : J-20');

        $start = now()->startOfDay();
        $expected = [14 => 1, 13 => 1, 7 => 2, 5 => 2, 3 => 3, 1 => 4, 0 => 5];
        foreach ($expected as $left => $stage) {
            $this->travelTo($start->copy()->addDays(20 - $left)->setTime(9, 0));
            $this->artisan('kyb:remind-grace');
            $this->artisan('kyb:remind-grace'); // un 2e passage le même jour n'envoie rien de plus
            $this->assertSame($stage, $merchant->fresh()->kyb_reminder_stage, "J-{$left}");
        }

        $this->assertCount(5, $this->reminders($merchant));
        $this->assertCount(5, app('mailer')->getSymfonyTransport()->messages());
    }

    public function test_a_missed_period_sends_only_the_most_advanced_stage(): void
    {
        $merchant = $this->liveMerchant(-2);

        $this->artisan('kyb:remind-grace');

        $this->assertCount(1, $this->reminders($merchant));
        $this->assertSame(5, $merchant->fresh()->kyb_reminder_stage);
        $this->assertTrue($this->reminders($merchant)->first()->data['overdue']);
    }

    public function test_the_reminder_email_states_the_deadline_and_links_to_the_dossier(): void
    {
        config(['app.frontend_url' => 'https://admin.example.org']);
        $merchant = $this->liveMerchant(3);

        $this->artisan('kyb:remind-grace');

        $mail = app('mailer')->getSymfonyTransport()->messages()[0]->getOriginalMessage();
        $this->assertSame($merchant->email, $mail->getTo()[0]->getAddress());
        $this->assertStringContainsString('3 jour(s)', $mail->getSubject());
        $this->assertStringContainsString($merchant->kyb_grace_until->format('d/m/Y'), $mail->getHtmlBody());
        $this->assertStringContainsString('https://admin.example.org/portal/dashboard/kyb', $mail->getHtmlBody());
        $this->assertSame(3, $this->reminders($merchant)->first()->data['days_left']);
    }

    public function test_no_reminder_for_approved_submitted_suspended_sandbox_or_deadline_free_merchants(): void
    {
        $approved = $this->liveMerchant(2, ['kyb_status' => 'approved']);
        $inReview = $this->liveMerchant(2, ['kyb_status' => 'in_review']);
        $suspended = $this->liveMerchant(2, ['status' => false]);
        $sandbox = $this->liveMerchant(2, ['environment' => 'sandbox']);
        $noDeadline = $this->liveMerchant(2);
        $noDeadline->forceFill(['kyb_grace_until' => null])->save();
        $customer = User::factory()->create();
        $customer->forceFill(['kyb_grace_until' => now()->addDay()])->save();

        $this->artisan('kyb:remind-grace')->assertExitCode(0);

        foreach ([$approved, $inReview, $suspended, $sandbox, $noDeadline, $customer] as $user) {
            $this->assertCount(0, $this->reminders($user), "{$user->id}");
        }
    }

    public function test_a_rejected_dossier_still_gets_reminders_and_approval_ends_them(): void
    {
        $merchant = $this->liveMerchant(1, ['kyb_status' => 'rejected']);
        $this->artisan('kyb:remind-grace');
        $this->assertCount(1, $this->reminders($merchant));

        // Dossier approuvé : le délai est levé, plus de rappel.
        $merchant->forceFill(['kyb_status' => 'approved', 'kyb_grace_until' => null, 'kyb_reminder_stage' => 0])->save();
        $this->travelTo(now()->addDays(3));
        $this->artisan('kyb:remind-grace');
        $this->assertCount(1, $this->reminders($merchant));
    }

    public function test_the_command_is_scheduled_daily(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('kyb:remind-grace')->assertExitCode(0);
    }
}
