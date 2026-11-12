<?php

declare(strict_types=1);

namespace Byte8\Pulsar\Test\Unit\Model\Collector\LogError;

use Byte8\Pulsar\Model\Collector\LogError\MessageNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MessageNormalizerTest extends TestCase
{
    private MessageNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new MessageNormalizer();
    }

    /**
     * Regression for the 2026-11-11 GraphQL injection scan against a client
     * store: ~13k POST /graphql in 11 hours produced 42 distinct exception.log
     * messages — and 42 "new log error type" signatures — because every payload
     * was unique. After normalisation the scan must collapse to one signature per
     * GraphQL variable slot / ID slot, with the structural parts preserved.
     */
    public function testGraphQlScanCollapsesToOneSignaturePerSlot(): void
    {
        $fixture = __DIR__ . '/../../../_files/graphql_scan_messages.tsv';
        $lines = file($fixture, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::assertNotFalse($lines);
        self::assertGreaterThanOrEqual(40, count($lines), 'fixture should hold the whole scan');

        $bySignature = [];
        foreach ($lines as $line) {
            [, $message] = explode("\t", $line, 2);
            $bySignature[$this->normalizer->normalize($message)][] = $message;
        }

        $expected = [
            'Variable "$input" got invalid value {VAL} at "input.express_button"; Boolean cannot represent a non boolean value: {VAL}',
            'Variable "$input" got invalid value {VAL} at "input.vaultIntent"; Boolean cannot represent a non boolean value: {VAL}',
            'Variable "$input" got invalid value {VAL} at "input.amount"; Float cannot represent non numeric value: {VAL}',
            'Variable "$id" got invalid value {VAL}; Int cannot represent non-integer value: {VAL}',
            'Variable "$formType" got invalid value {VAL}; Value {VAL} does not exist in "ReCaptchaFormEnum" enum.',
            'Variable "$useCurrentGroup" got invalid value {VAL}; Boolean cannot represent a non boolean value: {VAL}',
            'Variable "$location" got invalid value {VAL}; Value {VAL} does not exist in "PaymentLocation" enum.',
            'Could not find a cart with ID "{STR}"',
            'Ein Warenkorb mit der ID "{STR}" konnte nicht gefunden werden.',
            'Der CMS-Block mit der ID "{STR}" existiert nicht.',
        ];

        self::assertEqualsCanonicalizing($expected, array_keys($bySignature));
        self::assertCount(10, $bySignature);
    }

    #[DataProvider('messages')]
    public function testNormalizesDynamicParts(string $message, string $expected): void
    {
        self::assertSame($expected, $this->normalizer->normalize($message));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function messages(): iterable
    {
        yield 'embedded timestamp' => [
            'Message has been rejected: [2026-06-25T03:38:30+00:00] The object referenced by the queue does not exist',
            'Message has been rejected: [{TS}] The object referenced by the queue does not exist',
        ];
        yield 'store-code probe' => [
            "Requested store is not found (<?php dns_get_record('dbmjh4lblp65jvf00m30xwgpm.example'); ?>)",
            'Requested store is not found ({STR})',
        ];
        yield 'plain store code' => [
            'Requested store is not found (default_de)',
            'Requested store is not found ({STR})',
        ];
        yield 'numeric id in quotes' => [
            'Die CMS-Seite mit der ID "0" existiert nicht.',
            'Die CMS-Seite mit der ID "{STR}" existiert nicht.',
        ];
        yield 'quote-breaking payload after ID' => [
            'Could not find a cart with ID "-1" OR 5*5=25 or "Qh0xp9F9"=""',
            'Could not find a cart with ID "{STR}"',
        ];
        yield 'identifiers are kept' => [
            'Field "foo" is not defined by type "CartItemInput".',
            'Field {STR} is not defined by type "CartItemInput".',
        ];
        yield 'unknown field' => [
            'Cannot query field "foo" on type "Query".',
            'Cannot query field {STR} on type "Query".',
        ];
        yield 'expected type' => [
            'Expected type Int, found "abc".',
            'Expected type Int, found {VAL}',
        ];
        yield 'enum did-you-mean tail dropped' => [
            'Variable "$formType" got invalid value "PLACE_ORDERv2uCetVc"; Value "PLACE_ORDERv2uCetVc" does not exist in "ReCaptchaFormEnum" enum. Did you mean the enum value "PLACE_ORDER"?',
            'Variable "$formType" got invalid value {VAL}; Value {VAL} does not exist in "ReCaptchaFormEnum" enum.',
        ];
        yield 'class names and paths survive' => [
            'Class "Magento\Catalog\Model\Product" does not exist at "input.cart_items"',
            'Class "Magento\Catalog\Model\Product" does not exist at "input.cart_items"',
        ];
        yield 'free-text quoted value' => [
            'Attribute "color" of product "Blue Shirt XL" is missing',
            'Attribute "color" of product "{STR}" is missing',
        ];
        yield 'random token in quotes' => [
            'Invalid session "k3j4h5g6f7d8s9a0q1w2e3r4" for customer',
            'Invalid session "{STR}" for customer',
        ];
        yield 'apostrophes are not quote pairs' => [
            "Customer's address doesn't exist for 'abc' item",
            "Customer's address doesn't exist for 'abc' item",
        ];
        yield 'single-quoted sql fragment' => [
            "Query failed: 'abc' OR 5*5=25 or 'Sgpv4vts'='",
            "Query failed: 'abc' OR 5*5={N} or '{STR}'='",
        ];
        yield 'email ip uuid token digits' => [
            'User john.doe@example.com from 10.0.0.1 session 3f2a9c8b7d6e5f4a3b2c1d0e order id5512 ref 123e4567-e89b-12d3-a456-426614174000',
            'User {EMAIL} from {IP} session {TOKEN} order id{N} ref {UUID}',
        ];
        yield 'whitespace collapsed' => [
            "Foo   bar\n\tbaz ",
            'Foo bar baz',
        ];
    }

    public function testIsDeterministic(): void
    {
        $message = 'Variable "$id" got invalid value "DE6P5TSx8a"; Int cannot represent non-integer value: "DE6P5TSx8a"';
        self::assertSame($this->normalizer->normalize($message), $this->normalizer->normalize($message));
    }
}
