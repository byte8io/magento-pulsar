<?php

declare(strict_types=1);

namespace Byte8\Pulsar\Model\Collector\LogError;

/**
 * Collapses the dynamic parts of a log message so that one logical error
 * always hashes to the same signature.
 *
 * The signature decides whether Pulsar raises a "new log error type" alert, so
 * every value an attacker or a request can vary (IDs, quoted payloads, GraphQL
 * variable values, timestamps, tokens) has to disappear here. Structural
 * identifiers that distinguish one error from another (GraphQL variable names,
 * input paths, enum and type names, class names) are kept on purpose.
 *
 * Pure function of its input — no Magento dependencies — so it can be unit
 * tested outside a Magento install.
 */
class MessageNormalizer
{
    public const MAX_LENGTH = 200;

    /**
     * Quoted content that is kept verbatim: GraphQL variable names ("$input"),
     * input paths ("input.vaultIntent"), enum / type / field names, PHP class
     * names. Anything with whitespace, punctuation or a leading digit is a value.
     */
    private const IDENTIFIER_PATTERN = '/^\$?[A-Za-z_][\w.\\\\:-]{0,39}$/';

    public function normalize(string $message): string
    {
        $msg = mb_substr($message, 0, self::MAX_LENGTH);

        // Strip embedded ISO-8601 timestamps FIRST. Many Magento messages quote a
        // timestamp in the body (e.g. async-queue "Message has been rejected:
        // [2026-06-25T03:38:30+00:00] ..."). The numeric pass below cannot remove
        // the date/hour because they are glued to the `T` separator (no word
        // boundary), so the signature would otherwise rotate every hour and every
        // day — re-detecting the same logical error as a brand-new "type".
        $msg = preg_replace(
            '/\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+\-]\d{2}:?\d{2})?/',
            '{TS}',
            $msg
        );

        $msg = $this->collapseGraphQlValueSlots($msg);
        $msg = $this->collapseMagentoValueSlots($msg);
        $msg = $this->collapseIdSlots($msg);
        $msg = $this->collapseQuotedLiterals($msg);

        // Strip UUIDs (v4 pattern)
        $msg = preg_replace('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', '{UUID}', $msg);

        // Strip hex/alphanumeric tokens (session IDs, quote IDs, etc.)
        $msg = preg_replace('/\b[0-9a-f]{16,}\b/i', '{TOKEN}', $msg);

        // Strip email addresses and IPs before the numeric pass, otherwise the
        // numeric pass mangles their digits and these patterns no longer match.
        $msg = preg_replace('/[\w.+-]+@[\w.-]+/', '{EMAIL}', $msg);
        $msg = preg_replace('/\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}/', '{IP}', $msg);

        // Strip numeric IDs (runs of 2+ digits). Deliberately NOT anchored on word
        // boundaries so IDs glued to letters (e.g. "order id5512", increment IDs)
        // also collapse — over-collapsing distinct errors that differ only by a
        // number is acceptable and desirable for a stable signature.
        $msg = preg_replace('/\d{2,}/', '{N}', $msg);

        // Collapse whitespace
        $msg = preg_replace('/\s+/', ' ', trim($msg));

        return $msg;
    }

    /**
     * GraphQL coercion errors echo the offending value — often twice — and the
     * value is whatever the client sent. One injection scan against /graphql
     * yields a distinct message per payload unless the value slots are blanked.
     * The variable name, the "at input.path" location and the type/enum names
     * survive: they are what makes one coercion error different from another.
     *
     *   Variable "$input" got invalid value "x' OR 1=1" at "input.amount"; Float cannot represent non numeric value: "x' OR 1=1"
     *   → Variable "$input" got invalid value {VAL} at "input.amount"; Float cannot represent non numeric value: {VAL}
     *
     * The payload may itself contain quotes, so the slot runs up to the next
     * structural boundary (` at "`, `; <Type> …`) rather than to a closing quote.
     */
    private function collapseGraphQlValueSlots(string $msg): string
    {
        $msg = preg_replace(
            '/ got invalid value .*?(?= at "|; [A-Z]|$)/su',
            ' got invalid value {VAL}',
            $msg
        );
        $msg = preg_replace(
            '/(cannot represent (?:a )?non[- ]?\w+ value): .*$/su',
            '$1: {VAL}',
            $msg
        );
        // Drop the "Did you mean …?" tail too — it is derived from the value.
        $msg = preg_replace(
            '/; Value .*? does not exist in "([^"]+)" enum\.?.*$/su',
            '; Value {VAL} does not exist in "$1" enum.',
            $msg
        );
        $msg = preg_replace('/(Expected type [^,]+), found .*$/su', '$1, found {VAL}', $msg);
        $msg = preg_replace(
            '/Field "[^"]*" is not defined by type "([^"]*)"/u',
            'Field {STR} is not defined by type "$1"',
            $msg
        );
        $msg = preg_replace(
            '/Cannot query field "[^"]*" on type "([^"]*)"/u',
            'Cannot query field {STR} on type "$1"',
            $msg
        );

        return $msg;
    }

    /**
     * Magento messages that echo a request value outside of quotes.
     *
     *   Requested store is not found (<?php dns_get_record('…'); ?>)
     *   → Requested store is not found ({STR})
     *
     * The store code comes straight from the URL / header and is a favourite
     * probe target, so everything inside the parentheses is attacker-chosen.
     */
    private function collapseMagentoValueSlots(string $msg): string
    {
        return preg_replace('/(Requested store is not found) \(.*$/su', '$1 ({STR})', $msg);
    }

    /**
     * `… with ID "<value>" …` / `… mit der ID "<value>" …` — the value is user
     * input (cart ID, CMS block ID, …) and an injected payload can close the
     * quote and continue, so the slot runs greedily to the LAST quote on the
     * line. Over-collapsing a second quoted value further along is acceptable.
     */
    private function collapseIdSlots(string $msg): string
    {
        $msg = preg_replace('/\b(ID|Id|id) "(.*)"/su', '$1 "{STR}"', $msg);
        $msg = preg_replace("/\\b(ID|Id|id) '(.*)'/su", "$1 '{STR}'", $msg);

        return $msg;
    }

    /**
     * Any remaining quoted literal is a value unless it looks like an identifier.
     * Single-quoted spans are only collapsed when they contain no whitespace so
     * English apostrophes ("doesn't exist for 'x'") are not paired up wrongly.
     */
    private function collapseQuotedLiterals(string $msg): string
    {
        $msg = preg_replace_callback(
            '/"([^"]+)"/u',
            fn(array $m) => $this->isIdentifier($m[1]) ? $m[0] : '"{STR}"',
            $msg
        );
        $msg = preg_replace_callback(
            '/`([^`]+)`/u',
            fn(array $m) => $this->isIdentifier($m[1]) ? $m[0] : '`{STR}`',
            $msg
        );
        $msg = preg_replace_callback(
            "/'([^'\\s]+)'/u",
            fn(array $m) => $this->isIdentifier($m[1]) ? $m[0] : "'{STR}'",
            $msg
        );

        return $msg;
    }

    private function isIdentifier(string $value): bool
    {
        if (preg_match(self::IDENTIFIER_PATTERN, $value) !== 1) {
            return false;
        }

        // Random tokens (session IDs, scanner suffixes such as "Sgpv4vts" or
        // "DE6P5TSx8a", probe hostnames) pass the shape test above. Real
        // identifiers carry digits only as a single trailing group ("address1",
        // "input.street.0"); digits followed by more letters mark a value.
        return preg_match('/^\D*\d*$/', $value) === 1;
    }
}
