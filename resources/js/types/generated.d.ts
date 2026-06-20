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
namespace Organization {
namespace Data {
export type BranchData = {
organization_id: number,
name: string,
location: string,
};
export type DirectorData = {
organization_id: number,
first_name: string,
last_name: string,
photo: undefined | null,
};
export type MunicipalityData = {
name: string,
state: string,
};
export type OrganizationData = {
name: string,
municipality_id: number,
registered_at: undefined,
slug: string | null,
logo: undefined | null,
};
export type RepresentativeData = {
organization_id: number,
branch_id: number,
first_name: string,
last_name: string,
shift: App.Domain.Organization.Enums.RepresentativeShift,
is_coordinator: boolean,
photo: undefined | null,
};
}
namespace Enums {
export type RepresentativeShift = 'morning' | 'evening' | 'night';
}
}
}
}
