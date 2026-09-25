import { existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

import { INTENT_KEY, MARKER_KEY, PAYLOAD_VERSION, SW_VERSION, TRAIL_KEY } from '../src/contract';
import { normalizeServiceWorkerConfig } from '../src/service-worker/config';
import { ACTION_TYPES, MAX_ACTIONS, MAX_DATA_PROPERTIES } from '../src/service-worker/payload';
import { BASE_CONFIG } from './page_harness';

const REPO = resolve(import.meta.dirname, '../..');
const schema = JSON.parse(readFileSync(resolve(REPO, 'src/Application/Contract/schema/v1.json'), 'utf8'));
const php = (path: string) => readFileSync(resolve(REPO, path), 'utf8');

/**
 * The page and the worker are bundled from the same src/contract.ts, so they cannot
 * drift. What CAN drift is the published language with PHP: the payload schema, the
 * worker config and the page config. This suite fails the build when they diverge.
 */
describe('contract with the PHP side', () => {
    it('accepts exactly the payload version of the schema', () => {
        expect(schema.properties.v.const).toBe(PAYLOAD_VERSION);
    });

    it('knows exactly the action types of the schema', () => {
        expect([...ACTION_TYPES].sort()).toEqual([...schema.properties.actions.items.properties.type.enum].sort());
    });

    it('applies the same bounds as the schema', () => {
        expect(MAX_ACTIONS).toBe(schema.properties.actions.maxItems);
        expect(MAX_DATA_PROPERTIES).toBe(schema.properties.data.maxProperties);
    });

    it('reads every payload field the schema defines, and nothing else', () => {
        const source = readFileSync(resolve(import.meta.dirname, '../src/service-worker/payload.ts'), 'utf8');

        for (const field of Object.keys(schema.properties)) {
            expect(source, `payload.ts must read "${field}"`).toMatch(new RegExp(`raw\\.${field}\\b`));
        }
    });

    it('normalizes every key ServiceWorkerConfig::toArray() produces', () => {
        const source = php('src/Application/ServiceWorker/ServiceWorkerConfig.php');
        const keys = [...source.matchAll(/'([a-zA-Z]+)' => \$/g)].map((match) => match[1]!);

        expect(keys.length).toBeGreaterThan(0);
        expect(Object.keys(normalizeServiceWorkerConfig({})).sort()).toEqual(expect.arrayContaining(keys.sort()));
    });

    it('reads every key ClientConfiguration renders in the page meta', () => {
        const source = php('src/Application/ClientConfiguration.php');
        const shape = /array\{([^}]+)\}/.exec(source)?.[1] ?? '';
        const keys = [
            ...[...shape.matchAll(/([a-zA-Z]+):/g)].map((match) => match[1]!),
            ...[...source.matchAll(/'([a-zA-Z]+)' => /g)].map((match) => match[1]!),
        ];

        expect(keys).toEqual(expect.arrayContaining(['publicKey', 'clientState']));
        expect(Object.keys(BASE_CONFIG)).toEqual(expect.arrayContaining(keys));
    });
});

describe('built artifacts', () => {
    const dist = resolve(import.meta.dirname, '../dist');

    it.runIf(existsSync(resolve(dist, 'web-push-sw.js')))('embeds the current contract in the prebuilt worker', () => {
        const worker = readFileSync(resolve(dist, 'web-push-sw.js'), 'utf8');

        // Constant-folded by the bundler into the pong reply.
        expect(worker).toMatch(new RegExp(`version: ${JSON.stringify(SW_VERSION)}`));
        expect(worker).toContain(INTENT_KEY);
        expect(worker).toContain(TRAIL_KEY);
        expect(worker).toContain(MARKER_KEY);
        expect(worker).toContain('self.__WEB_PUSH_CONFIG__');
        // A classic script: importScripts() refuses module syntax.
        expect(worker).not.toMatch(/^\s*(import|export)\s/m);
    });

    it.runIf(existsSync(resolve(dist, 'index.js')))('embeds the same SW_VERSION in the page bundle', () => {
        expect(readFileSync(resolve(dist, 'index.js'), 'utf8')).toContain(`SW_VERSION = ${JSON.stringify(SW_VERSION)}`);
    });
});
