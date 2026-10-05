import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import ts from 'typescript';

// Load the isolated helper without a bundler or pending generated route modules.
const source = readFileSync(
    new URL('./financial-aid.ts', import.meta.url),
    'utf8',
);
const { outputText } = ts.transpileModule(source, {
    compilerOptions: {
        target: ts.ScriptTarget.ESNext,
        module: ts.ModuleKind.ESNext,
    },
});
const { formatUsdc, requestedAmountError, formatSubmittedAt } = await import(
    `data:text/javascript;base64,${Buffer.from(outputText).toString('base64')}`
);

test('formats base units exactly, including values beyond Number precision', () => {
    for (const [input, expected] of [
        ['0', '0.00 USDC'],
        ['1', '0.000001 USDC'],
        ['123456789', '123.456789 USDC'],
        ['1200000', '1.20 USDC'],
        ['1000000000000', '1,000,000.00 USDC'],
        ['9007199254740993123456', '9,007,199,254,740,993.123456 USDC'],
        ['-1', '-0.000001 USDC'],
        ['', 'Unavailable'],
        ['1.5', 'Unavailable'],
    ]) {
        assert.equal(formatUsdc(input), expected);
    }
});

test('accepts decimal strings through the exact maximum', () => {
    for (const value of [
        '0.000001',
        '1',
        '12.345678',
        '1000000',
        '1000000.000000',
    ]) {
        assert.equal(requestedAmountError(value), '');
    }
});

test('rejects zero, negatives, excess precision, excess amounts and non-decimal input', () => {
    for (const value of [
        '',
        '0',
        '0.000000',
        '-1',
        '1.0000001',
        '1000000.000001',
        '1000001',
        '1e6',
        '1,000',
        'NaN',
        'Infinity',
        ' 1',
        '1.',
    ]) {
        assert.notEqual(requestedAmountError(value), '');
    }
});

test('formats dates consistently in UTC and handles invalid dates', () => {
    assert.equal(formatSubmittedAt('2026-09-26T00:30:00Z'), 'Sep 26, 2026');
    assert.equal(formatSubmittedAt('invalid'), 'Date unavailable');
});
