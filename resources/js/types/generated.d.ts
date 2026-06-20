declare namespace App {
namespace Domain {
namespace Content {
namespace Data {
export type ArchiveArticleData = {
status: App.Domain.Content.Enums.ArticleStatus,
};
export type ArticleData = {
title: string,
category_id: number,
content: Record<string, any>,
slug: string | null,
subtitle: string | null,
signature: string | null,
meta_title: string | null,
meta_description: string | null,
featured_image: undefined | null,
};
export type CategoryData = {
name: string,
slug: string | null,
description: string | null,
};
export type PublishArticleData = object;
}
namespace Enums {
export type ArticleStatus = 'draft' | 'published' | 'archived';
}
}
namespace Identity {
namespace Data {
export type LoginData = {
username: string,
password: string,
remember: boolean,
};
}
namespace Enums {
export type UserRole = 'super_admin' | 'administrator' | 'manager' | 'editor';
}
}
}
}
