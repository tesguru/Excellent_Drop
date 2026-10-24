import assert from 'node:assert/strict';
import test from 'node:test';

import { detailsLine, parseRdap, readResponse, retryAfterSeconds } from './rdap.js';

const NOW = new Date('2026-10-24T12:00:00Z');

function payload(statuses, overrides = {}) {
    return {
        objectClassName: 'domain',
        ldhName: 'example.com',
        status: statuses,
        events: [
            {
                eventAction: 'expiration',
                eventDate: overrides.expiration ?? '2027-01-15T04:00:00Z',
            },
            {
                eventAction: 'last changed',
                eventDate: overrides.lastChanged ?? '2026-10-01T09:30:00Z',
            },
        ],
        entities: [
            {
                roles: ['registrar'],
                vcardArray: [
                    'vcard',
                    [
                        ['version', {}, 'text', '4.0'],
                        ['fn', {}, 'text', overrides.registrar ?? 'Example Registrar, Inc.'],
                    ],
                ],
            },
        ],
    };
}

function parse(statuses, overrides = {}) {
    return parseRdap(payload(statuses, overrides), { source: 'com', now: NOW });
}

test('redemption period maps to In redemption and an estimate 35 days after the last change', () => {
    const result = parse(['redemption period']);

    assert.equal(result.key, 'redemption');
    assert.equal(result.label, 'In redemption');
    assert.equal(result.saveable, true);
    assert.equal(result.expiry, '2027-01-15');
    assert.equal(result.lastChanged, '2026-10-01');
    assert.equal(result.registrar, 'Example Registrar, Inc.');
    assert.deepEqual(result.estimatedDrop, {
        date: '2026-11-05',
        days: 35,
        basis: 'last changed + 35 days',
    });
});

test('client hold wins over an unrelated prohibition status and stays readable', () => {
    const result = parse(['client hold', 'client transfer prohibited']);

    assert.equal(result.key, 'on_hold');
    assert.equal(result.label, 'On hold');
    assert.deepEqual(result.extraStatuses, ['client transfer prohibited']);
    assert.match(detailsLine(result), /Other status: client transfer prohibited/);
});

test('pending delete maps to the five day warning and a five day estimate', () => {
    const result = parse(['pending delete']);

    assert.equal(result.key, 'pending_delete');
    assert.equal(result.label, 'Pending delete (drops in about 5 days)');
    assert.deepEqual(result.estimatedDrop, {
        date: '2026-10-06',
        days: 5,
        basis: 'last changed + 5 days',
    });
});

test('the priority order puts pending delete above redemption', () => {
    const result = parse(['redemption period', 'pending delete']);

    assert.equal(result.key, 'pending_delete');
});

test('pending restore and auto renew period map to their labels', () => {
    assert.equal(parse(['pending restore']).label, 'Being restored');
    assert.equal(parse(['auto renew period']).label, 'Grace period');
});

test('a past expiration with no special status is expired in grace', () => {
    const result = parse([], { expiration: '2026-01-15T04:00:00Z' });

    assert.equal(result.key, 'expired');
    assert.equal(result.label, 'Expired, in grace');
});

test('a future expiration is reported as renewed to that date', () => {
    const result = parse(['active']);

    assert.equal(result.key, 'renewed');
    assert.equal(result.label, 'Renewed to 2027-01-15');
    assert.equal(result.estimatedDrop, null);
});

test('an unknown status is shown as text without breaking the parser', () => {
    const result = parse(['some new registry status']);

    assert.equal(result.key, 'renewed');
    assert.deepEqual(result.extraStatuses, ['some new registry status']);
});

test('a 404 from the .com registry means not registered', () => {
    const result = readResponse({ status: 404, source: 'com' }, { now: NOW });

    assert.equal(result.key, 'not_registered');
    assert.equal(result.label, 'Not registered');
    assert.equal(result.saveable, false);
});

test('a 404 from rdap.org means not found or unknown', () => {
    const result = readResponse({ status: 404, source: 'rdap' }, { now: NOW });

    assert.equal(result.key, 'not_found');
    assert.equal(result.label, 'Not found / unknown');
});

test('a 429 is reported with Retry-After and never retried automatically', () => {
    const result = readResponse({
        status: 429,
        source: 'com',
        retryAfter: '120',
    }, { now: NOW });

    assert.equal(result.key, 'rate_limited');
    assert.equal(result.label, 'Rate limited, wait a few minutes');
    assert.equal(result.retryAfterSeconds, 120);
    assert.match(result.message, /120 seconds/);
    assert.equal(result.saveable, false);
});

test('a 429 without Retry-After still gives the rate limited label', () => {
    const result = readResponse({ status: 429, source: 'com' }, { now: NOW });

    assert.equal(result.label, 'Rate limited, wait a few minutes');
    assert.equal(result.retryAfterSeconds, null);
});

test('Retry-After can be an HTTP date', () => {
    assert.equal(retryAfterSeconds('Sat, 24 Oct 2026 12:02:00 GMT', NOW), 120);
});

test('a successful response is parsed from a JSON body string', () => {
    const result = readResponse({
        status: 200,
        body: JSON.stringify(payload(['pending delete'])),
        source: 'com',
    }, { now: NOW });

    assert.equal(result.key, 'pending_delete');
});

test('an unreadable body and a network error both say could not read', () => {
    const invalid = readResponse({ status: 200, body: '<html>', source: 'com' }, { now: NOW });
    const network = readResponse({ status: 0, source: 'com' }, { now: NOW });

    assert.equal(invalid.label, 'Could not read, open raw');
    assert.equal(network.label, 'Could not read, open raw');
    assert.equal(invalid.saveable, false);
    assert.equal(network.saveable, false);
});

test('the registrar is found on a nested registrar entity', () => {
    const data = payload([]);
    data.entities = [
        {
            roles: ['registry'],
            entities: [
                {
                    roles: ['registrar'],
                    vcardArray: ['vcard', [['fn', {}, 'text', 'Nested Registrar LLC']]],
                },
            ],
        },
    ];

    const result = parseRdap(data, { source: 'com', now: NOW });

    assert.equal(result.registrar, 'Nested Registrar LLC');
});
