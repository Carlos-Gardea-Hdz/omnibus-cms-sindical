<?php

declare(strict_types=1);

namespace App\Domain\Content\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Article lifecycle status (SPEC §3.3 NEWS-07). The enum is the single source of
 * truth for legal transitions — Actions call canTransitionTo() before mutating.
 *
 * Transition graph (Deviation B — a superset of NEWS-07, adding the unpublish
 * edge published→draft):
 *   draft       → published, archived
 *   published   → archived, draft (unpublish)
 *   archived    → published (republish)
 * Everything else — including any self→self — is illegal.
 */
#[TypeScript]
enum ArticleStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';

    /**
     * The states this status is allowed to transition into.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Published, self::Archived],
            self::Published => [self::Archived, self::Draft],
            self::Archived => [self::Published],
        };
    }

    /** Guard for the lifecycle — the authoritative legality check. */
    public function canTransitionTo(self $new): bool
    {
        return in_array($new, $this->allowedTransitions(), strict: true);
    }

    /** i18n key resolved client-side and via __() server-side: article_status.draft, etc. */
    public function labelKey(): string
    {
        return 'article_status.'.$this->value;
    }

    /** Magenta-palette accent per status (SPEC §1.4) for badges/chips. */
    public function color(): string
    {
        return match ($this) {
            self::Draft => '#A03CC7',      // neutral accent
            self::Published => '#DD00FF',  // primary
            self::Archived => '#9211CF',   // primary-dark
        };
    }
}
