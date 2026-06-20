<?php

declare(strict_types=1);

namespace App\Domain\Content\Data;

use Closure;
use Illuminate\Http\UploadedFile;
use Spatie\LaravelData\Attributes\Validation\Exists;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Validation\ValidationContext;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Article create/update payload (editor-facing).
 *
 * Spatie Data is the single source of truth: server-side validation rules AND
 * the TypeScript type consumed by the Inertia form. FormRequests are prohibited.
 * One route-agnostic DTO serves both store and update; slug uniqueness
 * (ignore-self on update) lives in the Actions, not a DTO Unique attribute.
 *
 * The `content` shape is validated here to a top-level TipTap document
 * (`{ type: 'doc', content: [...] }` with a non-empty body); DEEP node safety —
 * stripping `<script>`, `on*`, `style`, and unsafe `href` schemes — is the
 * SanitizesContent service's job, run in the Create/Update Actions before
 * persistence. The featured image is validated here only as a global type/size
 * cap; the publish-requires-image precondition lives in PublishArticleAction
 * because it depends on the persisted row.
 */
#[TypeScript]
final class ArticleData extends Data
{
    /**
     * @param  array<string, mixed>  $content
     */
    public function __construct(
        #[Required, Max(150)]
        public string $title,
        #[Required, Exists('categories', 'id')]
        public int $category_id,
        #[Required]
        public array $content,
        #[Nullable, Max(180)]
        public ?string $slug = null,
        #[Nullable, Max(200)]
        public ?string $subtitle = null,
        #[Nullable, Max(200)]
        public ?string $signature = null,
        #[Nullable, Max(200)]
        public ?string $meta_title = null,
        #[Nullable]
        public ?string $meta_description = null,
        #[Nullable]
        public ?UploadedFile $featured_image = null,
    ) {}

    /**
     * Top-level TipTap document shape + featured-image file constraints.
     * Spatie's attribute layer cannot express the nested `type === 'doc'` /
     * non-empty body contract, so the raw rules live here. The structural check
     * is a single closure on the `content` key (so a malformed body surfaces a
     * `content` session error, not a `content.*` sub-key error). Per-node
     * sanitization is NOT validation — it is the SanitizesContent service's job.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(ValidationContext $context): array
    {
        return [
            'content' => ['required', 'array', self::tiptapDocRule()],
            'featured_image' => ['nullable', 'image', 'mimes:jpeg,png,webp,gif', 'max:20480'],
        ];
    }

    /**
     * Closure rule asserting `content` is a TipTap document — root `type === 'doc'`
     * with a non-empty `content` body array. Reports on the `content` key itself.
     */
    private static function tiptapDocRule(): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_array($value)) {
                $fail('validation.array')->translate();

                return;
            }

            $type = $value['type'] ?? null;
            $body = $value['content'] ?? null;

            if ($type !== 'doc' || ! is_array($body) || $body === []) {
                $fail(__('articles.error.invalid_content'));
            }
        };
    }
}
