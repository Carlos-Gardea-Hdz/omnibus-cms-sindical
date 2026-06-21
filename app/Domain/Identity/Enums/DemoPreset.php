<?php

declare(strict_types=1);

namespace App\Domain\Identity\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The three public demo-login presets (SPEC §13 / AUTH-02, slice 008).
 *
 * Each preset maps a public showcase persona to a real {@see UserRole}. The set is
 * deliberately {administrator, manager, editor} — there is NO super_admin case, and
 * this absence is the load-bearing invariant of the whole slice: a demo visitor can
 * therefore never reach a super_admin-gated screen (catalog / user CRUD), because no
 * preset can ever mint one. Adding a super_admin case here would silently defeat the
 * entire demo-isolation guarantee, so it is forbidden.
 *
 * Importing nothing but UserRole keeps this enum inside the Identity domain; it is
 * the SSOT both the chooser (frontend, via the i18n keys) and ProvisionDemoSession
 * (backend, via {@see self::role()}) consume — never duplicated.
 */
#[TypeScript]
enum DemoPreset: string
{
    case Administrator = 'administrator';
    case Manager = 'manager';
    case Editor = 'editor';

    /** The role the minted demo user is given — NEVER super_admin (the invariant). */
    public function role(): UserRole
    {
        return match ($this) {
            self::Administrator => UserRole::Administrator,
            self::Manager => UserRole::Manager,
            self::Editor => UserRole::Editor,
        };
    }

    /** A fixed, fictional, deterministic display name (NO faker PII). */
    public function displayName(): string
    {
        return match ($this) {
            self::Administrator => 'Administrador Demo',
            self::Manager => 'Gerente Demo',
            self::Editor => 'Editor Demo',
        };
    }

    /** i18n key for the chooser-card title (frontend locales). */
    public function labelKey(): string
    {
        return 'demo.preset.'.$this->value.'.title';
    }

    /** i18n key for the chooser-card description (frontend locales). */
    public function descriptionKey(): string
    {
        return 'demo.preset.'.$this->value.'.desc';
    }
}
