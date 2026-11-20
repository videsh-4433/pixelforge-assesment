<?php

use App\Pixelforge\WebhookSignature;

beforeEach(fn () => $this->signer = new WebhookSignature('whsec_abc', 300));

it('accepts a valid signature', function () {
    $body = '{"id":"evt_1"}';
    $now = 1_800_000_000;

    expect($this->signer->isValid($body, "t={$now},v1=".hash_hmac('sha256', "{$now}.{$body}", 'whsec_abc'), $now))->toBeTrue();
});

it('rejects a tampered body, a wrong secret and a missing header', function () {
    $now = 1_800_000_000;
    $header = $this->signer->header('{"a":1}', $now);

    expect($this->signer->isValid('{"a":2}', $header, $now))->toBeFalse()
        ->and((new WebhookSignature('whsec_other'))->isValid('{"a":1}', $header, $now))->toBeFalse()
        ->and($this->signer->isValid('{"a":1}', null, $now))->toBeFalse()
        ->and($this->signer->isValid('{"a":1}', 'garbage', $now))->toBeFalse();
});

it('rejects timestamps outside the tolerance window (replays)', function () {
    $signedAt = 1_800_000_000;
    $header = $this->signer->header('{}', $signedAt);

    expect($this->signer->isValid('{}', $header, $signedAt + 299))->toBeTrue()
        ->and($this->signer->isValid('{}', $header, $signedAt + 301))->toBeFalse();
});

it('fails closed when no secret is configured', function () {
    $header = (new WebhookSignature(''))->header('{}', time());

    expect((new WebhookSignature(''))->isValid('{}', $header))->toBeFalse();
});
