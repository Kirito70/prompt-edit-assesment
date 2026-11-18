<?php

use App\Services\PixelForge\WebhookSignature;

const WH_SECRET = 'whsec_test';
const WH_NOW = 1_800_000_000;

function whHeader(string $body, int $timestamp = WH_NOW, string $secret = WH_SECRET): string
{
    return "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$body}", $secret);
}

test('accepts a correctly signed body', function () {
    $body = '{"id":"evt_1"}';

    expect((new WebhookSignature)->verify(whHeader($body), $body, WH_SECRET, WH_NOW))->toBeTrue();
});

test('rejects a tampered body', function () {
    $header = whHeader('{"id":"evt_1"}');

    expect((new WebhookSignature)->verify($header, '{"id":"evt_2"}', WH_SECRET, WH_NOW))->toBeFalse();
});

test('rejects a signature made with a different secret', function () {
    $body = '{"id":"evt_1"}';

    expect((new WebhookSignature)->verify(whHeader($body, secret: 'whsec_other'), $body, WH_SECRET, WH_NOW))->toBeFalse();
});

test('rejects timestamps more than 300 seconds old or in the future', function (int $offset, bool $valid) {
    $body = '{}';
    $header = whHeader($body, WH_NOW + $offset);

    expect((new WebhookSignature)->verify($header, $body, WH_SECRET, WH_NOW))->toBe($valid);
})->with([
    'just inside, past' => [-300, true],
    'too old' => [-301, false],
    'just inside, future' => [300, true],
    'too far in the future' => [301, false],
]);

test('rejects malformed or empty headers and an empty secret', function (string $header, string $secret) {
    expect((new WebhookSignature)->verify($header, '{}', $secret, WH_NOW))->toBeFalse();
})->with([
    'empty header' => ['', WH_SECRET],
    'no timestamp' => ['v1=abc', WH_SECRET],
    'no signature' => ['t='.WH_NOW, WH_SECRET],
    'non numeric timestamp' => ['t=abc,v1=abc', WH_SECRET],
    'garbage' => ['hello world', WH_SECRET],
    'empty secret fails closed' => [whHeader('{}', secret: ''), ''],
]);

test('accepts any one matching v1 signature, to allow secret rotation', function () {
    $body = '{}';
    $good = hash_hmac('sha256', WH_NOW.".{$body}", WH_SECRET);
    $header = 't='.WH_NOW.',v1='.str_repeat('0', 64).",v1={$good}";

    expect((new WebhookSignature)->verify($header, $body, WH_SECRET, WH_NOW))->toBeTrue();
});
