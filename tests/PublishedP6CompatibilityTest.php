<?php

declare(strict_types=1);

namespace ArtisanBuild\BfcClient\Tests;

use ArtisanBuild\BfcClient\BfcContract;
use ArtisanBuild\BfcClient\BfcHeaders;
use ArtisanBuild\BuiltForCloud\BuiltForCloud;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\CredentialStatus;
use ArtisanBuild\BuiltForCloud\HttpContract;
use ArtisanBuild\BuiltForCloud\SubjectType;
use ArtisanBuild\BuiltForCloud\Testing\P6GateContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

final class PublishedP6CompatibilityTest extends P6TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::post('/p6-client-probe', static function (Request $request): array {
            return [
                'purpose' => $request->user()?->purpose?->value,
                'subject_ref' => $request->user()?->subject_ref,
                'client_id' => $request->header(BfcHeaders::CLIENT_ID),
            ];
        })->middleware(['bfc.contract-major', 'bfc.mcp']);
    }

    public function test_client_contract_is_frozen_to_the_published_p6_contract(): void
    {
        $this->assertSame(2, BfcContract::MAJOR);
        $this->assertSame(BuiltForCloud::API_VERSION, BfcContract::MAJOR);
        $this->assertSame(HttpContract::MAJOR_HEADER, BfcHeaders::CONTRACT_VERSION);
        $this->assertSame(P6GateContract::CONTRACT_MAJOR, BfcContract::MAJOR);
        $this->assertSame(P6GateContract::CONTRACT_HEADER, BfcHeaders::CONTRACT_VERSION);
        $this->assertSame([
            'contract_major_accepted',
            'contract_major_missing_refused',
            'contract_major_malformed_refused',
            'contract_major_duplicate_field_refused',
            'contract_major_unsupported_refused',
        ], array_values(array_filter(
            P6GateContract::LIVE_CASES,
            static fn (string $case): bool => str_starts_with($case, 'contract_major_'),
        )));
    }

    public function test_p6_admits_only_the_canonical_version_and_uses_stable_refusals(): void
    {
        $this->postJson('/p6-client-probe')->assertStatus(400)->assertExactJson([
            'error' => 'missing_contract_major',
            'supported_contract_major' => 2,
        ])->assertHeaderMissing('Retry-After');

        foreach (['', '02', '2,2', '+2', 'major-marker'] as $malformed) {
            $response = $this->withHeader(BfcHeaders::CONTRACT_VERSION, $malformed)->postJson('/p6-client-probe');
            $response->assertStatus(400)->assertExactJson([
                'error' => 'malformed_contract_major',
                'supported_contract_major' => 2,
            ])->assertHeaderMissing('Retry-After');
            if ($malformed !== '') {
                $this->assertStringNotContainsString($malformed, $response->getContent());
            }
        }

        $this->withHeader(BfcHeaders::CONTRACT_VERSION, '3')->postJson('/p6-client-probe')
            ->assertStatus(426)
            ->assertExactJson(['error' => 'unsupported_contract_major', 'supported_contract_major' => 2])
            ->assertHeaderMissing('Retry-After');
    }

    public function test_fixed_purpose_and_lifecycle_own_authority_while_client_metadata_does_not(): void
    {
        $valid = $this->mintCredential([
            'purpose' => CredentialPurpose::Mcp,
            'subject_type' => SubjectType::Installation,
            'subject_ref' => 'fixture-installation-a',
        ]);
        $wrongPurpose = $this->mintCredential([
            'purpose' => CredentialPurpose::Consumption,
            'subject_type' => SubjectType::Installation,
            'subject_ref' => 'fixture-installation-a',
        ]);
        $revoked = $this->mintCredential([
            'purpose' => CredentialPurpose::Mcp,
            'subject_type' => SubjectType::Installation,
            'subject_ref' => 'fixture-installation-a',
            'revoked_at' => now(),
        ]);
        $expired = $this->mintCredential([
            'purpose' => CredentialPurpose::Mcp,
            'subject_type' => SubjectType::Installation,
            'subject_ref' => 'fixture-installation-a',
            'expires_at' => now()->subSecond(),
        ]);
        $headers = static fn (string $secret, string $client): array => [
            BfcHeaders::CONTRACT_VERSION => (string) BfcContract::MAJOR,
            BfcHeaders::CLIENT_ID => $client,
            'Authorization' => 'Bearer '.$secret,
        ];

        foreach (['metadata-a', 'spoofed-installation-b'] as $clientId) {
            $this->postJson('/p6-client-probe', [
                'purpose' => CredentialPurpose::OperatorManagement->value,
                'role' => 'owner',
                'acl' => ['*'],
            ], $headers($valid->plaintext(), $clientId))->assertOk()->assertExactJson([
                'purpose' => CredentialPurpose::Mcp->value,
                'subject_ref' => 'fixture-installation-a',
                'client_id' => $clientId,
            ]);
        }

        foreach ([$wrongPurpose, $revoked, $expired] as $refused) {
            $this->postJson('/p6-client-probe', [], $headers($refused->plaintext(), 'claimed-authority'))
                ->assertStatus(401)
                ->assertExactJson(['message' => 'Unauthenticated.']);
        }

        $this->postJson('/p6-client-probe', [], $headers('unknown-fixture-credential', 'fixture-installation-a'))
            ->assertStatus(401)
            ->assertExactJson(['message' => 'Unauthenticated.']);

        $this->assertSame(CredentialStatus::Active, $valid->credential->fresh()->status);
    }
}
