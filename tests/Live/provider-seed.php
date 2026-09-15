<?php

declare(strict_types=1);

use ArtisanBuild\BuiltForCloud\Actions\MintCredential;
use ArtisanBuild\BuiltForCloud\Console\ConsoleKeyring;
use ArtisanBuild\BuiltForCloud\Credential;
use ArtisanBuild\BuiltForCloud\CredentialPurpose;
use ArtisanBuild\BuiltForCloud\MintOptions;
use ArtisanBuild\BuiltForCloud\OperatorAbility;
use ArtisanBuild\BuiltForCloud\Subject;
use ArtisanBuild\BuiltForCloud\SubjectType;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$input = json_decode((string) stream_get_contents(STDIN), true);
$publicKey = is_array($input) && is_string($input['public_key'] ?? null) ? $input['public_key'] : null;

if ($publicKey === null) {
    fwrite(STDERR, 'The P6 seed fixture requires a public key.');
    exit(2);
}

$ring = app(ConsoleKeyring::class);
$ring->activate($ring->add('b1-live-key', $publicKey)->key_id);
$mint = app(MintCredential::class);
$valid = $mint(new Subject(SubjectType::Installation, 'b1-installation'), new MintOptions(purpose: CredentialPurpose::Mcp));
$wrongPurpose = $mint(new Subject(SubjectType::Installation, 'b1-installation'), new MintOptions(purpose: CredentialPurpose::Consumption));
$revoked = $mint(new Subject(SubjectType::Installation, 'b1-installation'), new MintOptions(purpose: CredentialPurpose::Mcp));
$operator = $mint(new Subject(SubjectType::Operator, 'b1-operator'), new MintOptions(
    purpose: CredentialPurpose::OperatorManagement,
    abilities: [OperatorAbility::Admin->value],
));
Credential::query()->findOrFail($revoked->summary->id)->forceFill(['revoked_at' => now()])->save();

fwrite(STDOUT, json_encode([
    'valid_id' => $valid->summary->id,
    'valid_secret' => $valid->secret?->reveal(),
    'wrong_secret' => $wrongPurpose->secret?->reveal(),
    'revoked_secret' => $revoked->secret?->reveal(),
    'operator_secret' => $operator->secret?->reveal(),
], JSON_THROW_ON_ERROR));
