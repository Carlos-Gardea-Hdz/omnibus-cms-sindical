declare namespace App {
namespace Domain {
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
