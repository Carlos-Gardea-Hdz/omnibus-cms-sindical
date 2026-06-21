<?php

declare(strict_types=1);

use App\Domain\Shared\Rules\CurpFormat;
use App\Domain\Shared\Rules\MexicanPhone;
use App\Domain\Shared\Rules\RfcFormat;
use Illuminate\Contracts\Validation\ValidationRule;

/*
 * Pure-logic coverage for the three Mexican-PII format rules (CONTRACT §4/§14, SPEC
 * §11.4 #9/#10). Each rule is a self-contained ValidationRule that uppercase-
 * normalises before matching and calls $fail() with the matching i18n key on a bad
 * value. No framework bootstrap, no database — the rule object is exercised directly
 * through a tiny $fail spy, so the patterns are pinned regardless of the validator.
 * The fixtures are FICTIONAL pattern-valid strings (never a real CURP/RFC).
 */

/**
 * Run a rule and return the failure messages it emitted (empty == passed).
 *
 * @return list<string>
 */
function runPiiRule(ValidationRule $rule, string $attribute, mixed $value): array
{
    $failures = [];

    $rule->validate($attribute, $value, function (string $message) use (&$failures): void {
        $failures[] = $message;
    });

    return $failures;
}

/*
 * ─────────────────────────── CurpFormat ───────────────────────────
 * ^[A-Z]{4}\d{6}[HM][A-Z]{5}[A-Z0-9]\d$  (18 chars, FICTIONAL fixtures)
 */

it('CurpFormat passes a pattern-valid CURP (fictional)', function (string $curp): void {
    expect(runPiiRule(new CurpFormat, 'curp', $curp))->toBe([]);
})->with([
    'uppercase' => ['XEXX010101HNEXXXA4'],
    'lowercase normalises to uppercase' => ['xexx010101hnexxxa4'],
]);

it('CurpFormat rejects an invalid CURP with the curp_format key', function (string $curp): void {
    $failures = runPiiRule(new CurpFormat, 'curp', $curp);

    expect($failures)->toHaveCount(1)
        ->and($failures[0])->toBe(__('validation.curp_format'));
})->with([
    'too short (17)' => ['XEXX010101HNEXXXA'],
    'too long (19)' => ['XEXX010101HNEXXXA44'],
    'bad gender char' => ['XEXX010101XNEXXXA4'],
    'letters where digits expected' => ['XEXXABCDEFHNEXXXA4'],
    'empty' => [''],
    'free text' => ['no soy una curp'],
]);

/*
 * ─────────────────────────── RfcFormat ───────────────────────────
 * ^[A-ZN&]{3,4}\d{6}[A-Z0-9]{3}$  (FICTIONAL fixtures)
 */

it('RfcFormat passes a pattern-valid RFC (fictional)', function (string $rfc): void {
    expect(runPiiRule(new RfcFormat, 'rfc', $rfc))->toBe([]);
})->with([
    'persona física (13)' => ['XEXX010101000'],
    'persona moral (12)' => ['ABC010101AB1'],
    'lowercase normalises' => ['xexx010101000'],
]);

it('RfcFormat rejects an invalid RFC with the rfc_format key', function (string $rfc): void {
    $failures = runPiiRule(new RfcFormat, 'rfc', $rfc);

    expect($failures)->toHaveCount(1)
        ->and($failures[0])->toBe(__('validation.rfc_format'));
})->with([
    'too short' => ['AB010101'],
    'bad date block' => ['XEXXABCDEF000'],
    'empty' => [''],
    'free text' => ['no rfc'],
]);

/*
 * ─────────────────────────── MexicanPhone ───────────────────────────
 * ^\d{10}$  (exactly 10 digits)
 */

it('MexicanPhone passes a 10-digit string', function (): void {
    expect(runPiiRule(new MexicanPhone, 'mobile', '5512345678'))->toBe([]);
});

it('MexicanPhone rejects a non-10-digit value with the mexican_phone key', function (string $phone): void {
    $failures = runPiiRule(new MexicanPhone, 'mobile', $phone);

    expect($failures)->toHaveCount(1)
        ->and($failures[0])->toBe(__('validation.mexican_phone'));
})->with([
    'nine digits' => ['551234567'],
    'eleven digits' => ['55123456789'],
    'contains letters' => ['55ABCDEF78'],
    'with spaces/dashes' => ['55-1234-567'],
    'empty' => [''],
]);
