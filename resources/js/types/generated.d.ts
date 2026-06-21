declare namespace App {
namespace Domain {
namespace Analytics {
namespace Data {
export type AnalyticsFilterData = {
organization_id: number | null,
branch_id: number | null,
category_id: number | null,
period: App.Domain.Analytics.Enums.TimePeriod | null,
};
}
namespace Enums {
export type TimePeriod = 'day' | 'week' | 'month';
}
}
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
namespace Engagement {
namespace Data {
export type SubmitContactData = {
first_name: string,
last_name: string,
email: string,
phone: string,
message: string,
organization_id: number,
branch_id: number,
};
}
}
namespace Identity {
namespace Data {
export type DemoLoginData = {
preset: App.Domain.Identity.Enums.DemoPreset,
};
export type LoginData = {
username: string,
password: string,
remember: boolean,
};
export type UpdateUserData = {
username: string,
name: string | null,
email: string | null,
password: string | null,
role: App.Domain.Identity.Enums.UserRole,
organization_id: number | null,
};
export type UserData = {
username: string,
name: string | null,
email: string | null,
password: string,
role: App.Domain.Identity.Enums.UserRole,
organization_id: number | null,
};
}
namespace Enums {
export type DemoPreset = 'administrator' | 'manager' | 'editor';
export type UserRole = 'super_admin' | 'administrator' | 'manager' | 'editor';
}
}
namespace Jobs {
namespace Data {
export type CreateJobPostingData = {
title: string,
description: string,
schedule: string,
contact_info: string,
organization_id: number,
branch_id: number,
salary_min_cents: number | null,
salary_max_cents: number | null,
salary_display: string | null,
};
export type ToggleJobStatusData = {
status: App.Domain.Jobs.Enums.JobStatus,
};
}
namespace Enums {
export type JobStatus = 'draft' | 'active' | 'paused' | 'closed';
}
}
namespace Membership {
namespace Data {
export type RegisterMemberData = {
first_name: string,
last_name_paternal: string,
last_name_maternal: string,
curp: string,
rfc: string,
date_of_birth: undefined,
municipality_id: number,
address: string,
postal_code: string,
neighborhood: string,
mobile: string,
phone: string | null,
organization_id: number | null,
};
}
namespace Enums {
export type MemberStatus = 'pending' | 'approved' | 'rejected';
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
